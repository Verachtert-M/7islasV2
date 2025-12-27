<?php

namespace App\Services;

use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Extension\SandboxExtension;
use Twig\Sandbox\SecurityPolicy;
use Twig\Error\SyntaxError;
use Twig\Error\RuntimeError;
use Twig\Error\LoaderError;

class TemplateRenderingService
{
    private Environment $twig;
    private array $templateCache = [];
    private array $renderCache = [];
    private int $maxCacheSize = 1000;

    public function __construct()
    {
        $this->initializeTwig();
    }

    /**
     * Initialize Twig environment with security restrictions
     */
    private function initializeTwig(): void
    {
        $loader = new ArrayLoader([]);
        $this->twig = new Environment($loader, [
            'cache' => false,
            'debug' => false,
            'strict_variables' => false, // Allow undefined variables to return null instead of fatal error
            'autoescape' => false
        ]);

        // Add custom truncate filter IMMEDIATELY after creating environment
        $truncateFilter = new \Twig\TwigFilter('truncate', function ($string, $length = 100, $ellipsis = '…') {
            if (strlen($string) <= $length) {
                return $string;
            }
            return substr($string, 0, $length) . $ellipsis;
        });
        $this->twig->addFilter($truncateFilter);

        // Add IntlExtension if PHP intl extension is available (for format_datetime, etc.)
        if (extension_loaded('intl')) {
            $this->twig->addExtension(new \Twig\Extra\Intl\IntlExtension());
        }

        // Set up security sandbox
        $this->setupSandbox();
    }

    /**
     * Set up Twig sandbox with security restrictions
     */
    private function setupSandbox(): void
    {
        $allowedTags = [
            'if',
            'endif',
            'else',
            'elseif',
            'for',
            'endfor',
            'in',
            'set',
            'endset',
            'block',
            'endblock',
            'macro',
            'endmacro',
            'import',
            'from',
            'filter',
            'endfilter',
            'spaceless',
            'endspaceless',
            'with',
            'endwith',
            'verbatim',
            'endverbatim'
        ];

        $allowedFilters = [
            'abs',
            'batch',
            'capitalize',
            'convert_encoding',
            'country_name',
            'currency_name',
            'currency_symbol',
            'date',
            'date_modify',
            'default',
            'escape',
            'first',
            'format',
            'format_datetime',
            'join',
            'json_encode',
            'keys',
            'language_name',
            'last',
            'length',
            'locale_name',
            'lower',
            'map',
            'merge',
            'nl2br',
            'number_format',
            'raw',
            'reduce',
            'replace',
            'reverse',
            'round',
            'slice',
            'sort',
            'split',
            'striptags',
            'title',
            'trim',
            'truncate',
            'upper',
            'url_encode'
        ];

        $allowedMethods = [
            'keys',
            'values',
            'items',
            'count',
            'empty'
        ];

        $allowedProperties = [];

        $allowedFunctions = [
            'attribute',
            'block',
            'constant',
            'cycle',
            'date',
            'dump',
            'include',
            'max',
            'min',
            'parent',
            'random',
            'range',
            'source',
            'template_from_string'
        ];

        // Remove potentially dangerous functions
        $allowedFunctions = array_diff($allowedFunctions, [
            'include',
            'source',
            'template_from_string'
        ]);

        $policy = new SecurityPolicy(
            $allowedTags,
            $allowedFilters,
            $allowedMethods,
            $allowedProperties,
            $allowedFunctions
        );

        $sandbox = new SandboxExtension($policy);
        $this->twig->addExtension($sandbox);
    }

    /**
     * Render template for multiple items with caching
     */
    public function renderItemsWithTemplate(array $items, string $template): array
    {
        if (empty($template)) {
            return array_map(fn($item) => ['item' => $item, 'rendered_template' => null], $items);
        }

        $templateHash = md5($template);

        // Validate and compile template once
        if (!isset($this->templateCache[$templateHash])) {
            try {
                $this->validateTemplate($template);
                $this->templateCache[$templateHash] = $template;
            } catch (\Exception $e) {
                throw new \InvalidArgumentException("Template error: " . $e->getMessage());
            }
        }

        $results = [];
        foreach ($items as $item) {
            $itemData = $this->prepareItemData($item);
            $cacheKey = $this->generateCacheKey($templateHash, $itemData);

            // Check render cache
            if (isset($this->renderCache[$cacheKey])) {
                $renderedTemplate = $this->renderCache[$cacheKey];
            } else {
                try {
                    $renderedTemplate = $this->renderTemplate($template, $itemData);

                    // Cache the rendered result
                    $this->cacheRenderedTemplate($cacheKey, $renderedTemplate);
                } catch (\Exception $e) {
                    throw new \RuntimeException("Template rendering error: " . $e->getMessage());
                }
            }

            $results[] = [
                'item' => $itemData,
                'rendered_template' => $renderedTemplate
            ];
        }

        return $results;
    }

    /**
     * Validate template syntax
     */
    private function validateTemplate(string $template): void
    {
        try {
            $this->twig->parse($this->twig->tokenize(new \Twig\Source($template, 'template')));
        } catch (SyntaxError $e) {
            throw new \InvalidArgumentException("Template syntax error on line {$e->getTemplateLine()}: {$e->getMessage()}");
        }
    }

    /**
     * Render template with item data
     */
    private function renderTemplate(string $template, array $itemData): string
    {
        try {
            $templateObj = $this->twig->createTemplate($template);
            return $templateObj->render(['item' => $itemData]);
        } catch (RuntimeError $e) {
            throw new \RuntimeException("Template runtime error: {$e->getMessage()}");
        } catch (LoaderError $e) {
            throw new \RuntimeException("Template loader error: {$e->getMessage()}");
        }
    }

    /**
     * Prepare item data for template rendering
     */
    private function prepareItemData($item): array
    {
        if (is_array($item)) {
            $itemArray = $item;
        } elseif (is_object($item) && method_exists($item, 'toArray')) {
            $itemArray = $item->toArray();
        } elseif (is_object($item)) {
            $itemArray = get_object_vars($item);
        } else {
            return ['value' => $item];
        }

        return $itemArray;
    }

    /**
     * Generate cache key for rendered template
     */
    private function generateCacheKey(string $templateHash, array $itemData): string
    {
        // Create a stable hash of the item data
        $itemHash = md5(json_encode($itemData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $templateHash . '_' . $itemHash;
    }

    /**
     * Cache rendered template with size limit
     */
    private function cacheRenderedTemplate(string $cacheKey, string $renderedTemplate): void
    {
        // Implement LRU cache by removing oldest entries when cache is full
        if (count($this->renderCache) >= $this->maxCacheSize) {
            // Remove first (oldest) entry
            $firstKey = array_key_first($this->renderCache);
            unset($this->renderCache[$firstKey]);
        }

        $this->renderCache[$cacheKey] = $renderedTemplate;
    }

    /**
     * Clear all caches
     */
    public function clearCache(): void
    {
        $this->templateCache = [];
        $this->renderCache = [];
    }

    /**
     * Get cache statistics
     */
    public function getCacheStats(): array
    {
        return [
            'template_cache_size' => count($this->templateCache),
            'render_cache_size' => count($this->renderCache),
            'max_cache_size' => $this->maxCacheSize
        ];
    }

    /**
     * Set maximum cache size
     */
    public function setMaxCacheSize(int $size): void
    {
        $this->maxCacheSize = max(1, $size);
    }
}
