<?php

namespace App\Search;

use App\CMS\Item;
use App\Events\EventDispatcher;
use App\Events\SearchEvent;
use App\Contracts\CacheInterface;

/**
 * Advanced search engine with indexing and scoring
 */
class SearchEngine
{
    private array $index = [];
    private array $itemIndex = [];
    private bool $indexBuilt = false;

    public function __construct(
        private EventDispatcher $events,
        private CacheInterface $cache,
        private array $config = []
    ) {
        $this->config = array_merge([
            'min_word_length' => 3,
            'max_word_length' => 50,
            'boost_title' => 2.0,
            'boost_tags' => 1.5,
            'boost_exact_match' => 3.0,
            'cache_ttl' => 3600,
            'stop_words' => ['the', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'a', 'an', 'is', 'are', 'was', 'were', 'be', 'been', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could', 'should']
        ], $this->config);
    }

    /**
     * Build search index from items
     */
    public function buildIndex(array $items): void
    {
        $this->index = [];
        $this->itemIndex = [];

        foreach ($items as $item) {
            if (!$item instanceof Item) {
                continue;
            }

            $this->indexItem($item);
        }

        $this->indexBuilt = true;

        // Cache the index
        $this->cache->put('search_index', [
            'index' => $this->index,
            'item_index' => $this->itemIndex
        ], $this->config['cache_ttl']);
    }

    /**
     * Load index from cache
     */
    public function loadIndex(): bool
    {
        $cached = $this->cache->get('search_index');

        if ($cached && isset($cached['index'], $cached['item_index'])) {
            $this->index = $cached['index'];
            $this->itemIndex = $cached['item_index'];
            $this->indexBuilt = true;
            return true;
        }

        return false;
    }

    /**
     * Search items with advanced scoring
     */
    public function search(string $query, array $filters = [], int $limit = 50): SearchResult
    {
        $startTime = microtime(true);

        // Dispatch search starting event
        $this->events->dispatch('cms.search.starting', SearchEvent::starting($query, $filters));

        if (!$this->indexBuilt) {
            $this->loadIndex();
        }

        $query = $this->normalizeQuery($query);
        $terms = $this->extractTerms($query);

        if (empty($terms)) {
            return new SearchResult([], 0, 0.0, $query);
        }

        // Find matching items with scores
        $matches = $this->findMatches($terms, $filters);

        // Sort by relevance score (descending)
        usort($matches, fn($a, $b) => $b['score'] <=> $a['score']);

        // Limit results
        $matches = array_slice($matches, 0, $limit);

        // Extract items
        $items = array_map(fn($match) => $match['item'], $matches);

        $duration = microtime(true) - $startTime;
        $result = new SearchResult($items, count($matches), $duration, $query);

        // Dispatch search completed event
        $this->events->dispatch('cms.search.completed', SearchEvent::completed($query, $items, $duration, $filters));

        return $result;
    }

    /**
     * Get search suggestions
     */
    public function suggest(string $partial, int $limit = 10): array
    {
        $partial = strtolower(trim($partial));

        if (strlen($partial) < 2) {
            return [];
        }

        $suggestions = [];

        foreach ($this->index as $term => $data) {
            if (str_starts_with($term, $partial)) {
                $suggestions[] = [
                    'term' => $term,
                    'frequency' => count($data['items'])
                ];
            }
        }

        // Sort by frequency (descending)
        usort($suggestions, fn($a, $b) => $b['frequency'] <=> $a['frequency']);

        return array_slice(array_column($suggestions, 'term'), 0, $limit);
    }

    /**
     * Get search statistics
     */
    public function getStats(): array
    {
        return [
            'total_terms' => count($this->index),
            'total_items' => count($this->itemIndex),
            'index_built' => $this->indexBuilt,
            'avg_terms_per_item' => count($this->itemIndex) > 0
                ? array_sum(array_map(fn($item) => count($item['terms']), $this->itemIndex)) / count($this->itemIndex)
                : 0
        ];
    }

    /**
     * Clear search index
     */
    public function clearIndex(): void
    {
        $this->index = [];
        $this->itemIndex = [];
        $this->indexBuilt = false;
        $this->cache->forget('search_index');
    }

    /**
     * Index a single item
     */
    private function indexItem(Item $item): void
    {
        $itemId = $item->slug();
        $terms = [];

        // Index title with boost
        $titleTerms = $this->extractTerms($item->title());
        foreach ($titleTerms as $term) {
            $this->addToIndex($term, $itemId, 'title', $this->config['boost_title']);
            $terms[] = $term;
        }

        // Index content
        $contentTerms = $this->extractTerms($item->body());
        foreach ($contentTerms as $term) {
            $this->addToIndex($term, $itemId, 'content', 1.0);
            $terms[] = $term;
        }

        // Index excerpt
        if ($item->excerpt()) {
            $excerptTerms = $this->extractTerms($item->excerpt());
            foreach ($excerptTerms as $term) {
                $this->addToIndex($term, $itemId, 'excerpt', 1.2);
                $terms[] = $term;
            }
        }

        // Index tags with boost
        if ($item->tags()) {
            foreach ($item->tags() as $tag) {
                $tagTerms = $this->extractTerms($tag);
                foreach ($tagTerms as $term) {
                    $this->addToIndex($term, $itemId, 'tag', $this->config['boost_tags']);
                    $terms[] = $term;
                }
            }
        }

        // Store item reference
        $this->itemIndex[$itemId] = [
            'item' => $item,
            'terms' => array_unique($terms)
        ];
    }

    /**
     * Add term to search index
     */
    private function addToIndex(string $term, string $itemId, string $field, float $boost): void
    {
        if (!isset($this->index[$term])) {
            $this->index[$term] = ['items' => [], 'total_frequency' => 0];
        }

        if (!isset($this->index[$term]['items'][$itemId])) {
            $this->index[$term]['items'][$itemId] = [];
        }

        if (!isset($this->index[$term]['items'][$itemId][$field])) {
            $this->index[$term]['items'][$itemId][$field] = 0;
        }

        $this->index[$term]['items'][$itemId][$field]++;
        $this->index[$term]['total_frequency']++;
    }

    /**
     * Find matching items with scores
     */
    private function findMatches(array $terms, array $filters): array
    {
        $itemScores = [];
        $totalTerms = count($terms);

        foreach ($terms as $term) {
            if (!isset($this->index[$term])) {
                continue;
            }

            $termData = $this->index[$term];
            $idf = log(count($this->itemIndex) / count($termData['items']));

            foreach ($termData['items'] as $itemId => $fields) {
                if (!isset($this->itemIndex[$itemId])) {
                    continue;
                }

                $item = $this->itemIndex[$itemId]['item'];

                // Apply filters
                if (!$this->passesFilters($item, $filters)) {
                    continue;
                }

                if (!isset($itemScores[$itemId])) {
                    $itemScores[$itemId] = [
                        'item' => $item,
                        'score' => 0,
                        'matched_terms' => 0
                    ];
                }

                // Calculate TF-IDF score
                $tf = array_sum($fields);
                $score = $tf * $idf;

                // Apply field boosts
                foreach ($fields as $field => $frequency) {
                    $boost = match ($field) {
                        'title' => $this->config['boost_title'],
                        'tag' => $this->config['boost_tags'],
                        'excerpt' => 1.2,
                        default => 1.0
                    };
                    $score += $frequency * $boost;
                }

                $itemScores[$itemId]['score'] += $score;
                $itemScores[$itemId]['matched_terms']++;
            }
        }

        // Boost items that match more terms
        foreach ($itemScores as &$itemScore) {
            $termCoverage = $itemScore['matched_terms'] / $totalTerms;
            $itemScore['score'] *= (1 + $termCoverage);
        }

        return array_values($itemScores);
    }

    /**
     * Check if item passes filters
     */
    private function passesFilters(Item $item, array $filters): bool
    {
        foreach ($filters as $key => $value) {
            switch ($key) {
                case 'collection':
                    if ($item->meta('collection') !== $value) {
                        return false;
                    }
                    break;

                case 'status':
                    if ($item->status() !== $value) {
                        return false;
                    }
                    break;

                case 'tags':
                    $itemTags = $item->tags() ?? [];
                    $requiredTags = is_array($value) ? $value : [$value];
                    if (empty(array_intersect($itemTags, $requiredTags))) {
                        return false;
                    }
                    break;

                case 'date_from':
                    if ($item->date() && $item->date() < $value) {
                        return false;
                    }
                    break;

                case 'date_to':
                    if ($item->date() && $item->date() > $value) {
                        return false;
                    }
                    break;
            }
        }

        return true;
    }

    /**
     * Normalize search query
     */
    private function normalizeQuery(string $query): string
    {
        // Remove extra whitespace and convert to lowercase
        return trim(preg_replace('/\s+/', ' ', strtolower($query)));
    }

    /**
     * Extract search terms from text
     */
    private function extractTerms(string $text): array
    {
        // Remove HTML tags and normalize
        $text = strip_tags($text);
        $text = strtolower($text);

        // Extract words
        preg_match_all('/\b\w+\b/u', $text, $matches);
        $words = $matches[0];

        // Filter words
        $terms = [];
        foreach ($words as $word) {
            $len = strlen($word);

            // Skip if too short, too long, or is stop word
            if (
                $len < $this->config['min_word_length'] ||
                $len > $this->config['max_word_length'] ||
                in_array($word, $this->config['stop_words'])
            ) {
                continue;
            }

            $terms[] = $word;
        }

        return array_unique($terms);
    }
}
