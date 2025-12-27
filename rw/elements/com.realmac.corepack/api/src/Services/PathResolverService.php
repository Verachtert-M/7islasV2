<?php

namespace App\Services;

use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Service for resolving relative content paths to absolute filesystem paths
 */
class PathResolverService
{
    private static ?array $pathCache = null;
    private static ?string $resolvedServerRoot = null;
    private static ?string $resolvedSiteBasePath = null;
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * Resolve a relative content path to an absolute filesystem path
     * Uses the HTTP referer to determine the base path for relative resolution
     */
    public function resolveContentPath(string $relativePath, ?Request $request = null): ?string
    {
        try {
            if (!$this->initializePathResolution()) {
                return null;
            }

            // Get the base path from the referer if available
            $basePath = null;
            if ($request) {
                $referer = $request->getHeaderLine('referer');
                if ($referer) {
                    $refererPath = parse_url($referer, PHP_URL_PATH);
                    if ($refererPath) {
                        $basePath = $this->calculateContentPathFromReferer($refererPath);
                    }
                }
            }

            // If no referer-based path, fall back to site root
            if (!$basePath) {
                $basePath = rtrim(self::$resolvedServerRoot, '/') . self::$resolvedSiteBasePath;
            }

            // Normalize and combine paths
            $normalizedPath = $this->normalizePath($relativePath);
            $absolutePath = rtrim($basePath, '/') . '/' . ltrim($normalizedPath, '/');
            $resolvedPath = realpath($absolutePath) ?: $absolutePath;

            return $this->isValidContentPath($resolvedPath) ? $resolvedPath : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get content path from HTTP referer (for page-relative content)
     */
    public function getContentPathFromRequest(Request $request): ?string
    {
        $referer = $request->getHeaderLine('referer');
        if (!$referer) {
            return null;
        }

        $refererPath = parse_url($referer, PHP_URL_PATH);
        if (!$refererPath) {
            return null;
        }

        return $this->calculateContentPathFromReferer($refererPath);
    }

    /**
     * Resolve a path using depth-based navigation from the referer
     * This avoids sending "../" patterns in requests which trigger WAF rules
     * 
     * @param string $path The clean path without "../" (e.g., "cms/content/blog")
     * @param int $depth Number of directory levels to go up from the referer
     * @param Request|null $request The HTTP request containing the referer header
     * @return string|null The resolved absolute filesystem path, or null on failure
     */
    public function resolveWithDepth(string $path, int $depth, ?Request $request): ?string
    {
        try {
            // Get the referring page's directory
            $refererDir = $this->getContentPathFromRequest($request);
            if (!$refererDir) {
                // Fall back to site root if no referer
                if (!$this->initializePathResolution()) {
                    return null;
                }
                $refererDir = rtrim(self::$resolvedServerRoot, '/') . self::$resolvedSiteBasePath;
            }

            // Go up 'depth' levels from the referer directory
            $basePath = $refererDir;
            for ($i = 0; $i < $depth; $i++) {
                $basePath = dirname($basePath);
            }

            // Normalize the destination path (remove leading slashes/dots)
            $cleanPath = $this->normalizePath($path);

            // Append the destination path
            $fullPath = rtrim($basePath, '/') . '/' . ltrim($cleanPath, '/');
            $resolvedPath = realpath($fullPath) ?: $fullPath;

            return $this->isValidContentPath($resolvedPath) ? $resolvedPath : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get the resolved site base path (useful for other operations)
     */
    public function getSiteBasePath(): string
    {
        $this->initializePathResolution();
        return self::$resolvedSiteBasePath ?? '';
    }

    /**
     * Get the resolved server root (useful for other operations)
     */
    public function getServerRoot(): string
    {
        $this->initializePathResolution();
        return self::$resolvedServerRoot ?? '';
    }

    /**
     * Clear the path cache (useful for testing)
     */
    public static function clearCache(): void
    {
        self::$pathCache = null;
        self::$resolvedServerRoot = null;
        self::$resolvedSiteBasePath = null;
    }

    /**
     * Initialize path resolution system
     */
    private function initializePathResolution(): bool
    {
        if (self::$resolvedServerRoot && self::$resolvedSiteBasePath !== null) {
            return true; // Already initialized
        }

        try {
            // Get site base path from API location
            $apiPath = dirname($_SERVER['SCRIPT_NAME'] ?? '');
            self::$resolvedSiteBasePath = explode('/rw/', $apiPath)[0];

            // Calculate server root efficiently
            $scriptDir = dirname($_SERVER['SCRIPT_FILENAME']);
            self::$resolvedServerRoot = explode('/rw/', $scriptDir)[0];

            // Remove site subdirectory duplication
            if (self::$resolvedSiteBasePath && str_ends_with(self::$resolvedServerRoot, self::$resolvedSiteBasePath . '/')) {
                self::$resolvedServerRoot = substr(self::$resolvedServerRoot, 0, -strlen(self::$resolvedSiteBasePath . '/'));
            }

            return !empty(self::$resolvedServerRoot);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Calculate content path from referer URL
     * Accounts for API being 4 levels deep from site root
     */
    private function calculateContentPathFromReferer(string $refererPath): ?string
    {
        $cacheKey = md5($refererPath . ($_SERVER['SCRIPT_NAME'] ?? ''));

        if (self::$pathCache && isset(self::$pathCache[$cacheKey])) {
            return self::$pathCache[$cacheKey];
        }

        if (!$this->initializePathResolution()) {
            return null;
        }

        // Calculate path within site
        $pathWithinSite = self::$resolvedSiteBasePath && str_starts_with($refererPath, self::$resolvedSiteBasePath)
            ? substr($refererPath, strlen(self::$resolvedSiteBasePath))
            : $refererPath;

        // Get the directory of the referring page
        // If the referer ends with a slash, it already points to a directory (e.g. "/sub/")
        if ($pathWithinSite !== '' && str_ends_with($pathWithinSite, '/')) {
            // Use the directory path as-is, without the trailing slash ("/sub/") -> "/sub"
            $refererDir = rtrim($pathWithinSite, '/');
        } else {
            $refererDir = dirname($pathWithinSite);
            if ($refererDir === '.') {
                $refererDir = '';
            }
        }

        // Build the base path to the referring page's directory
        $refererBasePath = rtrim(self::$resolvedServerRoot, '/') . self::$resolvedSiteBasePath . $refererDir;
        $normalizedPath = realpath($refererBasePath) ?: $refererBasePath;

        // Cache result
        self::$pathCache[$cacheKey] = $normalizedPath;

        return $normalizedPath;
    }

    /**
     * Normalize relative paths to consistent format
     */
    private function normalizePath(string $path): string
    {
        // Remove leading ./ if present
        if (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }

        // Remove leading / if present (we'll add it back)
        $path = ltrim($path, '/');

        // Handle empty path
        if (empty($path)) {
            return '';
        }

        return $path;
    }

    /**
     * Validate that the content path is safe and accessible
     */
    private function isValidContentPath(string $contentPath): bool
    {
        if (!$contentPath || !is_dir($contentPath) || !is_readable($contentPath)) {
            return false;
        }

        // Quick whitelist check if configured
        if (isset($this->config['allowed_content_paths'])) {
            foreach ($this->config['allowed_content_paths'] as $allowedPath) {
                if (str_starts_with($contentPath, realpath($allowedPath))) {
                    return true;
                }
            }
            return false;
        }

        return true;
    }
}
