<?php

namespace App\Repositories;

use App\CMS\Item;
use App\Factories\ItemFactory;
use App\Contracts\FileSystemInterface;
use App\Contracts\CacheInterface;
use App\Config\CMSConfig;

/**
 * Repository for managing CMS items with caching
 */
class ItemRepository
{
    public function __construct(
        private FileSystemInterface $filesystem,
        private ItemFactory $factory,
        private CacheInterface $cache,
        private CMSConfig $config
    ) {}

    /**
     * Find an item by its full path
     */
    public function findByPath(string $path): ?Item
    {
        $cacheKey = $this->getCacheKey('item_path', $path);

        // Try cache first
        if ($cached = $this->cache->get($cacheKey)) {
            return $cached;
        }

        $fullPath = $this->getBasePath() . '/' . ltrim($path, '/');
        $item = $this->factory->createFromFile($fullPath);

        if ($item) {
            $this->cacheItem($cacheKey, $item);
        }

        return $item;
    }

    /**
     * Find an item by slug in a collection
     */
    public function findBySlug(string $collection, string $slug): ?Item
    {
        $cacheKey = $this->getCacheKey('item_slug', $collection . '/' . $slug);

        // Try cache first
        if ($cached = $this->cache->get($cacheKey)) {
            return $cached;
        }

        // Look for files matching the slug pattern
        $pattern = $this->getBasePath() . '/' . $collection . '/*' . $slug . '.{md,markdown}';
        $matches = $this->filesystem->glob($pattern);

        if (empty($matches)) {
            return null;
        }

        $item = $this->factory->createFromFile($matches[0]);

        if ($item) {
            $this->cacheItem($cacheKey, $item);
        }

        return $item;
    }

    /**
     * Find all items in a collection
     */
    public function findByCollection(string $collection): array
    {
        $cacheKey = $this->getCacheKey('collection', $collection);

        // Try cache first
        if ($cached = $this->cache->get($cacheKey)) {
            return $cached;
        }

        $collectionPath = $this->getBasePath() . '/' . $collection;

        if (!$this->filesystem->isDirectory($collectionPath)) {
            return [];
        }

        $items = $this->factory->createFromDirectory($collectionPath);

        // Cache the collection
        $this->cache->put($cacheKey, $items, $this->getCacheTtl());

        return $items;
    }

    /**
     * Find items matching criteria
     */
    public function findBy(array $criteria): array
    {
        // For now, this is a simple implementation
        // In a more advanced version, this could use indexes
        $allItems = $this->findAll();

        return array_filter($allItems, function ($item) use ($criteria) {
            foreach ($criteria as $field => $value) {
                $itemValue = $this->getItemValue($item, $field);

                if (is_array($value)) {
                    if (!in_array($itemValue, $value)) {
                        return false;
                    }
                } else {
                    if ($itemValue !== $value) {
                        return false;
                    }
                }
            }
            return true;
        });
    }

    /**
     * Find all items across all collections
     */
    public function findAll(): array
    {
        $cacheKey = $this->getCacheKey('all_items');

        // Try cache first
        if ($cached = $this->cache->get($cacheKey)) {
            return $cached;
        }

        $allItems = [];
        $collections = $this->getCollections();

        foreach ($collections as $collection) {
            $items = $this->findByCollection($collection);
            $allItems = array_merge($allItems, $items);
        }

        // Cache all items
        $this->cache->put($cacheKey, $allItems, $this->getCacheTtl());

        return $allItems;
    }

    /**
     * Alias for findAll() - get all items
     */
    public function all(): array
    {
        return $this->findAll();
    }

    /**
     * Search items by content
     */
    public function search(string $query, array $collections = []): array
    {
        $items = empty($collections) ? $this->findAll() : [];

        if (!empty($collections)) {
            foreach ($collections as $collection) {
                $collectionItems = $this->findByCollection($collection);
                $items = array_merge($items, $collectionItems);
            }
        }

        $query = strtolower($query);

        return array_filter($items, function ($item) use ($query) {
            // Search in title, content, tags, categories
            $searchableContent = strtolower(
                $item->title() . ' ' .
                    $item->rawBody() . ' ' .
                    implode(' ', $item->tags()) . ' ' .
                    implode(' ', $item->categories())
            );

            return str_contains($searchableContent, $query);
        });
    }

    /**
     * Get collections list
     */
    public function getCollections(): array
    {
        $cacheKey = $this->getCacheKey('collections_list');

        // Try cache first
        if ($cached = $this->cache->get($cacheKey)) {
            return $cached;
        }

        $basePath = $this->getBasePath();
        $collections = [];

        if ($this->filesystem->isDirectory($basePath)) {
            $directories = $this->filesystem->glob($basePath . '/*');

            foreach ($directories as $dir) {
                if ($this->filesystem->isDirectory($dir)) {
                    $name = basename($dir);
                    // Skip hidden directories and cache directories
                    if (!str_starts_with($name, '.')) {
                        $collections[] = $name;
                    }
                }
            }
        }

        // Cache collections list
        $this->cache->put($cacheKey, $collections, $this->getCacheTtl());

        return $collections;
    }

    /**
     * Clear cache for specific item or collection
     */
    public function clearCache(?string $type = null, ?string $identifier = null): void
    {
        if ($type && $identifier) {
            $cacheKey = $this->getCacheKey($type, $identifier);
            $this->cache->forget($cacheKey);
        } else {
            // Clear all CMS cache
            $this->cache->flush();
        }
    }

    /**
     * Get base path for content
     */
    private function getBasePath(): string
    {
        return $this->config->get('cms_base_path');
    }

    /**
     * Get cache TTL
     */
    private function getCacheTtl(): int
    {
        return $this->config->get('cache_ttl', 3600);
    }

    /**
     * Generate cache key
     */
    private function getCacheKey(string $type, string $identifier = ''): string
    {
        $key = 'cms_' . $type;
        if ($identifier) {
            $key .= '_' . md5($identifier);
        }
        return $key;
    }

    /**
     * Cache an item
     */
    private function cacheItem(string $cacheKey, Item $item): void
    {
        $this->cache->put($cacheKey, $item, $this->getCacheTtl());
    }

    /**
     * Get value from item for filtering
     */
    private function getItemValue(Item $item, string $field): mixed
    {
        return match ($field) {
            'slug' => $item->slug(),
            'title' => $item->title(),
            'author' => $item->author(),
            'status' => $item->status(),
            'featured' => $item->featured(),
            'tags' => $item->tags(),
            'categories' => $item->categories(),
            default => $item->meta($field)
        };
    }
}
