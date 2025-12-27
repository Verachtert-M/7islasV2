<?php

namespace App\Contracts;

interface CacheInterface
{
    /**
     * Retrieve an item from the cache
     */
    public function get(string $key): mixed;

    /**
     * Store an item in the cache
     */
    public function put(string $key, mixed $value, int $ttl = 3600): bool;

    /**
     * Remove an item from the cache
     */
    public function forget(string $key): bool;

    /**
     * Clear all items from the cache
     */
    public function flush(): bool;

    /**
     * Check if an item exists in the cache
     */
    public function has(string $key): bool;

    /**
     * Store an item in the cache if it doesn't exist
     */
    public function add(string $key, mixed $value, int $ttl = 3600): bool;

    /**
     * Increment a numeric value in the cache
     */
    public function increment(string $key, int $value = 1): int;

    /**
     * Decrement a numeric value in the cache
     */
    public function decrement(string $key, int $value = 1): int;

    /**
     * Store an item in the cache indefinitely
     */
    public function forever(string $key, mixed $value): bool;

    /**
     * Get multiple items from the cache
     */
    public function many(array $keys): array;

    /**
     * Store multiple items in the cache
     */
    public function putMany(array $values, int $ttl = 3600): bool;
}
