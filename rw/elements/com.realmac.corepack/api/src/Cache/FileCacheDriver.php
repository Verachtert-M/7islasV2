<?php

namespace App\Cache;

use App\Contracts\CacheInterface;
use App\Contracts\FileSystemInterface;
use RuntimeException;

/**
 * File-based cache driver
 */
class FileCacheDriver implements CacheInterface
{
    private string $cachePath;
    private FileSystemInterface $filesystem;

    public function __construct(string $cachePath, FileSystemInterface $filesystem)
    {
        $this->cachePath = rtrim($cachePath, '/');
        $this->filesystem = $filesystem;

        // Ensure cache directory exists
        if (!$this->filesystem->isDirectory($this->cachePath)) {
            $this->filesystem->makeDirectory($this->cachePath, 0755, true);
        }
    }

    public function get(string $key): mixed
    {
        $path = $this->getPath($key);

        if (!$this->filesystem->exists($path)) {
            return null;
        }

        $content = $this->filesystem->read($path);
        $data = unserialize($content);

        // Check if expired
        if ($data['expires'] !== null && time() > $data['expires']) {
            $this->forget($key);
            return null;
        }

        return $data['value'];
    }

    public function put(string $key, mixed $value, int $ttl = 3600): bool
    {
        $path = $this->getPath($key);
        $expires = $ttl > 0 ? time() + $ttl : null;

        $data = [
            'value' => $value,
            'expires' => $expires,
            'created' => time(),
        ];

        try {
            return $this->filesystem->write($path, serialize($data));
        } catch (RuntimeException $e) {
            return false;
        }
    }

    public function forget(string $key): bool
    {
        $path = $this->getPath($key);

        if (!$this->filesystem->exists($path)) {
            return true;
        }

        try {
            return $this->filesystem->delete($path);
        } catch (RuntimeException $e) {
            return false;
        }
    }

    public function flush(): bool
    {
        $pattern = $this->cachePath . '/*';
        $files = $this->filesystem->glob($pattern);

        foreach ($files as $file) {
            if (!$this->filesystem->isDirectory($file)) {
                try {
                    $this->filesystem->delete($file);
                } catch (RuntimeException $e) {
                    return false;
                }
            }
        }

        return true;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function add(string $key, mixed $value, int $ttl = 3600): bool
    {
        if ($this->has($key)) {
            return false;
        }

        return $this->put($key, $value, $ttl);
    }

    public function increment(string $key, int $value = 1): int
    {
        $current = $this->get($key) ?? 0;
        $new = (int) $current + $value;
        $this->put($key, $new);

        return $new;
    }

    public function decrement(string $key, int $value = 1): int
    {
        return $this->increment($key, -$value);
    }

    public function forever(string $key, mixed $value): bool
    {
        return $this->put($key, $value, 0);
    }

    public function many(array $keys): array
    {
        $result = [];

        foreach ($keys as $key) {
            $result[$key] = $this->get($key);
        }

        return $result;
    }

    public function putMany(array $values, int $ttl = 3600): bool
    {
        foreach ($values as $key => $value) {
            if (!$this->put($key, $value, $ttl)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the full path for a cache key
     */
    private function getPath(string $key): string
    {
        $hash = md5($key);
        return $this->cachePath . '/' . $hash . '.cache';
    }
}
