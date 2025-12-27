<?php

namespace App\ValueObjects;

/**
 * Author Value Object
 * Represents an author with metadata from authors folder markdown files
 */
class Author
{
    public function __construct(
        public readonly string $name,
        public readonly string $slug,
        public readonly ?string $title = null,
        public readonly ?string $bio = null,
        public readonly ?array $image = null,
        public readonly ?string $email = null,
        public readonly ?array $social = null,
        public readonly ?string $website = null,
        public readonly ?string $date = null,
        public readonly ?string $url = null,
        public readonly array $custom = []
    ) {}

    /**
     * Create Author from array data (from markdown frontmatter)
     */
    public static function fromArray(array $data): self
    {
        // Prioritize the name from custom fields (from markdown frontmatter) over the identifier
        $name = $data['name'] ?? 'Unknown Author';

        // Generate slug from name if not provided
        $slug = $data['slug'] ?? self::generateSlug($name);

        return new self(
            name: $name,
            slug: $slug,
            title: $data['title'] ?? null,
            bio: $data['bio'] ?? $data['description'] ?? null,
            image: self::normalizeImage($data['image'] ?? null),
            email: $data['email'] ?? null,
            social: $data['social'] ?? null,
            website: $data['website'] ?? $data['url'] ?? null,
            date: $data['date'] ?? null,
            url: $data['url'] ?? null,
            custom: self::extractCustomFields($data)
        );
    }

    /**
     * Create Author from just a string (simple author name)
     */
    public static function fromString(string $name): self
    {
        return new self(
            name: $name,
            slug: self::generateSlug($name)
        );
    }

    /**
     * Create a new Author instance with a URL
     */
    public function withUrl(string $url): self
    {
        return new self(
            name: $this->name,
            slug: $this->slug,
            title: $this->title,
            bio: $this->bio,
            image: $this->image,
            email: $this->email,
            social: $this->social,
            website: $this->website,
            date: $this->date,
            url: $url,
            custom: $this->custom
        );
    }

    /**
     * Convert to array for template usage
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'title' => $this->title,
            'bio' => $this->bio,
            'image' => $this->image,
            'email' => $this->email,
            'social' => $this->social,
            'website' => $this->website,
            'date' => $this->date,
            'url' => $this->url,
            'custom' => $this->custom,
        ];
    }

    /**
     * Generate slug from name
     */
    private static function generateSlug(string $name): string
    {
        $slug = strtolower($name);
        $slug = preg_replace('/[^a-z0-9\-_]/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        return trim($slug, '-');
    }

    /**
     * Normalize image data to consistent format
     */
    private static function normalizeImage($image): ?array
    {
        if (empty($image)) {
            return null;
        }

        if (is_string($image)) {
            return [
                'src' => $image,
                'alt' => null,
                'title' => null
            ];
        }

        if (is_array($image)) {
            return [
                'src' => $image['src'] ?? $image['url'] ?? null,
                'alt' => $image['alt'] ?? null,
                'title' => $image['title'] ?? null
            ];
        }

        return null;
    }

    /**
     * Extract custom fields (fields not handled by standard metadata)
     */
    private static function extractCustomFields(array $data): array
    {
        $standardFields = [
            'name',
            'slug',
            'title',
            'bio',
            'description',
            'image',
            'email',
            'social',
            'website',
            'url',
            'date',
            // Exclude CMS metadata fields that shouldn't be in author custom fields
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

        $custom = [];
        foreach ($data as $key => $value) {
            if (!in_array($key, $standardFields)) {
                $custom[$key] = $value;
            }
        }

        return $custom;
    }
}
