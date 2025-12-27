<?php

namespace App\Services;

use App\Contracts\FileSystemInterface;
use RuntimeException;

/**
 * File system service implementation
 */
class FileSystemService implements FileSystemInterface
{
    public function exists(string $path): bool
    {
        return file_exists($path);
    }

    public function read(string $path): string
    {
        if (!$this->exists($path)) {
            throw new RuntimeException("File does not exist: {$path}");
        }

        $content = file_get_contents($path);

        if ($content === false) {
            throw new RuntimeException("Failed to read file: {$path}");
        }

        return $content;
    }

    public function write(string $path, string $content): bool
    {
        // Ensure directory exists
        $directory = dirname($path);
        if (!$this->isDirectory($directory)) {
            $this->makeDirectory($directory, 0755, true);
        }

        $result = file_put_contents($path, $content);

        return $result !== false;
    }

    public function glob(string $pattern): array
    {
        return glob($pattern) ?: [];
    }

    public function isDirectory(string $path): bool
    {
        return is_dir($path);
    }

    public function makeDirectory(string $path, int $mode = 0755, bool $recursive = true): bool
    {
        if ($this->isDirectory($path)) {
            return true;
        }

        return mkdir($path, $mode, $recursive);
    }

    public function lastModified(string $path): int
    {
        if (!$this->exists($path)) {
            throw new RuntimeException("File does not exist: {$path}");
        }

        $time = filemtime($path);

        if ($time === false) {
            throw new RuntimeException("Failed to get last modified time: {$path}");
        }

        return $time;
    }

    public function created(string $path): int
    {
        if (!$this->exists($path)) {
            throw new RuntimeException("File does not exist: {$path}");
        }

        $time = filectime($path);

        if ($time === false) {
            throw new RuntimeException("Failed to get creation time: {$path}");
        }

        return $time;
    }

    public function size(string $path): int
    {
        if (!$this->exists($path)) {
            throw new RuntimeException("File does not exist: {$path}");
        }

        $size = filesize($path);

        if ($size === false) {
            throw new RuntimeException("Failed to get file size: {$path}");
        }

        return $size;
    }

    public function delete(string $path): bool
    {
        if (!$this->exists($path)) {
            return true; // Already deleted
        }

        if ($this->isDirectory($path)) {
            return rmdir($path);
        }

        return unlink($path);
    }

    public function isWritable(string $path): bool
    {
        return is_writable($path);
    }
}
