<?php

namespace App\Services;

use App\ValueObjects\Tag;

/**
 * Service for managing tag relationships
 * Handles associating tag metadata from Tags folder markdown files
 */
class TagService extends RelationshipService
{
    public function __construct(string $basePath, array $parserOptions = [])
    {
        parent::__construct($basePath, 'tags', $parserOptions);
    }

    /**
     * Create a Tag object from array data
     */
    protected function createFromArray(array $data): Tag
    {
        return Tag::fromArray($data);
    }

    /**
     * Create a default Tag object from just an identifier
     */
    protected function createDefault(string $identifier): Tag
    {
        return Tag::fromString($identifier);
    }

    /**
     * Resolve tags for an item
     * 
     * @param array $tagNames Array of tag names from item frontmatter
     * @param string $contentPath Path to the content item being processed
     * @param array $options Options including URL configuration
     * @return array Array of Tag objects
     */
    public function resolveTags(array $tagNames, string $contentPath, array $options = []): array
    {
        $tags = $this->resolveRelationships($tagNames, $contentPath);

        // Apply URL generation if tag page path is configured and not empty
        if (isset($options['tag_page_path']) && !empty(trim($options['tag_page_path']))) {
            $prettyUrls = $options['pretty_urls'] ?? true;
            $tags = array_map(function ($tag) use ($options, $prettyUrls) {
                return $tag->withUrl($this->generateTagUrl($options['tag_page_path'], $tag->slug, $prettyUrls));
            }, $tags);
        }

        return $tags;
    }

    /**
     * Get all available tags from the tags folder
     */
    public function getAllTags(string $contentPath, array $options = []): array
    {
        $relationshipPath = $this->getRelationshipPath($contentPath);

        if (!is_dir($relationshipPath)) {
            return [];
        }

        $tags = [];
        $files = glob($relationshipPath . '/*.md');

        foreach ($files as $file) {
            $filename = basename($file, '.md');
            $tag = $this->resolveRelationship($filename, $relationshipPath);

            // Apply URL generation if tag page path is configured and not empty
            if (isset($options['tag_page_path']) && !empty(trim($options['tag_page_path']))) {
                $prettyUrls = $options['pretty_urls'] ?? true;
                $tag = $tag->withUrl($this->generateTagUrl($options['tag_page_path'], $tag->slug, $prettyUrls));
            }

            $tags[] = $tag;
        }

        // Sort tags by name
        usort($tags, fn($a, $b) => strcmp($a->name, $b->name));

        return $tags;
    }

    /**
     * Generate URL for a tag based on the tag page path
     */
    private function generateTagUrl(string $tagPagePath, string $tagSlug, bool $prettyUrls = true): string
    {
        if ($prettyUrls) {
            return rtrim($tagPagePath, '/') . '/' . $tagSlug;
        } else {
            $separator = str_contains($tagPagePath, '?') ? '&' : '?';
            return $tagPagePath . $separator . 'tag=' . urlencode($tagSlug);
        }
    }

    /**
     * Get tags by color (if specified in tag metadata)
     */
    public function getTagsByColor(string $contentPath, string $color, array $options = []): array
    {
        $allTags = $this->getAllTags($contentPath, $options);

        return array_filter($allTags, fn($tag) => $tag->color === $color);
    }

    /**
     * Search tags by name or description
     */
    public function searchTags(string $contentPath, string $query, array $options = []): array
    {
        $allTags = $this->getAllTags($contentPath, $options);
        $query = strtolower($query);

        return array_filter($allTags, function ($tag) use ($query) {
            return str_contains(strtolower($tag->name), $query) ||
                str_contains(strtolower($tag->title ?? ''), $query) ||
                str_contains(strtolower($tag->description ?? ''), $query);
        });
    }

    /**
     * Get tag statistics
     */
    public function getTagStats(string $contentPath): array
    {
        $relationshipPath = $this->getRelationshipPath($contentPath);

        if (!is_dir($relationshipPath)) {
            return [
                'total_tags' => 0,
                'has_tag_folder' => false,
                'tag_folder_path' => $relationshipPath,
            ];
        }

        $files = glob($relationshipPath . '/*.md');
        $tagsWithImages = 0;
        $tagsWithColors = 0;
        $tagsWithIcons = 0;

        foreach ($files as $file) {
            $filename = basename($file, '.md');
            $tag = $this->resolveRelationship($filename, $relationshipPath);

            if ($tag->image) $tagsWithImages++;
            if ($tag->color) $tagsWithColors++;
            if ($tag->icon) $tagsWithIcons++;
        }

        return [
            'total_tags' => count($files),
            'has_tag_folder' => true,
            'tag_folder_path' => $relationshipPath,
            'tags_with_images' => $tagsWithImages,
            'tags_with_colors' => $tagsWithColors,
            'tags_with_icons' => $tagsWithIcons,
            'cache_stats' => $this->getCacheStats(),
        ];
    }
}
