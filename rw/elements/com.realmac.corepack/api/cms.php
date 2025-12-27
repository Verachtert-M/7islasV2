<?php

/**
 * CMS API Bootstrap
 * Include this file to access the CMS API in templates
 */

// Load Composer autoloader
require_once __DIR__ . '/vendor/autoload.php';

// Load CMS classes
require_once __DIR__ . '/src/CMS/CMS.php';
require_once __DIR__ . '/src/CMS/CollectionBuilder.php';
require_once __DIR__ . '/src/CMS/CMSRepository.php';
require_once __DIR__ . '/src/CMS/MarkdownParser.php';
require_once __DIR__ . '/src/CMS/SearchService.php';
require_once __DIR__ . '/src/CMS/Item.php';
require_once __DIR__ . '/src/ValueObjects/ItemMetadata.php';
require_once __DIR__ . '/src/Services/PathResolverService.php';

use App\CMS\CMS;
use App\CMS\CollectionBuilder;

/**
 * Global CMS factory function for template usage
 * Creates or returns a cached CMS instance
 * 
 * @param array $options Additional CMS options
 * @return CMS
 */
if (!function_exists('cms')) {
    function cms(array $options = []): CMS
    {
        return CMS::make($options);
    }
}

/**
 * Quick collection accessor
 */
if (!function_exists('collection')) {
    function collection(string $collectionPath, array $options = []): CollectionBuilder
    {
        return cms($options)->collection($collectionPath);
    }
}

/**
 * Quick item accessor
 */
if (!function_exists('item')) {
    function item(string $path, ?string $slug = null, array $options = []): ?object
    {
        return cms()->item($path, $slug, $options);
    }
}

/**
 * Quick search accessor
 */
if (!function_exists('search')) {
    function search(string $query, ?string $collectionPath = null, array $options = []): array
    {
        return cms()->search($query, $collectionPath, $options);
    }
}
