<?php

namespace App\CMS;

/**
 * Collection builder for fluent API
 * Provides chainable methods for building collection queries
 */
class CollectionBuilder
{
    private string $collectionPath;
    private array $options;
    private CMS $cms;
    private array $filters = [];
    private ?string $orderBy = 'date_published';
    private string $direction = 'desc';
    private ?int $page = null;
    private int $perPage = 10;
    private ?string $pagePath = null;
    private bool $prettyUrls = false;
    private ?string $tagPagePath = null;
    private ?string $authorPagePath = null;
    private int $offset = 0;

    public function __construct(string $collectionPath, array $options = [], ?CMS $cms = null)
    {
        $this->collectionPath = $collectionPath;
        $this->options = $options;
        $this->cms = $cms ?? CMS::make($options);

        // Extract URL settings from options if provided
        $this->pagePath = $options['page_path'] ?? null;
        $this->prettyUrls = $options['pretty_urls'] ?? false;
        $this->tagPagePath = $options['tag_page_path'] ?? null;
        $this->authorPagePath = $options['author_page_path'] ?? null;
    }

    /**
     * Add filters
     */
    public function filter(array $filters): self
    {
        $this->filters = array_merge($this->filters, $filters);
        return $this;
    }

    /**
     * Filter by status
     */
    public function status(string $status): self
    {
        $this->filters['status'] = $status;
        return $this;
    }

    /**
     * Filter by featured
     */
    public function featured(bool $featured = true): self
    {
        $this->filters['featured'] = $featured;
        return $this;
    }

    /**
     * Filter by tags
     */
    public function tags(string|array $tags): self
    {
        $this->filters['tags'] = is_array($tags) ? implode(',', $tags) : $tags;
        return $this;
    }

    /**
     * Filter by author
     */
    public function author(string|array $author): self
    {
        $this->filters['author'] = is_array($author) ? implode(',', $author) : $author;
        return $this;
    }

    /**
     * Filter by date range
     */
    public function before(string $date): self
    {
        $this->filters['date_before'] = $date;
        return $this;
    }

    public function after(string $date): self
    {
        $this->filters['date_after'] = $date;
        return $this;
    }

    /**
     * Set ordering
     */
    public function orderBy(string $field, string $direction = 'desc'): self
    {
        $this->orderBy = $field;
        $this->direction = $direction;
        return $this;
    }

    /**
     * Order by date published (most common)
     */
    public function latest(): self
    {
        return $this->orderBy('date_published', 'desc');
    }

    public function oldest(): self
    {
        return $this->orderBy('date_published', 'asc');
    }

    /**
     * Set pagination
     */
    public function paginate(int $page, int $perPage = 10): self
    {
        $this->page = $page;
        $this->perPage = $perPage;
        return $this;
    }

    /**
     * Limit results
     */
    public function limit(int $limit): self
    {
        $this->perPage = $limit;
        return $this;
    }

    /**
     * Set pagination offset (skip first N items)
     */
    public function offset(int $offset): self
    {
        $this->offset = max(0, $offset);
        return $this;
    }

    /**
     * Get all items (no pagination)
     */
    public function all(): array
    {
        $repository = $this->getRepository();
        $collectionName = basename($this->collectionPath);

        $result = $repository->getCollection(
            $collectionName,
            $this->filters,
            $this->orderBy,
            $this->direction,
            null, // Explicitly pass null for no pagination
            10,   // perPage doesn't matter when page is null, but required by signature
            $this->offset
        );

        return array_map([$this, 'transformItem'], $result['items'] ?? []);
    }

    /**
     * Get items with pagination info
     */
    public function get(): object
    {
        $repository = $this->getRepository();
        $collectionName = basename($this->collectionPath);

        $result = $repository->getCollection(
            $collectionName,
            $this->filters,
            $this->orderBy,
            $this->direction,
            $this->page,
            $this->perPage,
            $this->offset
        );

        $items = array_map([$this, 'transformItem'], $result['items'] ?? []);

        // Fix pagination property names to match template expectations
        $meta = $result['meta'] ?? [];
        $pagination = [
            'current_page' => $meta['currentPage'] ?? 1,
            'total_pages' => $meta['lastPage'] ?? 1,
            'total_items' => $meta['totalItems'] ?? count($items),
            'items_per_page' => $meta['perPage'] ?? $this->perPage,
            'offset' => $meta['offset'] ?? $this->offset,
            'has_prev' => ($meta['currentPage'] ?? 1) > 1,
            'has_next' => ($meta['currentPage'] ?? 1) < ($meta['lastPage'] ?? 1)
        ];

        return (object) [
            'items' => $items,
            'pagination' => (object) $pagination,
            'each' => function ($callback) use ($items) {
                foreach ($items as $item) {
                    $callback($item);
                }
            }
        ];
    }

    /**
     * Get first item
     */
    public function first(): ?object
    {
        $items = $this->limit(1)->all();
        return $items[0] ?? null;
    }

    /**
     * Get count of items
     */
    public function count(): int
    {
        $repository = $this->getRepository();
        $collectionName = basename($this->collectionPath);

        $result = $repository->getCollection(
            $collectionName,
            $this->filters,
            $this->orderBy,
            $this->direction,
            null,
            10,
            $this->offset
        );

        return $result['count'] ?? count($result['items'] ?? []);
    }

    /**
     * Generate RSS feed
     * 
     * @param string|null $title Feed title
     * @param string|null $description Feed description
     * @param string|null $link Channel link (site URL) - also used as base URL for absolute item links
     */
    public function rss(?string $title = null, ?string $description = null, ?string $link = null): string
    {
        $collectionName = basename($this->collectionPath);

        $title = $title ?? ucfirst($collectionName) . ' Feed';
        $description = $description ?? 'RSS feed for ' . $collectionName;
        $link = $link ?? '';
        $baseUrl = $link ? rtrim($link, '/') : '';

        // Get transformed items with proper URLs
        $originalPerPage = $this->perPage;
        $this->perPage = $this->perPage > 0 ? $this->perPage : 20; // Use builder's limit if set
        $items = $this->latest()->all();
        $this->perPage = $originalPerPage; // Restore original value

        // Generate RSS XML
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0">' . "\n";
        $xml .= '<channel>' . "\n";
        $xml .= '<title>' . htmlspecialchars($title) . '</title>' . "\n";
        $xml .= '<description>' . htmlspecialchars($description) . '</description>' . "\n";
        $xml .= '<link>' . htmlspecialchars($link) . '</link>' . "\n";
        $xml .= '<lastBuildDate>' . date('r') . '</lastBuildDate>' . "\n";

        foreach ($items as $item) {
            $xml .= '<item>' . "\n";
            $xml .= '<title>' . htmlspecialchars($item->title ?? '') . '</title>' . "\n";
            $xml .= '<description>' . htmlspecialchars($item->excerpt ?? '') . '</description>' . "\n";
            $xml .= '<pubDate>' . date('r', strtotime($item->date ?? 'now')) . '</pubDate>' . "\n";
            $xml .= '<link>' . htmlspecialchars($item->url ?? '') . '</link>' . "\n";
            $xml .= '<guid>' . htmlspecialchars($item->url ?? '') . '</guid>' . "\n";
            $xml .= '</item>' . "\n";
        }

        $xml .= '</channel>' . "\n";
        $xml .= '</rss>';

        // Convert relative URLs to absolute in link and guid elements
        if ($baseUrl) {
            $xml = preg_replace_callback(
                '#<(link|guid)>(\.\./|\./)*(.*?)</\1>#',
                function ($matches) use ($baseUrl) {
                    $path = $matches[3];
                    if (stripos($path, 'http') !== 0) {
                        $path = $baseUrl . '/' . ltrim($path, '/');
                    }
                    return '<' . $matches[1] . '>' . $path . '</' . $matches[1] . '>';
                },
                $xml
            );
        }

        return $xml;
    }

    /**
     * Generate sitemap
     */
    public function sitemap(string $baseUrl = '', string $changefreq = 'weekly', float $priority = 0.5): string
    {
        $baseUrl = $baseUrl ? rtrim($baseUrl, '/') : '';
        
        // Get transformed items with proper URLs
        $originalPerPage = $this->perPage;
        $this->perPage = $this->perPage > 0 ? $this->perPage : 1000; // Use builder's limit if set
        $items = $this->latest()->all();
        $this->perPage = $originalPerPage; // Restore original value

        // Generate sitemap XML
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($items as $item) {
            // Use the item's URL if available, otherwise construct from baseUrl + slug
            $itemUrl = $item->url ?? ($baseUrl . '/' . ($item->slug ?? ''));

            $xml .= '<url>' . "\n";
            $xml .= '<loc>' . htmlspecialchars($itemUrl) . '</loc>' . "\n";
            $xml .= '<lastmod>' . date('Y-m-d', strtotime($item->date_modified ?? $item->date ?? 'now')) . '</lastmod>' . "\n";
            $xml .= '<changefreq>' . $changefreq . '</changefreq>' . "\n";
            $xml .= '<priority>' . $priority . '</priority>' . "\n";
            $xml .= '</url>' . "\n";
        }

        $xml .= '</urlset>';

        // Convert relative URLs to absolute in loc elements
        if ($baseUrl) {
            $xml = preg_replace_callback(
                '#<loc>\s*(\.\./|\./)*(.*?)\s*</loc>#s',
                function ($matches) use ($baseUrl) {
                    $path = trim($matches[2]);
                    if (stripos($path, 'http') !== 0) {
                        $path = $baseUrl . '/' . ltrim($path, '/');
                    }
                    return '<loc>' . $path . '</loc>';
                },
                $xml
            );
        }

        return $xml;
    }

    /**
     * Get or create a repository instance for this collection path
     */
    private function getRepository(): CMSRepository
    {
        // The collection path should be the full path to the collection directory
        // We need to get the parent directory as the content base path
        $contentPath = dirname($this->collectionPath);

        // Merge tag and author page paths into options
        $options = $this->options;
        if ($this->tagPagePath !== null) {
            $options['tag_page_path'] = $this->tagPagePath;
            $options['pretty_urls'] = $this->prettyUrls;
        }
        if ($this->authorPagePath !== null) {
            $options['author_page_path'] = $this->authorPagePath;
            $options['pretty_urls'] = $this->prettyUrls;
        }

        return CMSRepository::make($contentPath, $options);
    }

    /**
     * Transform CMS item to template-friendly object
     * Delegates to the CMS instance to ensure consistent data structure
     */
    private function transformItem($item): object
    {
        if (!$item) {
            return (object) [];
        }

        // Build options with collection-specific settings
        $options = [
            'page_path' => $this->pagePath,
            'pretty_urls' => $this->prettyUrls
        ];

        // Use the CMS transformItem method to ensure consistent data structure
        // Extract collection path from the full collection path
        $collectionPath = basename($this->collectionPath);
        return $this->cms->transformItem($item, $options, $collectionPath);
    }



    /**
     * Set the page path for URL generation
     */
    public function pagePath(string $pagePath): self
    {
        $this->pagePath = $pagePath;
        return $this;
    }

    /**
     * Set the tag page path for URL generation
     */
    public function tagPagePath(string $tagPagePath): self
    {
        $this->tagPagePath = $tagPagePath;
        return $this;
    }

    /**
     * Set the author page path for URL generation
     */
    public function authorPagePath(string $authorPagePath): self
    {
        $this->authorPagePath = $authorPagePath;
        return $this;
    }

    /**
     * Set whether to use pretty URLs
     */
    public function prettyUrls(bool $prettyUrls = true): self
    {
        $this->prettyUrls = $prettyUrls;
        return $this;
    }
}
