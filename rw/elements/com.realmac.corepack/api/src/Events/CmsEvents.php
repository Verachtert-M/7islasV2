<?php

namespace App\Events;

use App\CMS\Item;
use App\ValueObjects\ParsedContent;

/**
 * CMS Events - Constants for event names
 */
class CmsEvents
{
    // Content parsing events
    public const CONTENT_PARSING = 'cms.content.parsing';
    public const CONTENT_PARSED = 'cms.content.parsed';

    // Item lifecycle events
    public const ITEM_CREATING = 'cms.item.creating';
    public const ITEM_CREATED = 'cms.item.created';
    public const ITEM_LOADING = 'cms.item.loading';
    public const ITEM_LOADED = 'cms.item.loaded';

    // Collection events
    public const COLLECTION_LOADING = 'cms.collection.loading';
    public const COLLECTION_LOADED = 'cms.collection.loaded';

    // Cache events
    public const CACHE_STORING = 'cms.cache.storing';
    public const CACHE_STORED = 'cms.cache.stored';
    public const CACHE_RETRIEVING = 'cms.cache.retrieving';
    public const CACHE_RETRIEVED = 'cms.cache.retrieved';
    public const CACHE_CLEARING = 'cms.cache.clearing';
    public const CACHE_CLEARED = 'cms.cache.cleared';

    // Search events
    public const SEARCH_STARTING = 'cms.search.starting';
    public const SEARCH_COMPLETED = 'cms.search.completed';

    // Error events
    public const ERROR_OCCURRED = 'cms.error.occurred';
}

/**
 * Base event class
 */
abstract class CmsEvent
{
    public function __construct(
        public readonly string $eventName,
        public readonly float $timestamp
    ) {}

    public static function create(string $eventName): static
    {
        return new static($eventName, microtime(true));
    }
}

/**
 * Content parsing event
 */
class ContentParsingEvent extends CmsEvent
{
    public function __construct(
        string $eventName,
        public readonly string $content,
        public readonly string $filepath,
        ?float $timestamp = null
    ) {
        parent::__construct($eventName, $timestamp ?? microtime(true));
    }

    public static function parsing(string $content, string $filepath): self
    {
        return new self(CmsEvents::CONTENT_PARSING, $content, $filepath);
    }
}

/**
 * Content parsed event
 */
class ContentParsedEvent extends CmsEvent
{
    public function __construct(
        string $eventName,
        public readonly ParsedContent $parsedContent,
        public readonly string $filepath,
        ?float $timestamp = null
    ) {
        parent::__construct($eventName, $timestamp ?? microtime(true));
    }

    public static function parsed(ParsedContent $parsedContent, string $filepath): self
    {
        return new self(CmsEvents::CONTENT_PARSED, $parsedContent, $filepath);
    }
}

/**
 * Item lifecycle events
 */
class ItemEvent extends CmsEvent
{
    public function __construct(
        string $eventName,
        public readonly ?Item $item,
        public readonly string $identifier,
        public readonly array $metadata = [],
        ?float $timestamp = null
    ) {
        parent::__construct($eventName, $timestamp ?? microtime(true));
    }

    public static function creating(string $identifier, array $metadata = []): self
    {
        return new self(CmsEvents::ITEM_CREATING, null, $identifier, $metadata);
    }

    public static function created(Item $item): self
    {
        return new self(CmsEvents::ITEM_CREATED, $item, $item->slug());
    }

    public static function loading(string $identifier): self
    {
        return new self(CmsEvents::ITEM_LOADING, null, $identifier);
    }

    public static function loaded(Item $item): self
    {
        return new self(CmsEvents::ITEM_LOADED, $item, $item->slug());
    }
}

/**
 * Collection events
 */
class CollectionEvent extends CmsEvent
{
    public function __construct(
        string $eventName,
        public readonly string $collection,
        public readonly array $items = [],
        public readonly array $filters = [],
        ?float $timestamp = null
    ) {
        parent::__construct($eventName, $timestamp ?? microtime(true));
    }

    public static function loading(string $collection, array $filters = []): self
    {
        return new self(CmsEvents::COLLECTION_LOADING, $collection, [], $filters);
    }

    public static function loaded(string $collection, array $items): self
    {
        return new self(CmsEvents::COLLECTION_LOADED, $collection, $items);
    }
}

/**
 * Cache events
 */
class CacheEvent extends CmsEvent
{
    public function __construct(
        string $eventName,
        public readonly string $key,
        public readonly mixed $value = null,
        public readonly int $ttl = 0,
        ?float $timestamp = null
    ) {
        parent::__construct($eventName, $timestamp ?? microtime(true));
    }

    public static function storing(string $key, mixed $value, int $ttl): self
    {
        return new self(CmsEvents::CACHE_STORING, $key, $value, $ttl);
    }

    public static function stored(string $key): self
    {
        return new self(CmsEvents::CACHE_STORED, $key);
    }

    public static function retrieving(string $key): self
    {
        return new self(CmsEvents::CACHE_RETRIEVING, $key);
    }

    public static function retrieved(string $key, mixed $value): self
    {
        return new self(CmsEvents::CACHE_RETRIEVED, $key, $value);
    }

    public static function clearing(string $key = ''): self
    {
        return new self(CmsEvents::CACHE_CLEARING, $key);
    }

    public static function cleared(string $key = ''): self
    {
        return new self(CmsEvents::CACHE_CLEARED, $key);
    }
}

/**
 * Search events
 */
class SearchEvent extends CmsEvent
{
    public function __construct(
        string $eventName,
        public readonly string $query,
        public readonly array $results = [],
        public readonly array $filters = [],
        public readonly float $duration = 0.0,
        ?float $timestamp = null
    ) {
        parent::__construct($eventName, $timestamp ?? microtime(true));
    }

    public static function starting(string $query, array $filters = []): self
    {
        return new self(CmsEvents::SEARCH_STARTING, $query, [], $filters);
    }

    public static function completed(string $query, array $results, float $duration, array $filters = []): self
    {
        return new self(CmsEvents::SEARCH_COMPLETED, $query, $results, $filters, $duration);
    }
}

/**
 * Error event
 */
class ErrorEvent extends CmsEvent
{
    public function __construct(
        public readonly \Throwable $exception,
        public readonly string $context = '',
        public readonly array $data = [],
        ?float $timestamp = null
    ) {
        parent::__construct(CmsEvents::ERROR_OCCURRED, $timestamp ?? microtime(true));
    }

    public static function occurred(\Throwable $exception, string $context = '', array $data = []): self
    {
        return new self($exception, $context, $data);
    }
}
