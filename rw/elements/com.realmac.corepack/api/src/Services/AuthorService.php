<?php

namespace App\Services;

use App\ValueObjects\Author;

/**
 * Service for managing author relationships
 * Handles associating author metadata from Authors folder markdown files
 */
class AuthorService extends RelationshipService
{
    public function __construct(string $basePath, array $parserOptions = [])
    {
        parent::__construct($basePath, 'authors', $parserOptions);
    }

    /**
     * Create an Author object from array data
     */
    protected function createFromArray(array $data): Author
    {
        return Author::fromArray($data);
    }

    /**
     * Create a default Author object from just an identifier
     */
    protected function createDefault(string $identifier): Author
    {
        return Author::fromString($identifier);
    }

    /**
     * Resolve authors for an item
     * 
     * @param array $authorNames Array of author names from item frontmatter
     * @param string $contentPath Path to the content item being processed
     * @param array $options Options including URL configuration
     * @return array Array of Author objects
     */
    public function resolveAuthors(array $authorNames, string $contentPath, array $options = []): array
    {
        $authors = $this->resolveRelationships($authorNames, $contentPath);

        // Apply URL generation if author page path is configured and not empty
        if (isset($options['author_page_path']) && !empty(trim($options['author_page_path']))) {
            $prettyUrls = $options['pretty_urls'] ?? true;
            $authors = array_map(function ($author) use ($options, $prettyUrls) {
                return $author->withUrl($this->generateAuthorUrl($options['author_page_path'], $author->slug, $prettyUrls));
            }, $authors);
        }

        return $authors;
    }

    /**
     * Get all available authors from the authors folder
     */
    public function getAllAuthors(string $contentPath, array $options = []): array
    {
        $relationshipPath = $this->getRelationshipPath($contentPath);

        if (!is_dir($relationshipPath)) {
            return [];
        }

        $authors = [];
        $files = glob($relationshipPath . '/*.md');

        foreach ($files as $file) {
            $filename = basename($file, '.md');
            $author = $this->resolveRelationship($filename, $relationshipPath);

            // Apply URL generation if author page path is configured and not empty
            if (isset($options['author_page_path']) && !empty(trim($options['author_page_path']))) {
                $prettyUrls = $options['pretty_urls'] ?? true;
                $author = $author->withUrl($this->generateAuthorUrl($options['author_page_path'], $author->slug, $prettyUrls));
            }

            $authors[] = $author;
        }

        // Sort authors by name
        usort($authors, fn($a, $b) => strcmp($a->name, $b->name));

        return $authors;
    }

    /**
     * Generate URL for an author based on the author page path
     */
    private function generateAuthorUrl(string $authorPagePath, string $authorSlug, bool $prettyUrls = true): string
    {
        if ($prettyUrls) {
            return rtrim($authorPagePath, '/') . '/' . $authorSlug;
        } else {
            $separator = str_contains($authorPagePath, '?') ? '&' : '?';
            return $authorPagePath . $separator . 'author=' . urlencode($authorSlug);
        }
    }

    /**
     * Search authors by name or bio
     */
    public function searchAuthors(string $contentPath, string $query, array $options = []): array
    {
        $allAuthors = $this->getAllAuthors($contentPath, $options);
        $query = strtolower($query);

        return array_filter($allAuthors, function ($author) use ($query) {
            return str_contains(strtolower($author->name), $query) ||
                str_contains(strtolower($author->title ?? ''), $query) ||
                str_contains(strtolower($author->bio ?? ''), $query);
        });
    }

    /**
     * Get author statistics
     */
    public function getAuthorStats(string $contentPath): array
    {
        $relationshipPath = $this->getRelationshipPath($contentPath);

        if (!is_dir($relationshipPath)) {
            return [
                'total_authors' => 0,
                'has_author_folder' => false,
                'author_folder_path' => $relationshipPath,
            ];
        }

        $files = glob($relationshipPath . '/*.md');
        $authorsWithImages = 0;
        $authorsWithBios = 0;
        $authorsWithSocial = 0;

        foreach ($files as $file) {
            $filename = basename($file, '.md');
            $author = $this->resolveRelationship($filename, $relationshipPath);

            if ($author->image) $authorsWithImages++;
            if ($author->bio) $authorsWithBios++;
            if ($author->social) $authorsWithSocial++;
        }

        return [
            'total_authors' => count($files),
            'has_author_folder' => true,
            'author_folder_path' => $relationshipPath,
            'authors_with_images' => $authorsWithImages,
            'authors_with_bios' => $authorsWithBios,
            'authors_with_social' => $authorsWithSocial,
            'cache_stats' => $this->getCacheStats(),
        ];
    }
}
