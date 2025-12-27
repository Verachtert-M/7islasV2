<?php

namespace App\Config;

use InvalidArgumentException;

/**
 * CMS Configuration management with validation and defaults
 */
class CMSConfig
{
    private array $config;
    private array $defaults = [
        'cms_base_path' => null,
        'cache_path' => null,
        'cache_ttl' => 3600,
        'resources' => [
            'path' => '/resources',
            'fields' => ['image', 'thumbnail', 'featured_image']
        ],
        'parser' => [
            'markdown' => [
                'allow_unsafe_links' => false,
                'allow_unsafe_images' => false,
                'html_input' => 'strip',
            ]
        ],
        'search' => [
            'enabled' => true,
            'index_content' => true,
            'index_metadata' => true,
        ],
        'collections' => [
            'auto_discover' => true,
            'expect_dates' => true,
        ],
        'pagination' => [
            'default_per_page' => 10,
            'max_per_page' => 100,
        ]
    ];

    public function __construct(array $config = [])
    {
        $this->config = $this->validateAndMergeDefaults($config);
    }

    /**
     * Get a configuration value
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->getNestedValue($this->config, $key, $default);
    }

    /**
     * Check if a configuration key exists
     */
    public function has(string $key): bool
    {
        return $this->getNestedValue($this->config, $key) !== null;
    }

    /**
     * Set a configuration value
     */
    public function set(string $key, mixed $value): void
    {
        $this->setNestedValue($this->config, $key, $value);
    }

    /**
     * Get all configuration
     */
    public function all(): array
    {
        return $this->config;
    }

    /**
     * Validate and merge with defaults
     */
    private function validateAndMergeDefaults(array $config): array
    {
        // Validate required fields
        $this->validateRequired($config);

        // Merge with defaults
        $merged = $this->mergeRecursive($this->defaults, $config);

        // Set dynamic defaults
        $merged = $this->setDynamicDefaults($merged);

        return $merged;
    }

    /**
     * Validate required configuration
     */
    private function validateRequired(array $config): void
    {
        $required = ['cms_base_path'];

        foreach ($required as $key) {
            if (empty($config[$key])) {
                throw new InvalidArgumentException("Configuration key '{$key}' is required");
            }
        }

        // Validate paths exist
        if (!is_dir($config['cms_base_path'])) {
            throw new InvalidArgumentException(
                "CMS base path does not exist: {$config['cms_base_path']}"
            );
        }
    }

    /**
     * Set dynamic defaults based on other config values
     */
    private function setDynamicDefaults(array $config): array
    {
        // Set default cache path if not provided
        if (empty($config['cache_path'])) {
            $config['cache_path'] = $config['cms_base_path'] . '/.cache';
        }

        return $config;
    }

    /**
     * Recursively merge arrays
     */
    private function mergeRecursive(array $array1, array $array2): array
    {
        $merged = $array1;

        foreach ($array2 as $key => $value) {
            if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])) {
                $merged[$key] = $this->mergeRecursive($merged[$key], $value);
            } else {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    /**
     * Get nested array value using dot notation
     */
    private function getNestedValue(array $array, string $key, mixed $default = null): mixed
    {
        if (strpos($key, '.') === false) {
            return $array[$key] ?? $default;
        }

        $keys = explode('.', $key);
        $value = $array;

        foreach ($keys as $k) {
            if (!is_array($value) || !array_key_exists($k, $value)) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    /**
     * Set nested array value using dot notation
     */
    private function setNestedValue(array &$array, string $key, mixed $value): void
    {
        if (strpos($key, '.') === false) {
            $array[$key] = $value;
            return;
        }

        $keys = explode('.', $key);
        $current = &$array;

        foreach ($keys as $k) {
            if (!isset($current[$k]) || !is_array($current[$k])) {
                $current[$k] = [];
            }
            $current = &$current[$k];
        }

        $current = $value;
    }

    /**
     * Get cache configuration
     */
    public function getCacheConfig(): array
    {
        return [
            'path' => $this->get('cache_path'),
            'ttl' => $this->get('cache_ttl'),
        ];
    }

    /**
     * Get parser configuration
     */
    public function getParserConfig(string $parser = 'markdown'): array
    {
        return $this->get("parser.{$parser}", []);
    }

    /**
     * Get resources configuration
     */
    public function getResourcesConfig(): array
    {
        return $this->get('resources', []);
    }

    /**
     * Get search configuration
     */
    public function getSearchConfig(): array
    {
        return $this->get('search', []);
    }

    /**
     * Get collections configuration
     */
    public function getCollectionsConfig(): array
    {
        return $this->get('collections', []);
    }

    /**
     * Get pagination configuration
     */
    public function getPaginationConfig(): array
    {
        return $this->get('pagination', []);
    }
}
