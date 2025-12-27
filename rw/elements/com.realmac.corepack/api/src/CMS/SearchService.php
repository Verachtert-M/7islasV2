<?php

namespace App\CMS;

/**
 * Enhanced Search Service with advanced features
 */
class SearchService
{
    private array $searchIndex = [];
    private array $searchCache = [];
    private array $stopWords = ['the', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'is', 'are', 'was', 'were', 'a', 'an'];
    private array $analytics = [];

    /**
     * Enhanced search with indexing, fuzzy matching, and advanced scoring
     */
    public function search(string $query, array $items, array $options = []): array
    {
        $this->logSearch($query);

        // Check cache first
        $cacheKey = $this->getCacheKey($query, $options);
        if (isset($this->searchCache[$cacheKey])) {
            return $this->searchCache[$cacheKey];
        }

        $startTime = microtime(true);

        // Build or refresh search index if needed
        $this->buildSearchIndex($items);

        $processedQuery = $this->preprocessQuery($query);
        $results = [];

        // Handle different search types
        if ($this->isPhrase($query)) {
            $results = $this->phraseSearch($processedQuery, $items);
        } elseif ($this->hasBooleanOperators($query)) {
            $results = $this->booleanSearch($processedQuery, $items);
        } else {
            $results = $this->standardSearch($processedQuery, $items, $options);
        }

        // Apply post-processing
        $results = $this->applyFilters($results, $options);
        $results = $this->rankResults($results, $processedQuery);

        $duration = microtime(true) - $startTime;
        $this->logSearchComplete($query, count($results), $duration);

        // Cache results
        $this->searchCache[$cacheKey] = $results;

        return $results;
    }

    /**
     * Advanced fuzzy search with Levenshtein distance
     */
    public function fuzzySearch(string $query, array $items, int $maxDistance = 2): array
    {
        $results = [];
        $query = strtolower(trim($query));

        foreach ($items as $item) {
            $score = $this->calculateFuzzyScore($item, $query, $maxDistance);

            if ($score > 0) {
                $results[] = [
                    'item' => $item,
                    'score' => $score,
                    'snippet' => $this->generateEnhancedSnippet($item, $query)
                ];
            }
        }

        // Sort by score descending
        usort($results, fn($a, $b) => $b['score'] <=> $a['score']);

        return array_column($results, 'item');
    }

    /**
     * Phrase search for exact phrases
     */
    public function phraseSearch(string $phrase, array $items): array
    {
        $results = [];
        $phrase = trim($phrase, '"\'');

        foreach ($items as $item) {
            $score = $this->calculatePhraseScore($item, $phrase);

            if ($score > 0) {
                $results[] = [
                    'item' => $item,
                    'score' => $score,
                    'snippet' => $this->generateEnhancedSnippet($item, $phrase)
                ];
            }
        }

        usort($results, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_column($results, 'item');
    }

    /**
     * Boolean search with AND, OR, NOT operators
     */
    public function booleanSearch(string $query, array $items): array
    {
        $tokens = $this->parseBooleanQuery($query);
        $results = [];

        foreach ($items as $item) {
            if ($this->evaluateBooleanExpression($tokens, $item)) {
                $score = $this->calculateSearchScore($item, $query);
                $results[] = [
                    'item' => $item,
                    'score' => $score,
                    'snippet' => $this->generateEnhancedSnippet($item, $query)
                ];
            }
        }

        usort($results, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_column($results, 'item');
    }

    /**
     * Faceted search with filters
     */
    public function facetedSearch(string $query, array $items, array $facets = []): array
    {
        $results = $this->search($query, $items);

        foreach ($facets as $facetName => $facetValue) {
            $results = array_filter($results, function ($item) use ($facetName, $facetValue) {
                switch ($facetName) {
                    case 'author':
                        return stripos($item->author() ?? '', $facetValue) !== false;
                    case 'date_range':
                        return $this->isInDateRange($item->date(), $facetValue);
                    case 'tags':
                        return in_array($facetValue, $item->tags());
                    case 'categories':
                        return in_array($facetValue, $item->categories());
                    case 'status':
                        return $item->status() === $facetValue;
                    case 'featured':
                        return $item->featured() === (bool)$facetValue;
                    default:
                        return true;
                }
            });
        }

        return $results;
    }

    /**
     * Search suggestions and autocomplete
     */
    public function getSuggestions(string $partialQuery, array $items, int $limit = 5): array
    {
        $suggestions = [];
        $partialQuery = strtolower(trim($partialQuery));

        if (strlen($partialQuery) < 2) {
            return $suggestions;
        }

        // Collect potential suggestions from titles, tags, and content
        foreach ($items as $item) {
            // Title words
            $titleWords = explode(' ', strtolower($item->title()));
            foreach ($titleWords as $word) {
                if (str_starts_with($word, $partialQuery) && strlen($word) > strlen($partialQuery)) {
                    $suggestions[$word] = ($suggestions[$word] ?? 0) + 10;
                }
            }

            // Tags
            foreach ($item->tags() as $tag) {
                $tag = strtolower($tag);
                if (str_starts_with($tag, $partialQuery) && strlen($tag) > strlen($partialQuery)) {
                    $suggestions[$tag] = ($suggestions[$tag] ?? 0) + 5;
                }
            }
        }

        // Sort by frequency and return top suggestions
        arsort($suggestions);
        return array_slice(array_keys($suggestions), 0, $limit);
    }

    /**
     * Build search index for faster searching
     */
    private function buildSearchIndex(array $items): void
    {
        if (!empty($this->searchIndex)) {
            return; // Index already built
        }

        foreach ($items as $index => $item) {
            $words = $this->extractWords($item);

            foreach ($words as $word => $weight) {
                if (!isset($this->searchIndex[$word])) {
                    $this->searchIndex[$word] = [];
                }
                $this->searchIndex[$word][$index] = $weight;
            }
        }
    }

    /**
     * Extract and weight words from an item
     */
    private function extractWords(Item $item): array
    {
        $words = [];

        // Title words (highest weight)
        $titleWords = $this->tokenize($item->title());
        foreach ($titleWords as $word) {
            $words[$word] = ($words[$word] ?? 0) + 10;
        }

        // Author words
        if ($item->author()) {
            $author = $item->author();
            // Handle both string and array (enriched) authors
            $authorText = is_array($author) ? ($author['name'] ?? '') : (string)$author;
            if ($authorText) {
                $authorWords = $this->tokenize($authorText);
                foreach ($authorWords as $word) {
                    $words[$word] = ($words[$word] ?? 0) + 8;
                }
            }
        }

        // Tag words
        foreach ($item->tags() as $tag) {
            // Handle both string and array (enriched) tags
            $tagText = is_array($tag) ? ($tag['name'] ?? '') : (string)$tag;
            if ($tagText) {
                $tagWords = $this->tokenize($tagText);
                foreach ($tagWords as $word) {
                    $words[$word] = ($words[$word] ?? 0) + 6;
                }
            }
        }

        // Category words
        foreach ($item->categories() as $category) {
            // Handle both string and array categories
            $categoryText = is_array($category) ? ($category['name'] ?? '') : (string)$category;
            if ($categoryText) {
                $categoryWords = $this->tokenize($categoryText);
                foreach ($categoryWords as $word) {
                    $words[$word] = ($words[$word] ?? 0) + 6;
                }
            }
        }

        // Excerpt words
        if ($item->excerpt()) {
            $excerptWords = $this->tokenize($item->excerpt());
            foreach ($excerptWords as $word) {
                $words[$word] = ($words[$word] ?? 0) + 4;
            }
        }

        // Body content words (lowest weight)
        $bodyWords = $this->tokenize($item->rawBody() ?? '');
        foreach ($bodyWords as $word) {
            $words[$word] = ($words[$word] ?? 0) + 1;
        }

        return $words;
    }

    /**
     * Tokenize text into searchable words
     */
    private function tokenize(string $text): array
    {
        $text = strtolower(strip_tags($text));
        $text = preg_replace('/[^a-z0-9\s]/', ' ', $text);
        $words = array_filter(explode(' ', $text));

        // Remove stop words
        $words = array_diff($words, $this->stopWords);

        // Remove short words
        $words = array_filter($words, fn($word) => strlen($word) >= 2);

        return array_values($words);
    }

    /**
     * Enhanced scoring algorithm with multiple factors
     */
    private function calculateSearchScore(Item $item, string $query): int
    {
        $score = 0;
        $queryWords = $this->tokenize($query);

        foreach ($queryWords as $word) {
            // Exact matches in different fields
            if (stripos($item->title(), $word) !== false) {
                $score += $this->isExactWordMatch($item->title(), $word) ? 15 : 10;
            }

            if ($item->author()) {
                $author = $item->author();
                $authorText = is_array($author) ? ($author['name'] ?? '') : (string)$author;
                if ($authorText && stripos($authorText, $word) !== false) {
                    $score += $this->isExactWordMatch($authorText, $word) ? 12 : 8;
                }
            }

            if ($item->excerpt() && stripos($item->excerpt(), $word) !== false) {
                $score += $this->isExactWordMatch($item->excerpt(), $word) ? 8 : 6;
            }

            foreach ($item->tags() as $tag) {
                // Handle both string and array (enriched) tags
                $tagText = is_array($tag) ? ($tag['name'] ?? '') : (string)$tag;
                if ($tagText && stripos($tagText, $word) !== false) {
                    $score += $this->isExactWordMatch($tagText, $word) ? 8 : 4;
                }
            }

            foreach ($item->categories() as $category) {
                // Handle both string and array categories
                $categoryText = is_array($category) ? ($category['name'] ?? '') : (string)$category;
                if ($categoryText && stripos($categoryText, $word) !== false) {
                    $score += $this->isExactWordMatch($categoryText, $word) ? 8 : 4;
                }
            }

            if (stripos($item->rawBody() ?? '', $word) !== false) {
                $score += 2;
            }
        }

        // Boost for newer content
        $score += $this->calculateRecencyBoost($item);

        // Boost for featured content
        if ($item->featured()) {
            $score += 5;
        }

        return $score;
    }

    /**
     * Calculate fuzzy score with Levenshtein distance
     */
    private function calculateFuzzyScore(Item $item, string $query, int $maxDistance): int
    {
        $score = 0;
        $words = $this->extractWords($item);

        foreach ($words as $word => $weight) {
            $distance = levenshtein($query, $word);
            if ($distance <= $maxDistance) {
                $fuzzyScore = $weight * (($maxDistance - $distance + 1) / ($maxDistance + 1));
                $score += (int)$fuzzyScore;
            }
        }

        return $score;
    }

    /**
     * Calculate phrase score for exact phrase matching
     */
    private function calculatePhraseScore(Item $item, string $phrase): int
    {
        $score = 0;

        if (stripos($item->title(), $phrase) !== false) {
            $score += 20;
        }

        if ($item->excerpt() && stripos($item->excerpt(), $phrase) !== false) {
            $score += 10;
        }

        if (stripos($item->rawBody() ?? '', $phrase) !== false) {
            $score += 5;
        }

        return $score;
    }

    /**
     * Generate enhanced snippet with better highlighting
     */
    private function generateEnhancedSnippet(Item $item, string $query, int $length = 200): string
    {
        $text = $item->excerpt() ?? strip_tags($item->body());
        $queryWords = $this->tokenize($query);

        // Find the best position to extract snippet
        $bestPosition = $this->findBestSnippetPosition($text, $queryWords);

        $start = max(0, $bestPosition - $length / 2);
        $snippet = substr($text, $start, $length);

        // Highlight all query words
        foreach ($queryWords as $word) {
            $snippet = preg_replace('/\b' . preg_quote($word, '/') . '\b/i', '<mark>$0</mark>', $snippet);
        }

        return ($start > 0 ? '...' : '') . $snippet . (strlen($text) > $start + $length ? '...' : '');
    }

    /**
     * Check if query is a phrase (enclosed in quotes)
     */
    private function isPhrase(string $query): bool
    {
        return (str_starts_with($query, '"') && str_ends_with($query, '"')) ||
            (str_starts_with($query, "'") && str_ends_with($query, "'"));
    }

    /**
     * Check if query has boolean operators
     */
    private function hasBooleanOperators(string $query): bool
    {
        return preg_match('/\b(AND|OR|NOT)\b/i', $query);
    }

    /**
     * Preprocess query for better matching
     */
    private function preprocessQuery(string $query): string
    {
        $query = trim($query);

        // Handle wildcards
        $query = str_replace(['*', '?'], ['%', '_'], $query);

        return $query;
    }

    /**
     * Standard search implementation
     */
    private function standardSearch(string $query, array $items, array $options): array
    {
        $results = [];
        $enableFuzzy = $options['fuzzy'] ?? false;

        foreach ($items as $item) {
            $score = $this->calculateSearchScore($item, $query);

            // Add fuzzy matching if enabled
            if ($enableFuzzy && $score === 0) {
                $score = $this->calculateFuzzyScore($item, $query, 2);
            }

            if ($score > 0) {
                $results[] = [
                    'item' => $item,
                    'score' => $score,
                    'snippet' => $this->generateEnhancedSnippet($item, $query)
                ];
            }
        }

        return $results;
    }

    /**
     * Additional helper methods for logging and caching
     */
    private function logSearch(string $query): void
    {
        $this->analytics[] = [
            'query' => $query,
            'timestamp' => time(),
            'type' => 'search_start'
        ];
    }

    private function logSearchComplete(string $query, int $resultCount, float $duration): void
    {
        $this->analytics[] = [
            'query' => $query,
            'results' => $resultCount,
            'duration' => $duration,
            'timestamp' => time(),
            'type' => 'search_complete'
        ];
    }

    private function getCacheKey(string $query, array $options): string
    {
        return md5($query . serialize($options));
    }

    private function isExactWordMatch(string $text, string $word): bool
    {
        return preg_match('/\b' . preg_quote($word, '/') . '\b/i', $text);
    }

    private function calculateRecencyBoost(Item $item): int
    {
        $itemDate = strtotime($item->date());
        $daysSincePublished = (time() - $itemDate) / (60 * 60 * 24);

        if ($daysSincePublished < 7) return 3;
        if ($daysSincePublished < 30) return 2;
        if ($daysSincePublished < 90) return 1;

        return 0;
    }

    private function findBestSnippetPosition(string $text, array $queryWords): int
    {
        $bestPosition = 0;
        $maxMatches = 0;

        for ($i = 0; $i < strlen($text) - 200; $i += 50) {
            $segment = substr($text, $i, 200);
            $matches = 0;

            foreach ($queryWords as $word) {
                $matches += substr_count(strtolower($segment), strtolower($word));
            }

            if ($matches > $maxMatches) {
                $maxMatches = $matches;
                $bestPosition = $i;
            }
        }

        return $bestPosition;
    }

    // Placeholder methods for complex features
    private function parseBooleanQuery(string $query): array
    {
        // Simplified boolean parsing - would need more sophisticated implementation
        return explode(' ', $query);
    }

    private function evaluateBooleanExpression(array $tokens, Item $item): bool
    {
        // Simplified boolean evaluation - would need proper parser
        return true;
    }

    private function applyFilters(array $results, array $options): array
    {
        return $results;
    }

    private function rankResults(array $results, string $query): array
    {
        usort($results, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_column($results, 'item');
    }

    private function isInDateRange(string $date, array $range): bool
    {
        $itemTime = strtotime($date);
        $startTime = strtotime($range['start'] ?? '1970-01-01');
        $endTime = strtotime($range['end'] ?? '2099-12-31');

        return $itemTime >= $startTime && $itemTime <= $endTime;
    }

    /**
     * Get search analytics
     */
    public function getAnalytics(): array
    {
        return $this->analytics;
    }

    /**
     * Find related items based on criteria (backward compatibility)
     */
    public function findRelated(
        Item $item,
        array $allItems,
        array $criteria = ['tags'],
        int $limit = 5
    ): array {
        $scored = [];

        foreach ($allItems as $candidate) {
            $result = $this->calculateSimilarityScore($item, $candidate, $criteria);
            $score = $result['score'];
            $matchedCriteria = $result['matched'];

            // If multiple criteria are specified, item must match ALL of them
            if (count($criteria) > 1) {
                // Check if all criteria were matched
                $allMatched = count($matchedCriteria) === count($criteria);
                if ($allMatched && $score > 0) {
                    $scored[] = [
                        'item' => $candidate,
                        'score' => $score
                    ];
                }
            } else {
                // Single criterion: just check if score > 0
                if ($score > 0) {
                    $scored[] = [
                        'item' => $candidate,
                        'score' => $score
                    ];
                }
            }
        }

        // Sort by score descending
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        // Return only items that match, up to the limit
        return array_slice(array_column($scored, 'item'), 0, $limit);
    }

    /**
     * Calculate similarity score between two items
     * Returns array with 'score' and 'matched' criteria
     */
    private function calculateSimilarityScore(Item $item, Item $candidate, array $criteria): array
    {
        $score = 0;
        $matchedCriteria = [];

        foreach ($criteria as $criterion) {
            $criterionScore = 0;

            switch ($criterion) {
                case 'tags':
                    $criterionScore = $this->calculateArraySimilarity($item->tags(), $candidate->tags(), 3);
                    break;

                case 'categories':
                    $criterionScore = $this->calculateArraySimilarity($item->categories(), $candidate->categories(), 3);
                    break;

                case 'author':
                    // Support both single author (backward compatibility) and multiple authors
                    $itemAuthors = $this->getItemAuthors($item);
                    $candidateAuthors = $this->getItemAuthors($candidate);

                    // Debug logging
                    error_log(sprintf(
                        'Author comparison - Item: %s | Candidate: %s',
                        json_encode($itemAuthors),
                        json_encode($candidateAuthors)
                    ));

                    if (!empty($itemAuthors) && !empty($candidateAuthors)) {
                        // Calculate similarity like tags, with higher weight (5 points per matching author)
                        $criterionScore = $this->calculateArraySimilarity($itemAuthors, $candidateAuthors, 5);

                        error_log(sprintf('Author criterion score: %d', $criterionScore));
                    }
                    break;

                case 'title':
                    $criterionScore = $this->calculateTitleSimilarity($item->title(), $candidate->title());
                    break;

                default:
                    // Handle custom metadata fields
                    $itemValue = $item->meta($criterion);
                    $candidateValue = $candidate->meta($criterion);
                    if ($itemValue && $candidateValue && $itemValue === $candidateValue) {
                        $criterionScore = 2;
                    }
                    break;
            }

            $score += $criterionScore;

            // Track which criteria actually matched
            if ($criterionScore > 0) {
                $matchedCriteria[] = $criterion;
            }
        }

        // Debug logging
        if (!empty($criteria)) {
            error_log(sprintf(
                'Item %s vs Candidate %s - Score: %d, Matched: %s, Required: %s',
                $item->slug(),
                $candidate->slug(),
                $score,
                json_encode($matchedCriteria),
                json_encode($criteria)
            ));
        }

        return [
            'score' => $score,
            'matched' => $matchedCriteria
        ];
    }

    /**
     * Calculate similarity between two arrays (e.g., tags, categories)
     */
    private function calculateArraySimilarity(array $array1, array $array2, int $weight = 1): int
    {
        // Normalize arrays to handle both scalar values and enriched objects
        $normalized1 = $this->normalizeArrayForComparison($array1);
        $normalized2 = $this->normalizeArrayForComparison($array2);

        $intersection = array_intersect($normalized1, $normalized2);
        return count($intersection) * $weight;
    }

    /**
     * Normalize array elements for comparison
     * Converts enriched objects/arrays to comparable scalar values
     */
    private function normalizeArrayForComparison(array $array): array
    {
        return array_map(function ($item) {
            $normalized = '';

            // If item is an array (enriched object), extract the name or slug for comparison
            if (is_array($item)) {
                $normalized = $item['name'] ?? $item['slug'] ?? $item['title'] ?? (string)$item;
            } else {
                // If it's already a scalar value, use it directly
                $normalized = (string)$item;
            }

            // Normalize: trim whitespace and convert to lowercase for case-insensitive comparison
            return mb_strtolower(trim($normalized));
        }, $array);
    }

    /**
     * Calculate title similarity using basic string comparison
     */
    private function calculateTitleSimilarity(string $title1, string $title2): int
    {
        // Simple word-based similarity
        $words1 = array_map('strtolower', explode(' ', $title1));
        $words2 = array_map('strtolower', explode(' ', $title2));

        $intersection = array_intersect($words1, $words2);
        return count($intersection);
    }

    /**
     * Get authors from an item (supports both single and multiple authors)
     */
    private function getItemAuthors(Item $item): array
    {
        // Try to get enriched authors first (supports multiple authors)
        if (method_exists($item, 'getEnrichedAuthors')) {
            $enrichedAuthors = $item->getEnrichedAuthors();
            if (!empty($enrichedAuthors)) {
                return $enrichedAuthors;
            }
        }

        // Fallback to single author for backward compatibility
        $author = $item->author();
        if ($author) {
            return [$author];
        }

        return [];
    }

    /**
     * Clear search cache
     */
    public function clearCache(): void
    {
        $this->searchCache = [];
        $this->searchIndex = [];
    }
}
