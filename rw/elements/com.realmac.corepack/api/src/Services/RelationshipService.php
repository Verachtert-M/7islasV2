<?php

namespace App\Services;

use App\CMS\MarkdownParser;

/**
 * Generic service for managing relationships between content items
 * Handles associating metadata from related markdown files
 */
abstract class RelationshipService
{
    protected string $basePath;
    protected string $relationshipFolder;
    protected array $cache = [];
    protected array $fileCache = [];
    protected MarkdownParser $parser;

    public function __construct(string $basePath, string $relationshipFolder, array $parserOptions = [])
    {
        $this->basePath = rtrim($basePath, '/');
        $this->relationshipFolder = $relationshipFolder;
        
        // Disable author/tag enrichment when parsing relationship files to prevent recursion
        // This parser is only used to load author/tag markdown files, which shouldn't have their own authors/tags enriched
        $parserOptions['disable_enrichment'] = true;
        
        $this->parser = new MarkdownParser($parserOptions, $this->basePath);
    }

    /**
     * Resolve relationships for a list of identifiers
     * 
     * @param array $identifiers Array of string identifiers (e.g., tag names)
     * @param string $contentPath Path to the content being processed
     * @return array Array of relationship objects
     */
    public function resolveRelationships(array $identifiers, string $contentPath): array
    {
        if (empty($identifiers)) {
            return [];
        }

        $relationships = [];
        $relationshipPath = $this->getRelationshipPath($contentPath);

        foreach ($identifiers as $identifier) {
            if (is_string($identifier)) {
                $identifier = trim($identifier);
                if (!empty($identifier)) {
                    $relationships[] = $this->resolveRelationship($identifier, $relationshipPath);
                }
            }
        }

        return $relationships;
    }

    /**
     * Resolve a single relationship
     */
    protected function resolveRelationship(string $identifier, string $relationshipPath): object
    {
        $cacheKey = $relationshipPath . '/' . $identifier;

        // Check cache first
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        // Try to find and load the relationship file
        $relationshipFile = $this->findRelationshipFile($identifier, $relationshipPath);

        if ($relationshipFile && file_exists($relationshipFile)) {
            $relationship = $this->createFromFile($relationshipFile, $identifier);
        } else {
            $relationship = $this->createDefault($identifier);
        }

        // Cache the result
        $this->cache[$cacheKey] = $relationship;

        return $relationship;
    }

    /**
     * Find the relationship file for a given identifier
     * Uses case-insensitive search to ensure correct filename case is preserved
     */
    protected function findRelationshipFile(string $identifier, string $relationshipPath): ?string
    {
        if (!is_dir($relationshipPath)) {
            return null;
        }

        // Use case-insensitive search first to get the actual filename with correct case
        // This prevents issues on case-insensitive filesystems where constructed paths
        // might return wrong case when using basename()
        $result = $this->findRelationshipFileCaseInsensitive($identifier, $relationshipPath);
        if ($result) {
            return $result;
        }

        // Fallback to exact matches (though this should rarely be needed now)
        $possibleFiles = [
            $relationshipPath . '/' . $identifier . '.md',
            $relationshipPath . '/' . strtolower($identifier) . '.md',
            $relationshipPath . '/' . $this->generateSlug($identifier) . '.md',
        ];

        foreach ($possibleFiles as $file) {
            if (file_exists($file)) {
                // Verify this is the actual case by checking if basename matches
                $actualFiles = glob($relationshipPath . '/*.md');
                foreach ($actualFiles as $actualFile) {
                    if (realpath($file) === realpath($actualFile)) {
                        return $actualFile; // Return the actual file path with correct case
                    }
                }
                return $file; // Fallback if realpath comparison fails
            }
        }

        return null;
    }

    /**
     * Case-insensitive file search
     */
    protected function findRelationshipFileCaseInsensitive(string $identifier, string $relationshipPath): ?string
    {
        $files = glob($relationshipPath . '/*.md');
        $targetSlug = strtolower($this->generateSlug($identifier));
        $targetName = strtolower($identifier);

        foreach ($files as $file) {
            $filename = strtolower(basename($file, '.md'));
            if ($filename === $targetName || $filename === $targetSlug) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Create relationship object from markdown file
     */
    protected function createFromFile(string $filepath, string $identifier): object
    {
        $item = $this->parser->parse($filepath);

        if (!$item) {
            return $this->createDefault($identifier);
        }

        $metadata = $item->getMetadata();
        $data = $metadata->toArray();

        // Flatten custom fields to avoid nested custom['custom'] structure
        // When ItemMetadata->toArray() is called, custom fields are nested under 'custom' key
        // We want to merge these custom fields directly into the main data array
        if (isset($data['custom']) && is_array($data['custom'])) {
            $customFields = $data['custom'];

            // Handle double nesting: if custom fields contain another 'custom' key, use that
            if (isset($customFields['custom']) && is_array($customFields['custom'])) {
                $customFields = $customFields['custom'];
            }

            unset($data['custom']);
            $data = array_merge($data, $customFields);
        }

        // Use the name from frontmatter if available, otherwise fall back to identifier
        if (empty($data['name'])) {
            $data['name'] = $identifier;
        }

        // Use the filename as slug if not provided
        if (empty($data['slug'])) {
            $data['slug'] = basename($filepath, '.md');
        }

        return $this->createFromArray($data);
    }

    /**
     * Get the relationship path for a given content path
     */
    protected function getRelationshipPath(string $contentPath): string
    {
        // Find the parent directory of the content path
        $parentDir = dirname($contentPath);

        // If content path is a file, go up one more level
        if (is_file($contentPath)) {
            $parentDir = dirname($parentDir);
        }

        return $parentDir . '/' . $this->relationshipFolder;
    }

    /**
     * Generate a URL-friendly slug from a string
     */
    protected function generateSlug(string $string): string
    {
        // Convert to lowercase
        $slug = strtolower($string);

        // Replace spaces and special characters with hyphens
        $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);
        $slug = preg_replace('/[\s-]+/', '-', $slug);

        // Remove leading/trailing hyphens
        return trim($slug, '-');
    }

    /**
     * Clear cache for a specific relationship path
     */
    public function clearCache(?string $contentPath = null): void
    {
        if ($contentPath) {
            $relationshipPath = $this->getRelationshipPath($contentPath);
            $prefix = $relationshipPath . '/';

            foreach (array_keys($this->cache) as $key) {
                if (str_starts_with($key, $prefix)) {
                    unset($this->cache[$key]);
                }
            }
        } else {
            $this->cache = [];
        }

        $this->fileCache = [];
    }

    /**
     * Get cache statistics
     */
    public function getCacheStats(): array
    {
        return [
            'relationship_type' => static::class,
            'cached_items' => count($this->cache),
            'file_cache_items' => count($this->fileCache),
            'memory_usage' => memory_get_usage(),
        ];
    }

    /**
     * Check if relationship folder exists for a content path
     */
    public function hasRelationshipFolder(string $contentPath): bool
    {
        $relationshipPath = $this->getRelationshipPath($contentPath);
        return is_dir($relationshipPath);
    }

    /**
     * Abstract methods that must be implemented by subclasses
     */
    abstract protected function createFromArray(array $data): object;
    abstract protected function createDefault(string $identifier): object;
}
