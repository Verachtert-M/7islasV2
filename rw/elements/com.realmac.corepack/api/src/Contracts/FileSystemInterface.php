<?php

namespace App\Contracts;

interface FileSystemInterface
{
    /**
     * Check if a file or directory exists
     */
    public function exists(string $path): bool;

    /**
     * Read the contents of a file
     */
    public function read(string $path): string;

    /**
     * Write content to a file
     */
    public function write(string $path, string $content): bool;

    /**
     * Get files matching a pattern
     */
    public function glob(string $pattern): array;

    /**
     * Check if path is a directory
     */
    public function isDirectory(string $path): bool;

    /**
     * Create a directory
     */
    public function makeDirectory(string $path, int $mode = 0755, bool $recursive = true): bool;

    /**
     * Get the last modified time of a file
     */
    public function lastModified(string $path): int;

    /**
     * Get file creation time
     */
    public function created(string $path): int;

    /**
     * Get file size
     */
    public function size(string $path): int;

    /**
     * Delete a file
     */
    public function delete(string $path): bool;

    /**
     * Check if file is writable
     */
    public function isWritable(string $path): bool;
}
