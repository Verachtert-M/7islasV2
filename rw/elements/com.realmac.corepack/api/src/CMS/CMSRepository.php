<?php

namespace App\CMS;

use Illuminate\Support\Collection;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;

/**
 * Streamlined CMS Repository for managing markdown content collections
 */
class CMSRepository
{
    private string $basePath;
    private array $options;
    private MarkdownParser $parser;
    private SearchService $searchService;
    private array $fileIndexCache = [];
    private array $itemCache = [];

    public function __construct(string $basePath, array $options = [])
    {
        $this->basePath = rtrim($basePath, '/');
        $this->options = $options;
        $this->parser = new MarkdownParser($options, $this->basePath);
        $this->searchService = new SearchService();
    }

    public static function make(string $basePath, array $options = []): self
    {
        return new self($basePath, $options);
    }

    /**
     * Get all available collections
     */
    public function getCollections(): array
    {
        $collections = [];

        if (!is_dir($this->basePath)) {
            return $collections;
        }

        // First, check if the basePath itself contains markdown files
        $baseMarkdownFiles = glob($this->basePath . '/*.md');
        if (!empty($baseMarkdownFiles)) {
            // Treat the basePath as a collection itself
            $name = basename($this->basePath);
            $collections[] = [
                'name' => $name,
                'slug' => $name,
                'path' => $name,
                'full_path' => $this->basePath,
                'item_count' => count($baseMarkdownFiles),
                'last_modified' => $this->getLastModified($this->basePath),
                'sample_items' => $this->getSampleItems($this->basePath, 3)
            ];
        }

        // Then, look for subdirectories that contain markdown files
        $directories = glob($this->basePath . '/*', GLOB_ONLYDIR);

        foreach ($directories as $dir) {
            $name = basename($dir);

            // Skip hidden directories
            if (str_starts_with($name, '.')) {
                continue;
            }

            // Count markdown files in this directory
            $markdownFiles = glob($dir . '/*.md');
            $mdCount = count($markdownFiles);

            // Only include directories that contain markdown files
            if ($mdCount > 0) {
                $collections[] = [
                    'name' => $name,
                    'slug' => $name,
                    'path' => $name,
                    'full_path' => $dir,
                    'item_count' => $mdCount,
                    'last_modified' => $this->getLastModified($dir),
                    'sample_items' => $this->getSampleItems($dir, 3)
                ];
            }
        }

        // Sort collections by name
        usort($collections, fn($a, $b) => strcmp($a['name'], $b['name']));

        return $collections;
    }

    /**
     * Get a collection with optional filtering and sorting
     */
    public function getCollection(
        string $collectionPath,
        array $filters = [],
        ?string $orderBy = null,
        string $orderDirection = 'desc',
        ?int $page = null,
        int $perPage = 10,
        int $offset = 0
    ): array {
        $collectionDir = $this->basePath . '/' . trim($collectionPath, '/');

        if (!is_dir($collectionDir)) {
            return ['items' => [], 'meta' => []];
        }

        // Load all items
        $items = $this->loadCollectionItems($collectionDir);

        // Apply filters
        if (!empty($filters)) {
            $items = $this->applyFilters($items, $filters);
        }

        // Apply sorting
        if ($orderBy) {
            $items = $this->sortItems($items, $orderBy, $orderDirection);
        }

        // Handle pagination
        if ($page !== null) {
            return $this->paginateItems($items, $page, $perPage, $offset);
        }

        // Apply offset for non-paginated results
        if ($offset > 0) {
            $items = array_slice($items, $offset);
        }

        return [
            'items' => $items,
            'count' => count($items)
        ];
    }

    /**
     * Get a specific item by slug with improved pattern matching and caching
     */
    public function getItem(string $collectionPath, string $slug, array $options = []): ?Item
    {
        // Validate inputs
        if (!$this->isValidSlug($slug)) {
            return null;
        }

        $collectionDir = $this->basePath . '/' . trim($collectionPath, '/');
        $cacheKey = $collectionPath . '/' . $slug;

        // Check item cache first
        if (isset($this->itemCache[$cacheKey])) {
            return $this->itemCache[$cacheKey];
        }

        // Find the file using optimized approach
        $filepath = $this->findItemFile($collectionDir, $slug, $options);

        if (!$filepath) {
            return null;
        }

        // Parse the item
        $item = $this->parser->parse($filepath);

        // Cache the result if successful
        if ($item) {
            $this->itemCache[$cacheKey] = $item;
        }

        return $item;
    }

    /**
     * Get an individual item from anywhere in the CMS by file path
     */
    public function getIndividualItem(string $filePath, array $options = []): ?Item
    {
        // Validate inputs
        if (empty($filePath)) {
            return null;
        }

        // Normalize the file path
        $normalizedPath = $this->normalizeFilePath($filePath);
        $cacheKey = 'individual_' . md5($normalizedPath);

        // Check item cache first
        if (isset($this->itemCache[$cacheKey])) {
            return $this->itemCache[$cacheKey];
        }

        // Ensure the path is within the base path for security
        $fullPath = $this->basePath . '/' . ltrim($normalizedPath, '/');
        $realBasePath = realpath($this->basePath);
        $realFullPath = realpath($fullPath);

        if (!$realFullPath || !str_starts_with($realFullPath, $realBasePath)) {
            return null; // Path traversal attempt or file not found
        }

        // Check if file exists and is a markdown file
        if (!file_exists($realFullPath) || !str_ends_with($realFullPath, '.md')) {
            return null;
        }

        // Parse the item
        $item = $this->parser->parse($realFullPath);

        // Cache the result if successful
        if ($item) {
            $this->itemCache[$cacheKey] = $item;
        }

        return $item;
    }

    /**
     * Get an individual item by slug from anywhere in the CMS
     */
    public function getIndividualItemBySlug(string $slug, array $options = []): ?Item
    {
        // Validate inputs
        if (!$this->isValidSlug($slug)) {
            return null;
        }

        $cacheKey = 'individual_slug_' . $slug;

        // Check item cache first
        if (isset($this->itemCache[$cacheKey])) {
            return $this->itemCache[$cacheKey];
        }

        // Search for the file recursively in the CMS
        $filepath = $this->findIndividualItemFile($slug, $options);

        if (!$filepath) {
            return null;
        }

        // Parse the item
        $item = $this->parser->parse($filepath);

        // Cache the result if successful
        if ($item) {
            $this->itemCache[$cacheKey] = $item;
        }

        return $item;
    }

    /**
     * Find an individual item file by slug recursively
     */
    private function findIndividualItemFile(string $slug, array $options = []): ?string
    {
        $caseInsensitive = $options['case_insensitive'] ?? false;
        $targetSlug = $caseInsensitive ? strtolower($slug) : $slug;

        // First, check the root directory
        $rootPatterns = [
            $this->basePath . '/' . $slug . '.md',
            $this->basePath . '/' . $slug . '.markdown',
        ];

        // Add date-prefixed patterns for root directory
        $rootPatterns[] = $this->basePath . '/*' . $slug . '.md';
        $rootPatterns[] = $this->basePath . '/*' . $slug . '.markdown';

        foreach ($rootPatterns as $pattern) {
            $matches = glob($pattern);
            if (!empty($matches)) {
                $filepath = $matches[0];

                if ($this->validateSlugMatch($filepath, $slug, $caseInsensitive)) {
                    return $filepath;
                }
            }
        }

        // Then recursively search subdirectories
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->basePath, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && (str_ends_with($file->getFilename(), '.md') || str_ends_with($file->getFilename(), '.markdown'))) {
                $filename = basename($file->getFilename(), '.md');
                $filename = basename($filename, '.markdown');

                // Handle date-prefixed filenames
                if (preg_match('/^(\d{4}-\d{2}-\d{2})-(.+)$/', $filename, $matches)) {
                    $filename = $matches[2];
                }

                // Check if this matches our target slug
                if ($caseInsensitive) {
                    if (strtolower($filename) === $targetSlug) {
                        return $file->getPathname();
                    }
                } else {
                    if ($filename === $slug) {
                        return $file->getPathname();
                    }
                }
            }
        }

        return null;
    }

    /**
     * Validate that a file path matches the expected slug
     */
    private function validateSlugMatch(string $filepath, string $slug, bool $caseInsensitive): bool
    {
        $filename = basename($filepath, '.md');
        $filename = basename($filename, '.markdown');

        // Handle date-prefixed filenames
        if (preg_match('/^(\d{4}-\d{2}-\d{2})-(.+)$/', $filename, $matches)) {
            $filename = $matches[2];
        }

        if ($caseInsensitive) {
            return strtolower($filename) === strtolower($slug);
        }

        return $filename === $slug;
    }

    /**
     * Find individual item file with comprehensive case-insensitive search
     */
    private function findIndividualItemFileCaseInsensitive(string $slug): ?string
    {
        $targetSlug = strtolower($slug);

        // Recursively search all markdown files
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->basePath, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.md')) {
                $filename = basename($file->getFilename(), '.md');

                // Handle date-prefixed filenames
                if (preg_match('/^(\d{4}-\d{2}-\d{2})-(.+)$/', $filename, $matches)) {
                    $filename = $matches[2];
                }

                if (strtolower($filename) === $targetSlug) {
                    return $file->getPathname();
                }
            }
        }

        return null;
    }

    /**
     * Normalize file path for security and consistency
     */
    private function normalizeFilePath(string $filePath): string
    {
        // Remove any leading/trailing slashes and normalize
        $normalized = trim($filePath, '/');

        // Remove any path traversal attempts
        $normalized = str_replace(['../', '..\\'], '', $normalized);

        // Ensure it ends with .md if not already
        if (!str_ends_with($normalized, '.md') && !str_ends_with($normalized, '.markdown')) {
            $normalized .= '.md';
        }

        return $normalized;
    }

    /**
     * Enhanced search across collections or within a specific collection
     */
    public function search(string $query, ?string $collectionName = null, array $options = []): array
    {
        $items = [];
        $collections = $this->getCollections();

        foreach ($collections as $collection) {
            // If a specific collection is requested, only load that one
            // If no collection specified (null), load all collections
            if ($collectionName !== null && $collection['name'] !== $collectionName) {
                continue;
            }

            $collectionItems = $this->loadCollectionItems($collection['full_path']);
            $items = array_merge($items, $collectionItems);
        }

        return $this->searchService->search($query, $items, $options);
    }

    /**
     * Fuzzy search with typo tolerance
     */
    public function fuzzySearch(string $query, ?string $collectionName = null, int $maxDistance = 2): array
    {
        $items = [];
        $collections = $this->getCollections();

        foreach ($collections as $collection) {
            // If a specific collection is requested, only load that one
            // If no collection specified (null), load all collections
            if ($collectionName !== null && $collection['name'] !== $collectionName) {
                continue;
            }

            $collectionItems = $this->loadCollectionItems($collection['full_path']);
            $items = array_merge($items, $collectionItems);
        }

        return $this->searchService->fuzzySearch($query, $items, $maxDistance);
    }

    /**
     * Faceted search with filters
     */
    public function facetedSearch(string $query, array $facets = [], ?string $collectionName = null): array
    {
        $items = [];
        $collections = $this->getCollections();

        foreach ($collections as $collection) {
            // If a specific collection is requested, only load that one
            // If no collection specified (null), load all collections
            if ($collectionName !== null && $collection['name'] !== $collectionName) {
                continue;
            }

            $collectionItems = $this->loadCollectionItems($collection['full_path']);
            $items = array_merge($items, $collectionItems);
        }

        return $this->searchService->facetedSearch($query, $items, $facets);
    }

    /**
     * Get search suggestions for autocomplete
     */
    public function getSearchSuggestions(string $partialQuery, ?string $collectionName = null, int $limit = 5): array
    {
        $items = [];
        $collections = $this->getCollections();

        foreach ($collections as $collection) {
            // If a specific collection is requested, only load that one
            // If no collection specified (null), load all collections
            if ($collectionName !== null && $collection['name'] !== $collectionName) {
                continue;
            }

            $collectionItems = $this->loadCollectionItems($collection['full_path']);
            $items = array_merge($items, $collectionItems);
        }

        return $this->searchService->getSuggestions($partialQuery, $items, $limit);
    }

    /**
     * Get search analytics
     */
    public function getSearchAnalytics(): array
    {
        return $this->searchService->getAnalytics();
    }

    /**
     * Get related items for a specific item (using item instance)
     */
    public function getRelatedItemsForItem(
        Item $item,
        string $collectionPath,
        array $criteria = ['tags'],
        int $limit = 5
    ): array {
        $collectionDir = $this->basePath . '/' . trim($collectionPath, '/');
        $allItems = $this->loadCollectionItems($collectionDir);

        // Remove the current item and filter out draft posts
        $allItems = array_values(array_filter($allItems, function ($i) use ($item) {
            return $i->slug() !== $item->slug() && $i->isPublished();
        }));

        return $this->searchService->findRelated($item, $allItems, $criteria, $limit);
    }

    /**
     * Get related items for a specific item (legacy method - fetches item by slug)
     */
    public function getRelatedItems(
        string $collectionPath,
        string $slug,
        array $criteria = ['tags'],
        int $limit = 5
    ): array {
        $item = $this->getItem($collectionPath, $slug);
        if (!$item) {
            return [];
        }

        return $this->getRelatedItemsForItem($item, $collectionPath, $criteria, $limit);
    }

    /**
     * Generate RSS feed for a collection
     */
    public function generateRssFeed(
        string $collectionPath,
        string $title,
        string $description,
        string $link,
        array $filters = [],
        int $limit = 20
    ): string {
        $result = $this->getCollection($collectionPath, $filters, 'date_published', 'desc', null, $limit);
        $items = $result['items'];
        $baseUrl = $link ? rtrim($link, '/') : '';

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0">' . "\n";
        $xml .= '<channel>' . "\n";
        $xml .= '<title>' . htmlspecialchars($title) . '</title>' . "\n";
        $xml .= '<description>' . htmlspecialchars($description) . '</description>' . "\n";
        $xml .= '<link>' . htmlspecialchars($link) . '</link>' . "\n";
        $xml .= '<lastBuildDate>' . date('r') . '</lastBuildDate>' . "\n";

        foreach ($items as $item) {
            $xml .= '<item>' . "\n";
            $xml .= '<title>' . htmlspecialchars($item->title()) . '</title>' . "\n";
            $xml .= '<description>' . htmlspecialchars($item->excerpt() ?? '') . '</description>' . "\n";
            $xml .= '<pubDate>' . date('r', strtotime($item->date('c'))) . '</pubDate>' . "\n";
            $xml .= '<link>' . htmlspecialchars($item->meta('url', '')) . '</link>' . "\n";
            $xml .= '<guid>' . htmlspecialchars($item->meta('url', '')) . '</guid>' . "\n";
            $xml .= '</item>' . "\n";
        }

        $xml .= '</channel>' . "\n";
        $xml .= '</rss>';

        // Convert relative URLs to absolute in link and guid elements
        if ($baseUrl) {
            $xml = preg_replace_callback(
                '#<(link|guid)>(\.\./|\./)*(.*?)</\1>#',
                function ($matches) use ($baseUrl) {
                    $path = $matches[3];
                    if (stripos($path, 'http') !== 0) {
                        $path = $baseUrl . '/' . ltrim($path, '/');
                    }
                    return '<' . $matches[1] . '>' . $path . '</' . $matches[1] . '>';
                },
                $xml
            );
        }

        return $xml;
    }

    /**
     * Generate sitemap for a collection
     */
    public function generateSitemap(
        string $collectionPath,
        string $baseUrl,
        string $changefreq = 'weekly',
        float $priority = 0.5,
        array $filters = [],
        int $limit = 1000
    ): string {
        $result = $this->getCollection($collectionPath, $filters, 'date_modified', 'desc', null, $limit);
        $items = $result['items'];
        $baseUrl = $baseUrl ? rtrim($baseUrl, '/') : '';

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($items as $item) {
            // Get the item URL, fallback to slug-based URL
            $itemUrl = $item->meta('url', $baseUrl . '/' . $item->slug());

            $xml .= '<url>' . "\n";
            $xml .= '<loc>' . htmlspecialchars($itemUrl) . '</loc>' . "\n";
            $xml .= '<lastmod>' . $item->dateModified('Y-m-d') . '</lastmod>' . "\n";
            $xml .= '<changefreq>' . $changefreq . '</changefreq>' . "\n";
            $xml .= '<priority>' . $priority . '</priority>' . "\n";
            $xml .= '</url>' . "\n";
        }

        $xml .= '</urlset>';

        // Convert relative URLs to absolute in loc elements
        if ($baseUrl) {
            $xml = preg_replace_callback(
                '#<loc>\s*(\.\./|\./)*(.*?)\s*</loc>#s',
                function ($matches) use ($baseUrl) {
                    $path = trim($matches[2]);
                    if (stripos($path, 'http') !== 0) {
                        $path = $baseUrl . '/' . ltrim($path, '/');
                    }
                    return '<loc>' . $path . '</loc>';
                },
                $xml
            );
        }

        return $xml;
    }

    // Private helper methods

    /**
     * Validate slug format and content
     */
    private function isValidSlug(string $slug): bool
    {
        // Check for empty or whitespace-only slugs
        if (empty(trim($slug))) {
            return false;
        }

        // Check length (reasonable limit)
        if (strlen($slug) > 200) {
            return false;
        }

        // Basic format validation (alphanumeric, dashes, underscores)
        // Allow some flexibility for different naming conventions
        if (!preg_match('/^[a-zA-Z0-9\-_\.]+$/', $slug)) {
            return false;
        }

        // Prevent path traversal attempts
        if (str_contains($slug, '..') || str_contains($slug, '/') || str_contains($slug, '\\')) {
            return false;
        }

        return true;
    }

    /**
     * Find item file using optimized pattern matching
     */
    private function findItemFile(string $collectionDir, string $slug, array $options = []): ?string
    {
        if (!is_dir($collectionDir)) {
            return null;
        }

        $caseInsensitive = $options['case_insensitive'] ?? false;
        $fileIndex = $this->getFileIndex($collectionDir);

        // Try exact slug match first
        if (isset($fileIndex[$slug])) {
            return $fileIndex[$slug];
        }

        // Try date-prefixed pattern (YYYY-MM-DD-slug)
        foreach ($fileIndex as $fileSlug => $filepath) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}-(.+)$/', $fileSlug, $matches)) {
                $extractedSlug = $matches[1];
                if ($extractedSlug === $slug || ($caseInsensitive && strcasecmp($extractedSlug, $slug) === 0)) {
                    return $filepath;
                }
            }
        }

        // If case-insensitive matching is enabled, try that
        if ($caseInsensitive) {
            foreach ($fileIndex as $fileSlug => $filepath) {
                if (strcasecmp($fileSlug, $slug) === 0) {
                    return $filepath;
                }
            }
        }

        return null;
    }

    /**
     * Get file index for a collection directory with caching
     */
    private function getFileIndex(string $collectionDir): array
    {
        if (!isset($this->fileIndexCache[$collectionDir])) {
            $this->fileIndexCache[$collectionDir] = $this->buildFileIndex($collectionDir);
        }
        return $this->fileIndexCache[$collectionDir];
    }

    /**
     * Build file index for a collection directory
     */
    private function buildFileIndex(string $collectionDir): array
    {
        $index = [];

        if (!is_dir($collectionDir)) {
            return $index;
        }

        $files = scandir($collectionDir);
        if ($files === false) {
            return $index;
        }

        foreach ($files as $file) {
            // Only process .md files
            if (!str_ends_with($file, '.md')) {
                continue;
            }

            $fullPath = $collectionDir . '/' . $file;

            // Skip if not a regular file
            if (!is_file($fullPath)) {
                continue;
            }

            // Extract slug from filename (matching MarkdownParser logic)
            $filename = basename($file, '.md');
            $slug = $filename;
            
            // Handle date-prefixed filenames consistently with MarkdownParser
            if (preg_match('/^(\d{4}-\d{2}-\d{2})-(.+)$/', $filename, $matches)) {
                $slug = $matches[2];
            }
            
            $index[$slug] = $fullPath;
        }

        return $index;
    }

    private function loadCollectionItems(string $collectionDir): array
    {
        if (!is_dir($collectionDir)) {
            return [];
        }

        $items = [];
        $files = glob($collectionDir . '/*.md');

        foreach ($files as $file) {
            $item = $this->parser->parse($file);
            if ($item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    private function applyFilters(array $items, array $filters): array
    {
        return array_filter($items, function ($item) use ($filters) {
            foreach ($filters as $key => $value) {
                // Handle special date filters
                if ($key === 'date_before') {
                    $itemDate = $item->date('c'); // Get in ISO format for comparison
                    if ($itemDate && $this->compareDates($itemDate, $value) > 0) {
                        return false;
                    }
                    continue;
                }

                if ($key === 'date_after') {
                    $itemDate = $item->date('c'); // Get in ISO format for comparison
                    if ($itemDate && $this->compareDates($itemDate, $value) < 0) {
                        return false;
                    }
                    continue;
                }

                // Handle tags filter (can be comma-separated string or array)
                if ($key === 'tags') {
                    $itemTags = $item->tags() ?? [];
                    $filterTags = is_array($value) ? $value : array_map('trim', explode(',', $value));

                    // Normalize both item tags and filter tags to lowercase for case-insensitive comparison
                    $itemTagsLower = array_map('strtolower', $itemTags);
                    $filterTagsLower = array_map('strtolower', $filterTags);

                    // Check if any of the filter tags match any of the item tags (case-insensitive)
                    $hasMatchingTag = false;
                    foreach ($filterTagsLower as $filterTagLower) {
                        if (in_array($filterTagLower, $itemTagsLower, true)) {
                            $hasMatchingTag = true;
                            break;
                        }
                    }

                    if (!$hasMatchingTag) {
                        return false;
                    }
                    continue;
                }

                // Handle author filter (can be comma-separated string or array)
                if ($key === 'author') {
                    $itemAuthor = $item->author() ?? '';
                    $filterAuthors = is_array($value) ? $value : array_map('trim', explode(',', $value));

                    // Normalize filter authors to lowercase for case-insensitive comparison
                    $filterAuthorsLower = array_map('strtolower', $filterAuthors);

                    // Handle case where author is an array/object with 'name' property
                    if (is_array($itemAuthor) || is_object($itemAuthor)) {
                        $authorName = is_array($itemAuthor) ? ($itemAuthor['name'] ?? '') : ($itemAuthor->name ?? '');
                        $authorNameLower = strtolower($authorName);
                        if (!in_array($authorNameLower, $filterAuthorsLower, true)) {
                            return false;
                        }
                    } else {
                        // Handle case where author is a simple string
                        $itemAuthorLower = strtolower($itemAuthor);
                        if (!in_array($itemAuthorLower, $filterAuthorsLower, true)) {
                            return false;
                        }
                    }
                    continue;
                }

                // Handle status filter
                if ($key === 'status') {
                    $itemStatus = $item->status();
                    if ($itemStatus !== $value) {
                        return false;
                    }
                    continue;
                }

                // Handle featured filter
                if ($key === 'featured') {
                    $itemFeatured = $item->featured();
                    if ((bool)$itemFeatured !== (bool)$value) {
                        return false;
                    }
                    continue;
                }

                // Handle other metadata through meta() method
                $itemValue = $item->meta($key);

                // Handle boolean filters
                if (is_bool($value)) {
                    if ((bool)$itemValue !== $value) {
                        return false;
                    }
                } else {
                    // Handle string/numeric filters
                    if ($itemValue !== $value) {
                        return false;
                    }
                }
            }
            return true;
        });
    }

    /**
     * Compare two dates for filtering
     * Returns: -1 if date1 < date2, 0 if equal, 1 if date1 > date2
     */
    private function compareDates(string $date1, string $date2): int
    {
        $timestamp1 = strtotime($date1);
        $timestamp2 = strtotime($date2);

        if ($timestamp1 === false || $timestamp2 === false) {
            return 0; // If either date is invalid, don't filter
        }

        return $timestamp1 <=> $timestamp2;
    }

    private function sortItems(array $items, string $field, string $direction): array
    {
        usort($items, function ($a, $b) use ($field, $direction) {
            // Map field names to actual method names
            $methodName = match ($field) {
                'date_published' => 'datePublished',
                'date_modified' => 'dateModified',
                'raw_body' => 'rawBody',
                'file_name' => 'fileName',
                'is_published' => 'isPublished',
                'is_draft' => 'isDraft',
                default => $field
            };

            // For date fields, use sortable ISO format instead of display format
            $isDateField = in_array($field, ['date', 'date_published', 'date_modified']);

            if ($isDateField && method_exists($a, $methodName) && method_exists($b, $methodName)) {
                // Use ISO format for date sorting
                $aValue = $a->$methodName('c'); // ISO 8601 format
                $bValue = $b->$methodName('c');
            } else {
                // Try to get value from meta first, then from method, then empty string
                $aValue = $a->meta($field) ?? (method_exists($a, $methodName) ? $a->$methodName() : '');
                $bValue = $b->meta($field) ?? (method_exists($b, $methodName) ? $b->$methodName() : '');
            }

            $result = $aValue <=> $bValue;
            return $direction === 'desc' ? -$result : $result;
        });

        return $items;
    }

    private function paginateItems(array $items, int $page, int $perPage, int $offset = 0): array
    {
        // Apply offset first - skip the first N items
        if ($offset > 0) {
            $items = array_slice($items, $offset);
        }

        $total = count($items);
        $pageOffset = ($page - 1) * $perPage;
        $paginatedItems = array_slice($items, $pageOffset, $perPage);

        return [
            'items' => $paginatedItems,
            'meta' => [
                'currentPage' => $page,
                'perPage' => $perPage,
                'totalItems' => $total,
                'lastPage' => $total > 0 ? (int)ceil($total / $perPage) : 1,
                'offset' => $offset,
            ]
        ];
    }

    private function getLastModified(string $dirPath): ?string
    {
        $files = glob($dirPath . '/*.md');
        $lastModified = 0;

        foreach ($files as $file) {
            $mtime = filemtime($file);
            if ($mtime > $lastModified) {
                $lastModified = $mtime;
            }
        }

        return $lastModified > 0 ? date('c', $lastModified) : null;
    }

    private function getSampleItems(string $dirPath, int $limit = 3): array
    {
        $files = glob($dirPath . '/*.md');
        $samples = [];

        foreach (array_slice($files, 0, $limit) as $file) {
            $filename = basename($file, '.md');
            $samples[] = [
                'slug' => $filename,
                'filename' => basename($file),
                'modified' => date('c', filemtime($file))
            ];
        }

        return $samples;
    }

    /**
     * Clear all caches
     */
    public function clearCache(): void
    {
        $this->fileIndexCache = [];
        $this->itemCache = [];
    }

    /**
     * Clear cache for a specific collection
     */
    public function clearCollectionCache(string $collectionPath): void
    {
        $collectionDir = $this->basePath . '/' . trim($collectionPath, '/');

        // Clear file index cache for this collection
        unset($this->fileIndexCache[$collectionDir]);

        // Clear item cache for items in this collection
        $prefix = $collectionPath . '/';
        foreach (array_keys($this->itemCache) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->itemCache[$key]);
            }
        }
    }

    /**
     * Get cache statistics for debugging
     */
    public function getCacheStats(): array
    {
        return [
            'file_index_entries' => count($this->fileIndexCache),
            'cached_items' => count($this->itemCache),
            'collections_indexed' => array_keys($this->fileIndexCache),
            'memory_usage' => [
                'file_index' => strlen(serialize($this->fileIndexCache)),
                'items' => strlen(serialize($this->itemCache))
            ]
        ];
    }
}
