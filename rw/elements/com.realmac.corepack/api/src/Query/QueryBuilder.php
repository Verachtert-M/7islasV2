<?php

namespace App\Query;

use App\CMS\Item;
use App\Repositories\ItemRepository;

/**
 * Query builder for CMS items
 */
class QueryBuilder
{
    private array $wheres = [];
    private array $orderBy = [];
    private ?int $limit = null;
    private int $offset = 0;
    private array $with = [];
    private ?string $collection = null;

    public function __construct(
        private ItemRepository $repository
    ) {}

    /**
     * Filter by collection
     */
    public function collection(string $collection): self
    {
        $this->collection = $collection;
        return $this;
    }

    /**
     * Add where condition
     */
    public function where(string $field, mixed $operator, mixed $value = null): self
    {
        // If only 2 arguments, assume equals operator
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        $this->wheres[] = [$field, $operator, $value];
        return $this;
    }

    /**
     * Add where in condition
     */
    public function whereIn(string $field, array $values): self
    {
        $this->wheres[] = [$field, 'in', $values];
        return $this;
    }

    /**
     * Add where not in condition
     */
    public function whereNotIn(string $field, array $values): self
    {
        $this->wheres[] = [$field, 'not_in', $values];
        return $this;
    }

    /**
     * Add where null condition
     */
    public function whereNull(string $field): self
    {
        $this->wheres[] = [$field, 'is', null];
        return $this;
    }

    /**
     * Add where not null condition
     */
    public function whereNotNull(string $field): self
    {
        $this->wheres[] = [$field, 'is_not', null];
        return $this;
    }

    /**
     * Add where like condition
     */
    public function whereLike(string $field, string $pattern): self
    {
        $this->wheres[] = [$field, 'like', $pattern];
        return $this;
    }

    /**
     * Add where between condition
     */
    public function whereBetween(string $field, array $values): self
    {
        if (count($values) !== 2) {
            throw new \InvalidArgumentException('whereBetween requires exactly 2 values');
        }
        $this->wheres[] = [$field, 'between', $values];
        return $this;
    }

    /**
     * Add where date condition
     */
    public function whereDate(string $field, string $operator, string $date): self
    {
        $this->wheres[] = [$field, "date_{$operator}", $date];
        return $this;
    }

    /**
     * Filter by status
     */
    public function published(): self
    {
        return $this->where('status', 'published');
    }

    /**
     * Filter by draft status
     */
    public function draft(): self
    {
        return $this->where('status', 'draft');
    }

    /**
     * Filter by featured
     */
    public function featured(bool $featured = true): self
    {
        return $this->where('featured', $featured);
    }

    /**
     * Filter by tags
     */
    public function withTag(string $tag): self
    {
        $this->wheres[] = ['tags', 'contains', $tag];
        return $this;
    }

    /**
     * Filter by multiple tags (any)
     */
    public function withAnyTag(array $tags): self
    {
        $this->wheres[] = ['tags', 'intersects', $tags];
        return $this;
    }

    /**
     * Filter by multiple tags (all)
     */
    public function withAllTags(array $tags): self
    {
        foreach ($tags as $tag) {
            $this->withTag($tag);
        }
        return $this;
    }

    /**
     * Filter by author
     */
    public function byAuthor(string $author): self
    {
        return $this->where('author', $author);
    }

    /**
     * Order by field
     */
    public function orderBy(string $field, string $direction = 'asc'): self
    {
        $this->orderBy[] = [$field, strtolower($direction)];
        return $this;
    }

    /**
     * Order by date (descending by default)
     */
    public function latest(): self
    {
        return $this->orderBy('date', 'desc');
    }

    /**
     * Order by date (ascending)
     */
    public function oldest(): self
    {
        return $this->orderBy('date', 'asc');
    }

    /**
     * Order by title
     */
    public function alphabetical(): self
    {
        return $this->orderBy('title', 'asc');
    }

    /**
     * Limit results
     */
    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    /**
     * Take first N items
     */
    public function take(int $count): self
    {
        return $this->limit($count);
    }

    /**
     * Skip N items
     */
    public function skip(int $count): self
    {
        $this->offset = $count;
        return $this;
    }

    /**
     * Offset results
     */
    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    /**
     * Paginate results
     */
    public function paginate(int $page, int $perPage = 15): self
    {
        $this->limit = $perPage;
        $this->offset = ($page - 1) * $perPage;
        return $this;
    }

    /**
     * Include relations
     */
    public function with(array $relations): self
    {
        $this->with = array_merge($this->with, $relations);
        return $this;
    }

    /**
     * Execute query and get results
     */
    public function get(): array
    {
        $items = $this->collection
            ? $this->repository->findByCollection($this->collection)
            : $this->repository->all();

        // Apply filters
        $items = $this->applyFilters($items);

        // Apply sorting
        $items = $this->applySorting($items);

        // Apply limit and offset
        if ($this->offset > 0) {
            $items = array_slice($items, $this->offset);
        }

        if ($this->limit !== null) {
            $items = array_slice($items, 0, $this->limit);
        }

        // Load relations
        if (!empty($this->with)) {
            $items = $this->loadRelations($items);
        }

        return $items;
    }

    /**
     * Get first result
     */
    public function first(): ?Item
    {
        $results = $this->take(1)->get();
        return $results[0] ?? null;
    }

    /**
     * Get single result or fail
     */
    public function firstOrFail(): Item
    {
        $item = $this->first();
        if ($item === null) {
            throw new \RuntimeException('No items found matching query');
        }
        return $item;
    }

    /**
     * Count results without executing full query
     */
    public function count(): int
    {
        $items = $this->collection
            ? $this->repository->findByCollection($this->collection)
            : $this->repository->all();

        return count($this->applyFilters($items));
    }

    /**
     * Check if any results exist
     */
    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * Get random items
     */
    public function random(int $count = 1): array
    {
        $items = $this->get();
        shuffle($items);
        return array_slice($items, 0, $count);
    }

    /**
     * Apply filters to items
     */
    private function applyFilters(array $items): array
    {
        foreach ($this->wheres as $where) {
            [$field, $operator, $value] = $where;
            $items = array_filter($items, fn(Item $item) => $this->matchesCondition($item, $field, $operator, $value));
        }

        return array_values($items);
    }

    /**
     * Check if item matches condition
     */
    private function matchesCondition(Item $item, string $field, string $operator, mixed $value): bool
    {
        $itemValue = $this->getFieldValue($item, $field);

        return match ($operator) {
            '=' => $itemValue == $value,
            '!=' => $itemValue != $value,
            '>' => $itemValue > $value,
            '>=' => $itemValue >= $value,
            '<' => $itemValue < $value,
            '<=' => $itemValue <= $value,
            'like' => is_string($itemValue) && str_contains(strtolower($itemValue), strtolower($value)),
            'in' => in_array($itemValue, $value),
            'not_in' => !in_array($itemValue, $value),
            'is' => $itemValue === $value,
            'is_not' => $itemValue !== $value,
            'contains' => is_array($itemValue) && in_array($value, $itemValue),
            'intersects' => is_array($itemValue) && !empty(array_intersect($itemValue, $value)),
            'between' => $itemValue >= $value[0] && $itemValue <= $value[1],
            'date_=' => $this->compareDates($itemValue, '=', $value),
            'date_>' => $this->compareDates($itemValue, '>', $value),
            'date_>=' => $this->compareDates($itemValue, '>=', $value),
            'date_<' => $this->compareDates($itemValue, '<', $value),
            'date_<=' => $this->compareDates($itemValue, '<=', $value),
            default => throw new \InvalidArgumentException("Unknown operator: {$operator}")
        };
    }

    /**
     * Get field value from item
     */
    private function getFieldValue(Item $item, string $field): mixed
    {
        return match ($field) {
            'slug' => $item->slug(),
            'title' => $item->title(),
            'author' => $item->author(),
            'status' => $item->status(),
            'featured' => $item->featured(),
            'tags' => $item->tags(),
            'categories' => $item->categories(),
            'image' => $item->image(),
            'excerpt' => $item->excerpt(),
            'date' => $item->getMetadata()->date,
            default => $item->meta($field)
        };
    }

    /**
     * Compare dates
     */
    private function compareDates(mixed $itemDate, string $operator, string $compareDate): bool
    {
        if (!$itemDate instanceof \DateTimeImmutable) {
            return false;
        }

        $compare = new \DateTimeImmutable($compareDate);

        return match ($operator) {
            '=' => $itemDate->format('Y-m-d') === $compare->format('Y-m-d'),
            '>' => $itemDate > $compare,
            '>=' => $itemDate >= $compare,
            '<' => $itemDate < $compare,
            '<=' => $itemDate <= $compare,
            default => false
        };
    }

    /**
     * Apply sorting to items
     */
    private function applySorting(array $items): array
    {
        if (empty($this->orderBy)) {
            return $items;
        }

        usort($items, function (Item $a, Item $b) {
            foreach ($this->orderBy as [$field, $direction]) {
                $aValue = $this->getFieldValue($a, $field);
                $bValue = $this->getFieldValue($b, $field);

                $comparison = $aValue <=> $bValue;

                if ($comparison !== 0) {
                    return $direction === 'desc' ? -$comparison : $comparison;
                }
            }
            return 0;
        });

        return $items;
    }

    /**
     * Load relations for items
     */
    private function loadRelations(array $items): array
    {
        // This would typically load related data
        // For now, just return items as-is
        return $items;
    }

    /**
     * Clone the query builder
     */
    public function clone(): self
    {
        $clone = new self($this->repository);
        $clone->wheres = $this->wheres;
        $clone->orderBy = $this->orderBy;
        $clone->limit = $this->limit;
        $clone->offset = $this->offset;
        $clone->with = $this->with;
        $clone->collection = $this->collection;
        return $clone;
    }
}
