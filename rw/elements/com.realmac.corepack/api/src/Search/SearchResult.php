<?php

namespace App\Search;

use App\CMS\Item;

/**
 * Search result container with metadata
 */
class SearchResult
{
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly float $duration,
        public readonly string $query,
        public readonly array $filters = []
    ) {}

    /**
     * Get items as array
     */
    public function toArray(): array
    {
        return [
            'items' => array_map(fn(Item $item) => $item->toArray(), $this->items),
            'total' => $this->total,
            'duration' => $this->duration,
            'query' => $this->query,
            'filters' => $this->filters
        ];
    }

    /**
     * Check if search has results
     */
    public function hasResults(): bool
    {
        return !empty($this->items);
    }

    /**
     * Get first item or null
     */
    public function first(): ?Item
    {
        return $this->items[0] ?? null;
    }

    /**
     * Get items count
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Get performance metrics
     */
    public function getMetrics(): array
    {
        return [
            'query' => $this->query,
            'total_results' => $this->total,
            'returned_results' => $this->count(),
            'duration_ms' => round($this->duration * 1000, 2),
            'filters_applied' => count($this->filters)
        ];
    }
}
