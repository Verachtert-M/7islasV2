<?php

namespace App\CMS;

use Symfony\Component\Yaml\Yaml;
use League\CommonMark\CommonMarkConverter;
use App\ValueObjects\ItemMetadata;
use App\Services\TagService;
use App\Services\AuthorService;

/**
 * Service for parsing markdown files with YAML frontmatter
 */
class MarkdownParser
{
    private CommonMarkConverter $converter;
    private array $options;
    private ?string $basePath = null;
    private ?TagService $tagService = null;
    private ?AuthorService $authorService = null;

    public function __construct(array $options = [], ?string $basePath = null)
    {
        $this->options = $options;
        $this->basePath = $basePath ? rtrim($basePath, '/') : null;
        $this->converter = new CommonMarkConverter([
            'allow_unsafe_links' => false,
            'allow_unsafe_images' => false,
            'html_input' => 'allow',
        ]);
    }

    /**
     * Parse a markdown file and return an Item instance
     */
    public function parse(string $filepath): ?Item
    {
        if (!file_exists($filepath)) {
            return null;
        }

        $content = file_get_contents($filepath);

        // Parse frontmatter and content
        if (!preg_match('/^---(.*?)---(.*)$/s', $content, $matches)) {
            return null;
        }

        $yaml = trim($matches[1]);
        $body = trim($matches[2]);

        try {
            $meta = Yaml::parse($yaml) ?: [];
        } catch (\Exception $e) {
            return null;
        }

        // Process filename for slug and date extraction
        $filename = basename($filepath, '.md');
        $slug = $filename;
        $date = null;

        // Extract date from filename if present (e.g., 2023-01-01-my-post.md)
        if (preg_match('/^(\d{4}-\d{2}-\d{2})-(.+)$/', $filename, $matches)) {
            $date = $matches[1];
            $slug = $matches[2];
        }

        // Process tags with TagService if available (unless enrichment is disabled)
        $tags = $this->normalizeArray($meta['tags'] ?? []);
        $enrichedTags = ($this->options['disable_enrichment'] ?? false) ? $tags : $this->enrichTags($tags, $filepath);

        // Process authors with AuthorService if available (unless enrichment is disabled)
        $authors = $this->normalizeArray($meta['authors'] ?? (isset($meta['author']) ? [$meta['author']] : []));
        $enrichedAuthors = ($this->options['disable_enrichment'] ?? false) ? $authors : $this->enrichAuthors($authors, $filepath);

        // Set the author field to the first enriched author's data (as array), or original value if not enriched
        $authorField = $meta['author'] ?? null;
        if (!empty($enrichedAuthors) && is_array($enrichedAuthors[0])) {
            $authorField = $enrichedAuthors[0]; // Full author data array
        }

        // Parse main date (required)
        $mainDate = $this->normalizeDate($meta['date'] ?? $date, $filepath);

        // Parse date_published (defaults to main date if not specified)
        $datePublished = isset($meta['date_published'])
            ? $this->normalizeDate($meta['date_published'], $filepath)
            : $mainDate;  // Fallback to main date

        // Parse date_modified (defaults to file modification time if not specified)
        if (isset($meta['date_modified'])) {
            $dateModified = $this->normalizeDate($meta['date_modified'], $filepath);
        } elseif (file_exists($filepath)) {
            // Use file modification time as fallback
            $dateModified = date('Y-m-d H:i:s', filemtime($filepath));
        } else {
            // Last resort: use main date
            $dateModified = $mainDate;
        }

        // Build metadata with defaults
        try {
            $metadata = ItemMetadata::fromArray([
                'slug' => $slug,
                'title' => $meta['title'] ?? ucfirst(str_replace('-', ' ', $slug)),
                'date' => $mainDate,
                'date_published' => $datePublished,
                'date_modified' => $dateModified,
                'author' => $authorField, // Use enriched author name if available
                'authors' => $enrichedAuthors, // Store enriched authors in metadata
                'featured' => (bool)($meta['featured'] ?? false),
                'status' => $meta['status'] ?? 'published',
                'tags' => $enrichedTags, // Already storing enriched tags in metadata
                'categories' => $this->normalizeArray($meta['categories'] ?? []),
                'image' => $meta['image'] ?? null,
                'excerpt' => $meta['excerpt'] ?? $this->generateExcerpt($body),
                'filepath' => $this->makeRelativeFilepath($filepath), // Store relative filepath
            ] + $this->extractCustomFields($meta));
        } catch (\Exception $e) {
            error_log("MarkdownParser: Failed to create metadata for {$filepath}: " . $e->getMessage());
            return null;
        }

        // Apply resource path prefixing to markdown content before conversion
        $body = $this->applyResourcePrefixing($body, $meta);

        // Convert markdown to HTML
        $htmlBody = $this->converter->convert($body)->getContent();

        // Apply resource path prefixing to metadata
        try {
            $metadata = $this->applyMetadataResourcePrefixing($metadata);
        } catch (\Exception $e) {
            error_log("MarkdownParser: Failed to apply resource prefixing for {$filepath}: " . $e->getMessage());
            // Continue with original metadata if resource prefixing fails
        }

        // Create item
        $item = new Item($metadata, $body, $htmlBody);

        return $item;
    }

    /**
     * Normalize array fields (tags, categories, etc.)
     */
    private function normalizeArray($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value)) {
            return array_map('trim', explode(',', $value));
        }
        return [];
    }

    /**
     * Normalize date field to ensure it's a valid date string
     * Date field is completely optional - provides automatic fallbacks when missing or invalid
     */
    private function normalizeDate($value, string $filepath = ''): string
    {
        // Handle missing/null/empty dates immediately - these are completely acceptable
        if ($value === null || $value === '' || (is_string($value) && trim($value) === '')) {
            return $this->generateFallbackDate($filepath);
        }

        // Handle different input types
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_int($value)) {
            // Handle YAML parsing of dates like 2024-01-12 as integers
            $dateStr = (string)$value;

            // Check if it's a Unix timestamp (10 digits)
            if (strlen($dateStr) === 10 && preg_match('/^\d{10}$/', $dateStr)) {
                return date('Y-m-d H:i:s', $value);
            }

            // Check if it's YYYYMMDD format (8 digits)
            if (strlen($dateStr) === 8 && preg_match('/^\d{8}$/', $dateStr)) {
                // Convert YYYYMMDD to YYYY-MM-DD
                return substr($dateStr, 0, 4) . '-' . substr($dateStr, 4, 2) . '-' . substr($dateStr, 6, 2);
            }

            $value = $dateStr; // Convert to string for further processing
        }

        // Convert to string if not already
        $dateString = (string)$value;

        // Validate the date string by trying to parse it
        try {
            // Try to create a DateTimeImmutable to validate the format
            new \DateTimeImmutable($dateString);
            return $dateString; // Valid date, return as-is
        } catch (\Exception $e) {
            // Invalid date format, use fallback
            return $this->generateFallbackDate($filepath);
        }
    }

    /**
     * Generate a fallback date when no valid date is available
     * Uses file modification time or current time as fallback
     */
    private function generateFallbackDate(string $filepath): string
    {
        // Use file modification time if available
        if ($filepath && file_exists($filepath)) {
            return date('Y-m-d H:i:s', filemtime($filepath));
        }

        // Last resort: current time
        return date('Y-m-d H:i:s');
    }

    /**
     * Generate excerpt from body content
     */
    private function generateExcerpt(string $body, int $words = 30): string
    {
        // Strip markdown and get plain text
        $text = strip_tags($this->converter->convert($body)->getContent());

        // Split into words and take the first N words
        $words_array = explode(' ', $text);
        if (count($words_array) <= $words) {
            return $text;
        }

        return implode(' ', array_slice($words_array, 0, $words)) . '...';
    }

    /**
     * Extract custom fields (fields not handled by standard metadata)
     */
    private function extractCustomFields(array $meta): array
    {
        $standardFields = [
            'title',
            'date',
            'date_published',
            'date_modified',
            'author',
            'authors', // Exclude the authors array from custom fields
            'featured',
            'status',
            'tags',
            'categories',
            'image',
            'excerpt'
        ];

        $custom = [];
        foreach ($meta as $key => $value) {
            if (!in_array($key, $standardFields)) {
                $custom[$key] = $value;
            }
        }

        return $custom;
    }

    /**
     * Enrich tags with metadata from Tags folder if available
     */
    private function enrichTags(array $tags, string $filepath): array
    {
        if (empty($tags)) {
            return [];
        }

        // Initialize TagService if not already done
        if ($this->tagService === null) {
            $basePath = $this->getBasePath($filepath);
            $this->tagService = new TagService($basePath, $this->options);
        }

        // Check if we have a Tags folder
        if (!$this->tagService->hasRelationshipFolder($filepath)) {
            // No Tags folder, return simple string tags
            return $tags;
        }

        // Extract URL options from parser options
        $tagOptions = [];
        if (isset($this->options['tag_page_path'])) {
            $tagOptions['tag_page_path'] = $this->options['tag_page_path'];
            $tagOptions['pretty_urls'] = $this->options['pretty_urls'] ?? true;
        }

        // Resolve tags with metadata
        $enrichedTags = $this->tagService->resolveTags($tags, $filepath, $tagOptions);

        // Convert Tag objects to arrays for ItemMetadata
        return array_map(function ($tag) {
            if (is_object($tag) && method_exists($tag, 'toArray')) {
                return $tag->toArray();
            }
            return $tag;
        }, $enrichedTags);
    }

    /**
     * Enrich authors with metadata from Authors folder if available
     */
    private function enrichAuthors(array $authors, string $filepath): array
    {
        if (empty($authors)) {
            return [];
        }

        // Initialize AuthorService if not already done
        if ($this->authorService === null) {
            $basePath = $this->getBasePath($filepath);
            $this->authorService = new AuthorService($basePath, $this->options);
        }

        // Check if we have an Authors folder
        if (!$this->authorService->hasRelationshipFolder($filepath)) {
            // No Authors folder, return simple string authors
            return $authors;
        }

        // Extract URL options from parser options
        $authorOptions = [];
        if (isset($this->options['author_page_path'])) {
            $authorOptions['author_page_path'] = $this->options['author_page_path'];
            $authorOptions['pretty_urls'] = $this->options['pretty_urls'] ?? true;
        }

        // Resolve authors with metadata
        try {
            $enrichedAuthors = $this->authorService->resolveAuthors($authors, $filepath, $authorOptions);
            
            // Convert Author objects to arrays for ItemMetadata
            return array_map(function ($author) {
                if (is_object($author) && method_exists($author, 'toArray')) {
                    return $author->toArray();
                }
                return $author;
            }, $enrichedAuthors);
        } catch (\Exception $e) {
            return $authors;
        }
    }

    /**
     * Get the base path for content from a file path
     */
    private function getBasePath(string $filepath): string
    {
        // Get the directory containing the file
        $dir = dirname($filepath);

        // Go up one level to get the parent directory (content root)
        return dirname($dir);
    }

    /**
     * Convert absolute filepath to relative path based on basePath
     */
    private function makeRelativeFilepath(string $filepath): string
    {
        if ($this->basePath === null) {
            return $filepath;
        }

        // Try to use realpath first for existing files
        $absolutePath = realpath($filepath);
        $basePathReal = realpath($this->basePath);

        // If realpath works for both, use it
        if ($absolutePath !== false && $basePathReal !== false) {
            // Check if the file is within the base path
            if (!str_starts_with($absolutePath, $basePathReal)) {
                return $filepath;
            }
            // Convert to relative path
            $relativePath = substr($absolutePath, strlen($basePathReal) + 1);
            return $relativePath;
        }

        // Fallback: normalize paths manually for non-existent files
        $normalizedFilepath = $this->normalizePath($filepath);
        $normalizedBasePath = $this->normalizePath($this->basePath);

        // Check if the file path starts with the base path
        if (!str_starts_with($normalizedFilepath, $normalizedBasePath)) {
            return $filepath;
        }

        // Convert to relative path
        $relativePath = substr($normalizedFilepath, strlen($normalizedBasePath) + 1);
        return $relativePath;
    }

    /**
     * Normalize a file path by resolving .. and . components
     */
    private function normalizePath(string $path): string
    {
        // Convert backslashes to forward slashes
        $path = str_replace('\\', '/', $path);

        // Remove duplicate slashes
        $path = preg_replace('#/+#', '/', $path);

        // Split path into parts
        $parts = explode('/', $path);
        $normalizedParts = [];

        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($normalizedParts);
            } else {
                $normalizedParts[] = $part;
            }
        }

        // Rebuild path
        $normalized = implode('/', $normalizedParts);

        // Preserve leading slash for absolute paths
        if (str_starts_with($path, '/')) {
            $normalized = '/' . $normalized;
        }

        return $normalized;
    }

    /**
     * Apply resource path prefixing if configured
     */
    private function applyResourcePrefixing(string $markdownBody, array $meta): string
    {
        $resources = $this->options['resources'] ?? [];
        $sharedResourcesPath = $resources['path'] ?? '';

        if (empty($sharedResourcesPath)) {
            return $markdownBody;
        }

        // Find markdown images in the markdown body that are not remote URLs
        // Matches: ![alt text](image_url) or ![alt text](image_url "title")
        preg_match_all('/!\[([^\]]*)\]\(([^)"\s]+)(?:\s+"[^"]*")?\)/', $markdownBody, $matches);

        if (!empty($matches[2])) {
            foreach ($matches[2] as $index => $imageUrl) {
                // Skip if it's a remote URL or data URI
                if (!preg_match('/^(https?:\/\/|data:)/i', $imageUrl)) {
                    $prefixedPath = rtrim($sharedResourcesPath, '/') . '/' . ltrim($imageUrl, '/');
                    // Replace the entire markdown image syntax
                    $originalMarkdown = $matches[0][$index];
                    $newMarkdown = str_replace($imageUrl, $prefixedPath, $originalMarkdown);
                    $markdownBody = str_replace($originalMarkdown, $newMarkdown, $markdownBody);
                }
            }
        }

        return $markdownBody;
    }

    /**
     * Apply resource path prefixing to metadata with deeply nested 'src' keys
     * Recursively searches for arrays containing 'src' keys,
     * and prefixes 'src' values that are not remote URLs (http/https/data:)
     */
    private function applyMetadataResourcePrefixing(\App\ValueObjects\ItemMetadata $metadata): \App\ValueObjects\ItemMetadata
    {
        $resources = $this->options['resources'] ?? [];
        $resourcePath = $resources['path'] ?? '';

        // Early return if no resource path configured
        if (empty($resourcePath)) {
            return $metadata;
        }

        // Convert to array, process, then convert back
        $metadataArray = $metadata->toArray();
        $processedArray = $this->processMetadataRecursively($metadataArray, $resourcePath);

        return \App\ValueObjects\ItemMetadata::fromArray($processedArray);
    }

    /**
     * Recursively process metadata array to find and prefix resource src values
     */
    private function processMetadataRecursively(array $data, string $resourcePath): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                // Process any src field in this array first
                if (isset($value['src']) && is_string($value['src']) && !$this->isAbsoluteUrl($value['src'])) {
                    $data[$key]['src'] = rtrim($resourcePath, '/') . '/' . ltrim($value['src'], '/');
                }
                // Then recurse into the (possibly modified) array to find nested src keys
                $data[$key] = $this->processMetadataRecursively($data[$key], $resourcePath);
            }
        }
        return $data;
    }

    /**
     * Check if a URL is absolute (starts with http/https or is a data URL)
     */
    private function isAbsoluteUrl(string $url): bool
    {
        return preg_match('/^(https?:\/\/|data:)/i', $url) === 1;
    }
}
