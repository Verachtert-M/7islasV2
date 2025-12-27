<?php

namespace App\ValueObjects;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Value object representing a CMS tag with metadata
 */
readonly class Tag
{
    public function __construct(
        public string $name,
        public string $slug,
        public ?string $title = null,
        public ?string $description = null,
        public ?array $image = null,
        public ?string $color = null,
        public ?string $icon = null,
        public ?DateTimeImmutable $date = null,
        public array $custom = [],
        public ?string $url = null
    ) {}

    /**
     * Create from array data (from markdown frontmatter)
     */
    public static function fromArray(array $data): self
    {
        // Validate required fields
        if (empty($data['name'])) {
            throw new InvalidArgumentException('Tag name is required');
        }

        if (empty($data['slug'])) {
            throw new InvalidArgumentException('Tag slug is required');
        }

        // Parse date if provided
        $date = null;
        if (isset($data['date'])) {
            try {
                $date = new DateTimeImmutable($data['date']);
            } catch (\Exception $e) {
                throw new InvalidArgumentException("Invalid date format: {$data['date']}");
            }
        }

        // Handle image data - normalize to array format
        $imageData = null;
        if (isset($data['image'])) {
            if (is_array($data['image'])) {
                $imageData = $data['image'];
            } elseif (is_string($data['image'])) {
                $imageData = ['src' => $data['image'], 'alt' => $data['name'], 'title' => $data['name']];
            }
        }

        // Extract custom fields (everything not in standard fields)
        $standardFields = [
            'name',
            'slug',
            'title',
            'description',
            'image',
            'color',
            'icon',
            'date',
            'url',
            // Exclude CMS metadata fields that shouldn't be in tag custom fields
            'date_published',
            'date_modified',
            'author',
            'authors',
            'featured',
            'status',
            'tags',
            'categories',
            'excerpt',
            'filepath'
        ];
        $custom = array_diff_key($data, array_flip($standardFields));

        return new self(
            name: $data['name'],
            slug: $data['slug'],
            title: $data['title'] ?? $data['name'],
            description: $data['description'] ?? null,
            image: $imageData,
            color: $data['color'] ?? null,
            icon: $data['icon'] ?? null,
            date: $date,
            custom: $custom,
            url: $data['url'] ?? null
        );
    }

    /**
     * Create a basic tag from just a name/slug with defaults
     */
    public static function fromString(string $name): self
    {
        $slug = self::generateSlug($name);

        return new self(
            name: $name,
            slug: $slug,
            title: $name,
            description: null,
            image: null,
            color: null,
            icon: null,
            date: null,
            custom: [],
            url: null
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
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'title' => $this->title,
            'description' => $this->description,
            'image' => $this->image,
            'color' => $this->color,
            'icon' => $this->icon,
            'date' => $this->date?->format('c'),
            'url' => $this->url,
            'custom' => $this->custom,
        ];
    }

    /**
     * Generate a URL-friendly slug from a string
     */
    private static function generateSlug(string $string): string
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
     * Create a new Tag instance with a URL
     */
    public function withUrl(string $url): self
    {
        return new self(
            name: $this->name,
            slug: $this->slug,
            title: $this->title,
            description: $this->description,
            image: $this->image,
            color: $this->color,
            icon: $this->icon,
            date: $this->date,
            custom: $this->custom,
            url: $url
        );
    }

    /**
     * String representation
     */
    public function __toString(): string
    {
        return $this->name;
    }
}
