<?php

namespace App\Factories;

use App\CMS\Item;
use App\ValueObjects\ItemMetadata;
use App\ValueObjects\ParsedContent;
use App\Contracts\FileSystemInterface;
use App\Parsers\ContentParserManager;
use App\Config\CMSConfig;
use RuntimeException;
use InvalidArgumentException;

/**
 * Factory for creating CMS items from files
 */
class ItemFactory
{
    public function __construct(
        private FileSystemInterface $filesystem,
        private ContentParserManager $parserManager,
        private CMSConfig $config
    ) {}

    /**
     * Create an item from a file path
     */
    public function createFromFile(string $filepath): ?Item
    {
        if (!$this->filesystem->exists($filepath)) {
            return null;
        }

        try {
            $content = $this->filesystem->read($filepath);
            $parsed = $this->parseContent($filepath, $content);
            $metadata = $this->buildMetadata($filepath, $parsed);

            return new Item($metadata, $parsed->body, $parsed->htmlBody);
        } catch (\Exception $e) {
            // Log error in production, for now return null
            return null;
        }
    }

    /**
     * Create an item from content string
     */
    public function createFromContent(string $content, string $filename = 'untitled.md'): Item
    {
        $parsed = $this->parseContent($filename, $content);
        $metadata = $this->buildMetadataFromContent($filename, $parsed);

        return new Item($metadata, $parsed->body, $parsed->htmlBody);
    }

    /**
     * Create multiple items from a directory
     */
    public function createFromDirectory(string $directoryPath): array
    {
        if (!$this->filesystem->isDirectory($directoryPath)) {
            throw new InvalidArgumentException("Directory does not exist: {$directoryPath}");
        }

        $items = [];
        $extensions = $this->parserManager->getSupportedExtensions();

        foreach ($extensions as $extension) {
            $pattern = rtrim($directoryPath, '/') . '/*.' . $extension;
            $files = $this->filesystem->glob($pattern);

            foreach ($files as $file) {
                $item = $this->createFromFile($file);
                if ($item) {
                    $items[] = $item;
                }
            }
        }

        return $items;
    }

    /**
     * Parse content using appropriate parser
     */
    private function parseContent(string $filepath, string $content): ParsedContent
    {
        $extension = pathinfo($filepath, PATHINFO_EXTENSION);

        if ($this->parserManager->supportsExtension($extension)) {
            return $this->parserManager->parseByExtension($extension, $content);
        }

        // Fallback to content-based detection
        if ($this->parserManager->canParse($content)) {
            return $this->parserManager->parse($content);
        }

        throw new RuntimeException("No parser available for file: {$filepath}");
    }

    /**
     * Build metadata from file and parsed content
     */
    private function buildMetadata(string $filepath, ParsedContent $parsed): ItemMetadata
    {
        $filename = basename($filepath, '.' . pathinfo($filepath, PATHINFO_EXTENSION));
        $frontMatter = $parsed->frontMatter;

        // Extract slug and date from filename
        [$slug, $date] = $this->extractSlugAndDate($filename);

        // Process resource paths
        $processedFrontMatter = $this->processResourcePaths($frontMatter);

        // Build metadata array
        $metadataArray = array_merge($processedFrontMatter, [
            'slug' => $slug,
            'date' => $date ?? $frontMatter['date'] ?? date('Y-m-d H:i:s', $this->filesystem->created($filepath)),
            'date_published' => $frontMatter['date_published'] ?? $frontMatter['date'] ?? $date,
            'date_modified' => date('Y-m-d H:i:s', $this->filesystem->lastModified($filepath)),
            'filepath' => $filepath,
            'filename' => $filename,
            'filesize' => $this->filesystem->size($filepath),
        ]);

        // Generate excerpt if not provided
        if (empty($metadataArray['excerpt']) && !empty($parsed->htmlBody)) {
            $metadataArray['excerpt'] = $this->generateExcerpt($parsed->htmlBody);
        }

        return ItemMetadata::fromArray($metadataArray);
    }

    /**
     * Build metadata from content (no file)
     */
    private function buildMetadataFromContent(string $filename, ParsedContent $parsed): ItemMetadata
    {
        $frontMatter = $parsed->frontMatter;

        // Extract slug from filename
        [$slug, $date] = $this->extractSlugAndDate($filename);

        // Process resource paths
        $processedFrontMatter = $this->processResourcePaths($frontMatter);

        // Build metadata array
        $metadataArray = array_merge($processedFrontMatter, [
            'slug' => $slug,
            'date' => $date ?? $frontMatter['date'] ?? date('Y-m-d H:i:s'),
            'date_published' => $frontMatter['date_published'] ?? $frontMatter['date'] ?? $date,
            'date_modified' => date('Y-m-d H:i:s'),
            'filename' => $filename,
        ]);

        // Generate excerpt if not provided
        if (empty($metadataArray['excerpt']) && !empty($parsed->htmlBody)) {
            $metadataArray['excerpt'] = $this->generateExcerpt($parsed->htmlBody);
        }

        return ItemMetadata::fromArray($metadataArray);
    }

    /**
     * Extract slug and date from filename
     */
    private function extractSlugAndDate(string $filename): array
    {
        // Check for date prefix pattern: YYYY-MM-DD-slug
        if (preg_match('/^(\d{4}-\d{2}-\d{2})-(.+)$/', $filename, $matches)) {
            return [$matches[2], $matches[1]];
        }

        // No date prefix, use filename as slug
        return [$filename, null];
    }

    /**
     * Process resource paths according to configuration
     */
    private function processResourcePaths(array $frontMatter): array
    {
        $resourcesConfig = $this->config->getResourcesConfig();
        $resourcePath = $resourcesConfig['path'] ?? '';
        $resourceFields = $resourcesConfig['fields'] ?? [];

        foreach ($resourceFields as $field) {
            if (isset($frontMatter[$field]) && is_string($frontMatter[$field])) {
                // Only process if it doesn't already start with the resource path
                if (!str_starts_with($frontMatter[$field], $resourcePath)) {
                    $frontMatter[$field] = rtrim($resourcePath, '/') . '/' . ltrim($frontMatter[$field], '/');
                }
            }
        }

        return $frontMatter;
    }

    /**
     * Generate excerpt from HTML content
     */
    private function generateExcerpt(string $htmlContent, int $words = 30): string
    {
        // Strip HTML tags
        $plainText = strip_tags($htmlContent);

        // Split into words
        $wordsArray = preg_split('/\s+/', $plainText, -1, PREG_SPLIT_NO_EMPTY);

        if (count($wordsArray) <= $words) {
            return trim($plainText);
        }

        // Take first N words and add ellipsis
        $excerpt = implode(' ', array_slice($wordsArray, 0, $words));
        return trim($excerpt) . '...';
    }

    /**
     * Validate item data
     */
    public function validateItemData(array $data): array
    {
        $errors = [];

        if (empty($data['title'])) {
            $errors[] = 'Title is required';
        }

        if (empty($data['slug'])) {
            $errors[] = 'Slug is required';
        }

        // Validate date format if provided
        if (!empty($data['date'])) {
            try {
                new \DateTimeImmutable($data['date']);
            } catch (\Exception $e) {
                $errors[] = 'Invalid date format';
            }
        }

        return $errors;
    }
}
