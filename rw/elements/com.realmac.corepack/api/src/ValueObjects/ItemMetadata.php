<?php

namespace App\ValueObjects;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Value object representing CMS item metadata
 */
readonly class ItemMetadata
{
    public function __construct(
        public string $slug,
        public string $title,
        public string $date,
        public string $date_published,
        public string $date_modified,
        public string|array|null $author = null,
        public array $authors = [],
        public bool $featured = false,
        public string $status = 'published',
        public array $tags = [],
        public array $categories = [],
        public ?array $image = null,
        public ?string $excerpt = null,
        public array $custom = []
    ) {}

    /**
     * Create from array data
     */
    public static function fromArray(array $data): self
    {
        // Validate required fields
        if (empty($data['slug'])) {
            throw new InvalidArgumentException('Slug is required');
        }

        if (empty($data['title'])) {
            throw new InvalidArgumentException('Title is required');
        }

        // Parse main date field (required) - normalize to ISO 8601 string
        $date = $data['date'] ?? date('c');
        $dateString = self::normalizeToIsoString($date);

        // Parse date_published - fallback to main date
        $datePublished = $data['date_published'] ?? null;
        $datePublishedString = $datePublished ? self::normalizeToIsoString($datePublished) : $dateString;

        // Parse date_modified - fallback to main date
        $dateModified = $data['date_modified'] ?? null;
        $dateModifiedString = $dateModified ? self::normalizeToIsoString($dateModified) : $dateString;

        // Extract custom fields (everything not in standard fields)
        $standardFields = [
            'slug',
            'title',
            'date',
            'date_published',
            'date_modified',
            'author',
            'authors',
            'featured',
            'status',
            'tags',
            'categories',
            'image',
            'excerpt',
            'filepath' // Added by parser
        ];

        // Handle custom fields - if already nested under 'custom' key, use that
        // Otherwise, extract all non-standard fields
        if (isset($data['custom']) && is_array($data['custom'])) {
            $custom = $data['custom'];
        } else {
            $custom = array_diff_key($data, array_flip($standardFields));
        }

        // Handle image data - can be string or array
        $imageData = $data['image'] ?? null;
        if (is_string($imageData)) {
            // Convert string to array format for backwards compatibility
            $imageData = ['src' => $imageData];
        } elseif (!is_array($imageData) && $imageData !== null) {
            // Invalid image data type
            $imageData = null;
        }

        return new self(
            slug: $data['slug'],
            title: $data['title'],
            date: $dateString,
            date_published: $datePublishedString,
            date_modified: $dateModifiedString,
            author: $data['author'] ?? null,
            authors: (array) ($data['authors'] ?? []),
            featured: (bool) ($data['featured'] ?? false),
            status: $data['status'] ?? 'published',
            tags: (array) ($data['tags'] ?? []),
            categories: (array) ($data['categories'] ?? []),
            image: $imageData,
            excerpt: $data['excerpt'] ?? null,
            custom: $custom
        );
    }

    /**
     * Get custom field value
     */
    public function getCustom(string $key, mixed $default = null): mixed
    {
        return $this->custom[$key] ?? $default;
    }

    /**
     * Check if has custom field
     */
    public function hasCustom(string $key): bool
    {
        return array_key_exists($key, $this->custom);
    }

    /**
     * Convert to array
     */
    /**
     * Normalize a date value to ISO 8601 string format
     */
    private static function normalizeToIsoString(mixed $date): string
    {
        if ($date instanceof DateTimeImmutable) {
            return $date->format('c');
        }

        if (is_string($date)) {
            try {
                return (new DateTimeImmutable($date))->format('c');
            } catch (\Exception $e) {
                return (new DateTimeImmutable())->format('c');
            }
        }

        return (new DateTimeImmutable())->format('c');
    }

    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'title' => $this->title,
            'date' => $this->date,
            'date_published' => $this->date_published,
            'date_modified' => $this->date_modified,
            'author' => $this->author,
            'authors' => $this->authors,
            'featured' => $this->featured,
            'status' => $this->status,
            'tags' => $this->tags,
            'categories' => $this->categories,
            'image' => $this->image,
            'excerpt' => $this->excerpt,
            'custom' => $this->custom,
        ];
    }

    /**
     * Format date
     */
    public function formatDate(string $format = 'F j, Y'): string
    {
        if ($this->date instanceof \DateTimeInterface) {
            return $this->date->format($format);
        }

        // Try to create a DateTimeImmutable if $this->date is a string
        if (is_string($this->date)) {
            try {
                return (new \DateTimeImmutable($this->date))->format($format);
            } catch (\Exception $e) {
                // Falls through to fallback below
            }
        }

        // Fallback: Current date/time in given format
        return (new \DateTimeImmutable())->format($format);
    }

    /**
     * Check if published
     */
    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    /**
     * Check if draft
     */
    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }
}
