<?php

namespace App\Events;

use InvalidArgumentException;

/**
 * Event dispatcher for extensibility and plugin architecture
 */
class EventDispatcher
{
    private array $listeners = [];
    private array $sortedListeners = [];
    private bool $sorted = false;

    /**
     * Add an event listener
     */
    public function listen(string $event, callable $listener, int $priority = 0): void
    {
        $this->listeners[$event][] = [
            'listener' => $listener,
            'priority' => $priority
        ];

        // Mark as unsorted so listeners get re-sorted next time
        unset($this->sortedListeners[$event]);
        $this->sorted = false;
    }

    /**
     * Add a one-time event listener
     */
    public function once(string $event, callable $listener, int $priority = 0): void
    {
        $onceListener = function (...$args) use ($event, $listener) {
            $this->forget($event, $listener);
            return call_user_func_array($listener, $args);
        };

        $this->listen($event, $onceListener, $priority);
    }

    /**
     * Dispatch an event
     */
    public function dispatch(string $event, mixed $payload = null): mixed
    {
        $listeners = $this->getListeners($event);

        foreach ($listeners as $listener) {
            $result = call_user_func($listener, $payload, $event);

            // If listener returns false, stop propagation
            if ($result === false) {
                break;
            }

            // If listener returns a value, update payload
            if ($result !== null) {
                $payload = $result;
            }
        }

        return $payload;
    }

    /**
     * Check if event has listeners
     */
    public function hasListeners(string $event): bool
    {
        return !empty($this->listeners[$event]);
    }

    /**
     * Get all listeners for an event
     */
    public function getListeners(string $event): array
    {
        if (!isset($this->listeners[$event])) {
            return [];
        }

        if (!isset($this->sortedListeners[$event])) {
            $this->sortListeners($event);
        }

        return $this->sortedListeners[$event];
    }

    /**
     * Remove a specific listener
     */
    public function forget(string $event, callable $listener): bool
    {
        if (!isset($this->listeners[$event])) {
            return false;
        }

        foreach ($this->listeners[$event] as $index => $item) {
            if ($item['listener'] === $listener) {
                unset($this->listeners[$event][$index]);
                unset($this->sortedListeners[$event]);
                return true;
            }
        }

        return false;
    }

    /**
     * Remove all listeners for an event
     */
    public function forgetAll(string $event): void
    {
        unset($this->listeners[$event], $this->sortedListeners[$event]);
    }

    /**
     * Clear all listeners
     */
    public function clear(): void
    {
        $this->listeners = [];
        $this->sortedListeners = [];
        $this->sorted = false;
    }

    /**
     * Get all registered events
     */
    public function getEvents(): array
    {
        return array_keys($this->listeners);
    }

    /**
     * Get listener count for an event
     */
    public function getListenerCount(string $event): int
    {
        return count($this->listeners[$event] ?? []);
    }

    /**
     * Sort listeners by priority (higher priority first)
     */
    private function sortListeners(string $event): void
    {
        if (!isset($this->listeners[$event])) {
            $this->sortedListeners[$event] = [];
            return;
        }

        // Sort by priority (higher first)
        $listeners = $this->listeners[$event];
        usort($listeners, function ($a, $b) {
            return $b['priority'] <=> $a['priority'];
        });

        // Extract just the callable listeners
        $this->sortedListeners[$event] = array_map(
            fn($item) => $item['listener'],
            $listeners
        );
    }

    /**
     * Subscribe multiple events with a single listener
     */
    public function subscribe(array $events, callable $listener, int $priority = 0): void
    {
        foreach ($events as $event) {
            $this->listen($event, $listener, $priority);
        }
    }

    /**
     * Create a filtered dispatcher that only handles specific events
     */
    public function filtered(array $allowedEvents): self
    {
        $filtered = new self();

        foreach ($allowedEvents as $event) {
            if (isset($this->listeners[$event])) {
                $filtered->listeners[$event] = $this->listeners[$event];
            }
        }

        return $filtered;
    }

    /**
     * Get statistics about the dispatcher
     */
    public function getStats(): array
    {
        $stats = [
            'total_events' => count($this->listeners),
            'total_listeners' => 0,
            'events' => []
        ];

        foreach ($this->listeners as $event => $listeners) {
            $count = count($listeners);
            $stats['total_listeners'] += $count;
            $stats['events'][$event] = $count;
        }

        return $stats;
    }
}
