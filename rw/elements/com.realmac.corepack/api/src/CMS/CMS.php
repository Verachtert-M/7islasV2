<?php

namespace App\CMS;

use App\Services\Logger;
use Psr\Log\LoggerInterface;

/**
 * CMS API for internal server-side usage
 * Provides a clean, chainable interface for CMS operations
 */
class CMS
{
    private static array $instances = [];
    private array $options;
    private LoggerInterface $logger;

    private function __construct(array $options = [])
    {
        $this->options = array_merge([
            'cache_ttl' => 3600,
            'parser' => [
                'markdown' => [
                    'allow_unsafe_links' => false,
                    'allow_unsafe_images' => false,
                    'html_input' => 'strip',
                ]
            ]
        ], $options);

        // Initialize logger
        $this->logger = Logger::getLogger('cms', $this->options);
        $this->logger->debug('CMS instance created', [
            'options' => array_keys($this->options),
            'instance_count' => count(self::$instances)
        ]);
    }

    /**
     * Create or get a cached CMS instance
     */
    public static function make(array $options = []): self
    {
        $key = md5(serialize($options));

        $logger = Logger::getInstance();
        $logger->debug('CMS::make() called', [
            'options_keys' => array_keys($options),
            'instance_key' => substr($key, 0, 8) . '...',
            'existing_instances' => count(self::$instances)
        ]);

        if (!isset(self::$instances[$key])) {
            $logger->info('Creating new CMS instance', [
                'instance_key' => substr($key, 0, 8) . '...',
                'total_instances' => count(self::$instances) + 1
            ]);
            self::$instances[$key] = new self($options);
        } else {
            $logger->debug('Returning existing CMS instance', [
                'instance_key' => substr($key, 0, 8) . '...'
            ]);
        }

        return self::$instances[$key];
    }

    /**
     * Get a collection builder for a relative collection path
     * The path will be automatically resolved based on the HTTP referer
     */
    public function collection(string $relativePath): CollectionBuilder
    {
        $this->logger->debug('CMS collection() called', ['relative_path' => $relativePath]);

        $fullPath = $this->resolveCollectionPath($relativePath);
        $this->logger->debug('Collection path resolved', ['full_path' => $fullPath]);

        if (!is_dir($fullPath)) {
            $this->logger->warning('Collection directory does not exist', ['path' => $fullPath]);
        }

        return new CollectionBuilder($fullPath, $this->options, $this);
    }

    /**
     * Get a single item by path - either a specific file or a collection with URL slug
     */
    public function item(string $path, ?string $slug = null, array $options = []): ?object
    {
        $this->logger->debug('CMS item() called', [
            'path' => $path,
            'slug' => $slug,
            'options' => array_keys($options)
        ]);

        // Resolve the path first
        $fullPath = $this->resolveCollectionPath($path);
        $this->logger->debug('Path resolved', ['full_path' => $fullPath]);

        // Check if path points to a specific file
        if (pathinfo($fullPath, PATHINFO_EXTENSION)) {
            $this->logger->debug('Single file mode detected');
            // Single file mode - use file() method
            return $this->file($fullPath, $options);
        }

        // Collection mode - get slug from URL or use provided slug
        $actualSlug = $slug ?? $this->getSlugFromUrl();
        $this->logger->debug('Collection mode', ['actual_slug' => $actualSlug]);

        if (!$actualSlug) {
            $this->logger->warning('No slug available for collection item', [
                'path' => $path,
                'provided_slug' => $slug
            ]);
            return null; // No slug available
        }

        $repository = $this->getRepository($fullPath);
        $collectionName = basename($fullPath);
        $item = $repository->getItem($collectionName, $actualSlug, $options);

        if ($item) {
            $this->logger->info('Item found successfully', [
                'collection' => $collectionName,
                'slug' => $actualSlug
            ]);
            return $this->transformItem($item, $options, $path);
        } else {
            $this->logger->warning('Item not found', [
                'collection' => $collectionName,
                'slug' => $actualSlug
            ]);
            return null;
        }
    }

    /**
     * Search across collections or within a specific collection path
     */
    public function search(string $query, ?string $collectionPath = null, array $options = []): array
    {
        $this->logger->debug('CMS search() called', [
            'query' => $query,
            'collection_path' => $collectionPath,
            'options' => array_keys($options)
        ]);

        if ($collectionPath) {
            $repository = $this->getRepository($collectionPath);
            $collectionName = basename($collectionPath);
            $this->logger->debug('Searching collection', [
                'collection_name' => $collectionName,
                'query' => $query
            ]);

            $results = $repository->search($query, $collectionName, $options);

            $this->logger->info('Search completed', [
                'query' => $query,
                'collection' => $collectionName,
                'results_count' => count($results)
            ]);
        } else {
            $this->logger->error('Global search attempted without collection path', [
                'query' => $query
            ]);
            // For global search, we'd need a base path - this might need to be rethought
            throw new \InvalidArgumentException('Global search requires a collection path');
        }

        return array_map(fn($item) => $this->transformItem($item, $options, $collectionPath), $results);
    }

    /**
     * Get individual item by full file path
     */
    public function file(string $filePath, array $options = []): ?object
    {
        $this->logger->debug('CMS file() called', [
            'file_path' => $filePath,
            'options' => array_keys($options)
        ]);

        if (!file_exists($filePath)) {
            $this->logger->warning('File does not exist', ['file_path' => $filePath]);
            return null;
        }

        // For single files, create repository with the file's directory as content base
        $contentPath = dirname($filePath);
        $repository = CMSRepository::make($contentPath, $this->options);
        $item = $repository->getIndividualItem(basename($filePath), $options);

        if ($item) {
            $this->logger->info('File item found successfully', [
                'file_path' => $filePath,
                'content_path' => $contentPath
            ]);
            return $this->transformItem($item, $options, $filePath);
        } else {
            $this->logger->warning('File item not found', ['file_path' => $filePath]);
            return null;
        }
    }

    /**
     * Get all available collections in a directory
     */
    public function collections(string $basePath): array
    {
        $this->logger->debug('CMS collections() called', ['base_path' => $basePath]);

        if (!is_dir($basePath)) {
            $this->logger->warning('Collections base path does not exist', ['base_path' => $basePath]);
            return [];
        }

        $repository = $this->getRepository($basePath);
        $collections = $repository->getCollections();

        $this->logger->info('Collections retrieved', [
            'base_path' => $basePath,
            'collections_count' => count($collections)
        ]);

        return $collections;
    }

    /**
     * Get related items for a specific item
     */
    public function getRelatedItems(string $collectionPath, string $slug, array $criteria = ['tags'], int $limit = 5): array
    {
        $this->logger->debug('CMS getRelatedItems() called', [
            'collection_path' => $collectionPath,
            'slug' => $slug,
            'criteria' => $criteria,
            'limit' => $limit
        ]);

        $repository = $this->getRepository($collectionPath);
        $items = $repository->getRelatedItems($collectionPath, $slug, $criteria, $limit);

        $this->logger->info('Related items retrieved', [
            'collection_path' => $collectionPath,
            'slug' => $slug,
            'criteria' => $criteria,
            'items_count' => count($items),
            'limit' => $limit
        ]);

        // Transform items to template-friendly objects
        return array_map(fn($item) => $this->transformItem($item, [], $collectionPath), $items);
    }

    /**
     * Get or create a repository instance for a specific path
     */
    private function getRepository(string $path): CMSRepository
    {
        // Only resolve if path is relative (doesn't start with /)
        // If it's already absolute, use it as-is
        $resolvedPath = $this->isAbsolutePath($path)
            ? $path
            : $this->resolveCollectionPath($path);

        // For collection paths, we need the parent directory as content base
        $contentPath = dirname($resolvedPath);
        return CMSRepository::make($contentPath, $this->options);
    }

    /**
     * Check if a path is absolute
     */
    private function isAbsolutePath(string $path): bool
    {
        // Unix/Linux/Mac: starts with /
        // Windows: starts with C:\ or similar
        return str_starts_with($path, '/') || preg_match('/^[A-Z]:\\\\/i', $path);
    }

    /**
     * Transform CMS item to template-friendly object
     */
    public function transformItem($item, array $options = [], ?string $originalCollectionPath = null): object
    {
        if (!$item) {
            $this->logger->debug('transformItem() called with null item');
            return (object) [];
        }

        $itemSlug = method_exists($item, 'slug') ? $item->slug() : 'unknown';
        $this->logger->debug('transformItem() called', [
            'item_slug' => $itemSlug,
            'original_collection_path' => $originalCollectionPath,
            'options' => array_keys($options)
        ]);

        // Use original collection path if provided, otherwise extract from filepath
        $collectionPath = $originalCollectionPath;
        $filepath = method_exists($item, 'file') ? $item->file() : null;
        if (!$collectionPath && $filepath) {
            $pathParts = explode('/', dirname($filepath));
            $collectionPath = end($pathParts);
            $this->logger->debug('Collection path extracted from filepath', [
                'filepath' => $filepath,
                'extracted_collection_path' => $collectionPath
            ]);
        }

        // Calculate collection name (basename) for use throughout
        // This is what gets attached to items and used by getRelatedItems()
        $collectionName = null;
        if ($collectionPath) {
            // Only resolve path if it's relative
            $resolvedPath = $this->isAbsolutePath($collectionPath)
                ? $collectionPath
                : $this->resolveCollectionPath($collectionPath);
            $collectionName = basename($resolvedPath);
        }

        // Inject repository context for related items functionality
        // This allows $item->getRelatedItems() to work without path resolution issues
        if ($collectionPath && method_exists($item, 'attach')) {
            try {
                // Get repository (which will also check if path needs resolution)
                $repository = $this->getRepository($collectionPath);

                $item->attach('repository', $repository);
                $item->attach('collection_path', $collectionName);
                $item->attach('cms', $this); // Attach CMS instance for URL generation
                $item->attach('cms_options', $options); // Attach options (page_path, pretty_urls, etc.)

                $this->logger->debug('Repository context attached to item', [
                    'collection_name' => $collectionName,
                    'item_slug' => $itemSlug,
                    'original_path' => $collectionPath
                ]);
            } catch (\Exception $e) {
                $this->logger->warning('Failed to attach repository context to item', [
                    'item_slug' => $itemSlug,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Generate and attach URL to item
        $pagePath = $options['page_path'] ?? null;
        $prettyUrls = $options['pretty_urls'] ?? false;
        $generatedUrl = $this->generateItemUrl($item->slug(), $pagePath, $prettyUrls);

        // Attach URL to item for template access
        // Note: collection_path already attached above (as basename)
        $item->attach('url', $generatedUrl);

        $this->logger->debug('Item transformed successfully', [
            'item_slug' => $itemSlug,
            'collection_name' => $collectionName,
            'original_path' => $collectionPath,
            'generated_url' => $generatedUrl
        ]);

        // Return the Item instance itself to preserve all methods
        return $item;
    }

    /**
     * Generate URL for an individual item
     */
    private function generateItemUrl(string $slug, ?string $pagePath, bool $prettyUrls = false): string
    {
        if (!$pagePath) {
            return ''; // No page path configured
        }

        if ($prettyUrls) {
            return rtrim($pagePath, '/') . '/' . $slug;
        } else {
            return $pagePath . '?item=' . $slug;
        }
    }

    /**
     * Get slug from URL - either from query parameter or path
     */
    private function getSlugFromUrl(): ?string
    {
        // Try to get from query parameter first
        if (isset($_GET['item'])) {
            return $_GET['item'];
        }

        // Try to get from path (for pretty URLs)
        $pathInfo = $_SERVER['PATH_INFO'] ?? '';
        if ($pathInfo) {
            return trim(basename($pathInfo), '/');
        }

        // Try to get from REQUEST_URI as fallback
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        if ($requestUri) {
            $path = parse_url($requestUri, PHP_URL_PATH);
            $pathParts = array_filter(explode('/', $path));
            return end($pathParts) ?: null;
        }

        return null;
    }

    /**
     * Clear all cached instances (useful for testing)
     */
    public static function clearInstances(): void
    {
        self::$instances = [];
    }

    /**
     * Resolve a relative collection path to an absolute filesystem path
     * Uses the current HTTP request context to determine the base path
     */
    private function resolveCollectionPath(string $relativePath): string
    {
        // Check if we have a pre-resolved content path (set by API controllers)
        if (isset($_SERVER['CMS_RESOLVED_CONTENT_PATH'])) {
            // For API calls, the path has already been resolved
            return $_SERVER['CMS_RESOLVED_CONTENT_PATH'];
        }

        // Create a mock PSR-7 request from global variables for path resolution
        $request = $this->createRequestFromGlobals();

        // Use PathResolverService to resolve the path
        $pathResolver = new \App\Services\PathResolverService();
        $resolvedPath = $pathResolver->resolveContentPath($relativePath, $request);

        if (!$resolvedPath) {
            // Fallback: try to resolve from current working directory
            $cwd = getcwd();
            $resolvedPath = $cwd . '/' . ltrim($relativePath, '/');
        }

        return $resolvedPath;
    }

    /**
     * Create a basic PSR-7 request from PHP globals
     */
    private function createRequestFromGlobals(): \Psr\Http\Message\ServerRequestInterface
    {
        // Create a simple request object with the referer header
        $headers = [];
        if (isset($_SERVER['HTTP_REFERER'])) {
            $headers['referer'] = $_SERVER['HTTP_REFERER'];
        }

        // Create a basic request implementation
        return new class($headers) implements \Psr\Http\Message\ServerRequestInterface {
            private array $headers;

            public function __construct(array $headers)
            {
                $this->headers = $headers;
            }

            public function getHeaderLine(string $name): string
            {
                $name = strtolower($name);
                return $this->headers[$name] ?? '';
            }

            // Minimal implementation - only what we need
            public function getProtocolVersion(): string
            {
                return '1.1';
            }
            public function withProtocolVersion(string $version): \Psr\Http\Message\MessageInterface
            {
                return $this;
            }
            public function getHeaders(): array
            {
                return $this->headers;
            }
            public function hasHeader(string $name): bool
            {
                return isset($this->headers[strtolower($name)]);
            }
            public function getHeader(string $name): array
            {
                return [$this->getHeaderLine($name)];
            }
            public function withHeader(string $name, $value): \Psr\Http\Message\MessageInterface
            {
                return $this;
            }
            public function withAddedHeader(string $name, $value): \Psr\Http\Message\MessageInterface
            {
                return $this;
            }
            public function withoutHeader(string $name): \Psr\Http\Message\MessageInterface
            {
                return $this;
            }
            public function getBody(): \Psr\Http\Message\StreamInterface
            {
                throw new \Exception('Not implemented');
            }
            public function withBody(\Psr\Http\Message\StreamInterface $body): \Psr\Http\Message\MessageInterface
            {
                return $this;
            }
            public function getRequestTarget(): string
            {
                return '/';
            }
            public function withRequestTarget(string $requestTarget): \Psr\Http\Message\RequestInterface
            {
                return $this;
            }
            public function getMethod(): string
            {
                return 'GET';
            }
            public function withMethod(string $method): \Psr\Http\Message\RequestInterface
            {
                return $this;
            }
            public function getUri(): \Psr\Http\Message\UriInterface
            {
                throw new \Exception('Not implemented');
            }
            public function withUri(\Psr\Http\Message\UriInterface $uri, bool $preserveHost = false): \Psr\Http\Message\RequestInterface
            {
                return $this;
            }
            public function getServerParams(): array
            {
                return $_SERVER;
            }
            public function getCookieParams(): array
            {
                return [];
            }
            public function withCookieParams(array $cookies): \Psr\Http\Message\ServerRequestInterface
            {
                return $this;
            }
            public function getQueryParams(): array
            {
                return [];
            }
            public function withQueryParams(array $query): \Psr\Http\Message\ServerRequestInterface
            {
                return $this;
            }
            public function getUploadedFiles(): array
            {
                return [];
            }
            public function withUploadedFiles(array $uploadedFiles): \Psr\Http\Message\ServerRequestInterface
            {
                return $this;
            }
            public function getParsedBody()
            {
                return null;
            }
            public function withParsedBody($data): \Psr\Http\Message\ServerRequestInterface
            {
                return $this;
            }
            public function getAttributes(): array
            {
                return [];
            }
            public function getAttribute(string $name, $default = null)
            {
                return $default;
            }
            public function withAttribute(string $name, $value): \Psr\Http\Message\ServerRequestInterface
            {
                return $this;
            }
            public function withoutAttribute(string $name): \Psr\Http\Message\ServerRequestInterface
            {
                return $this;
            }
        };
    }

    /**
     * Detect if the current request is using pretty URLs
     * This method analyzes the current URL structure to determine if pretty URLs are enabled
     */
    public static function detectPrettyUrls(): bool
    {
        // Check if the actual REQUEST_URI contains query parameters for item/slug/post
        // This checks the user-visible URL, not internal rewrite parameters
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $queryString = parse_url($requestUri, PHP_URL_QUERY);

        // If we have explicit query parameters for item, slug, or post in the URL, NOT pretty
        if ($queryString) {
            parse_str($queryString, $queryParams);
            if (isset($queryParams['item']) || isset($queryParams['slug']) || isset($queryParams['post'])) {
                return false;
            }
        }

        // If we have PATH_INFO, that's a strong indicator of pretty URLs
        if (isset($_SERVER['PATH_INFO']) && !empty($_SERVER['PATH_INFO'])) {
            return true;
        }

        // If $_GET has parameters but REQUEST_URI doesn't show them, pretty URLs are working
        if ((isset($_GET['item']) || isset($_GET['slug']) || isset($_GET['post'])) && !$queryString) {
            return true;
        }

        // Default: assume pretty URLs if no explicit query string
        return true;
    }

    /**
     * Clean the page path for pretty URLs to avoid double slugs
     * This removes the slug from the end of the URL if it matches the provided slug
     */
    public static function cleanPagePathForPrettyUrls(string $currentUrl, ?string $slug): string
    {
        if (!$slug) {
            return $currentUrl;
        }

        // Remove the slug from the end of the URL path
        $path = parse_url($currentUrl, PHP_URL_PATH);
        $pathParts = array_filter(explode('/', $path));

        // Remove the last part (the slug) if it matches our slug
        if (end($pathParts) === $slug) {
            array_pop($pathParts);
        }

        // Reconstruct the URL without the slug
        $newPath = '/' . implode('/', $pathParts);
        return (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $newPath;
    }
}
