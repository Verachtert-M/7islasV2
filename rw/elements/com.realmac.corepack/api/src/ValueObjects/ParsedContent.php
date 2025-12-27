<?php

namespace App\ValueObjects;

/**
 * Value object representing parsed content with front matter and body
 */
readonly class ParsedContent
{
    public function __construct(
        public array $frontMatter,
        public string $body,
        public string $htmlBody,
        public array $metadata = []
    ) {}

    /**
     * Get a front matter value
     */
    public function getFrontMatter(string $key, mixed $default = null): mixed
    {
        return $this->frontMatter[$key] ?? $default;
    }

    /**
     * Check if front matter has a key
     */
    public function hasFrontMatter(string $key): bool
    {
        return array_key_exists($key, $this->frontMatter);
    }

    /**
     * Get metadata value
     */
    public function getMetadata(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return [
            'frontMatter' => $this->frontMatter,
            'body' => $this->body,
            'htmlBody' => $this->htmlBody,
            'metadata' => $this->metadata,
        ];
    }
}
