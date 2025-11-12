<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Events;

/**
 * Event Dispatcher
 *
 * Simple event system for database operations:
 * - Event registration and dispatching
 * - Multiple listeners per event
 * - Wildcard event patterns
 * - Event queuing
 * - Event statistics
 *
 * @package Infocyph\DBLayer\Events
 * @author Hasan
 */
class Events
{
    /**
     * Enable/disable event dispatching
     */
    private static bool $enabled = true;
    /**
     * Registered event listeners
     */
    private static array $listeners = [];

    /**
     * Event queue for deferred dispatch
     */
    private static array $queue = [];

    /**
     * Event statistics
     */
    private static array $stats = [
        'dispatched' => 0,
        'queued' => 0,
    ];

    /**
     * Clear queued events without dispatching
     */
    public static function clearQueue(): void
    {
        self::$queue = [];
    }

    /**
     * Disable event dispatching
     */
    public static function disable(): void
    {
        self::$enabled = false;
    }

    /**
     * Dispatch an event
     */
    public static function dispatch(string $event, array $payload = []): void
    {
        if (!self::$enabled) {
            return;
        }

        self::$stats['dispatched']++;

        // Dispatch to exact listeners
        if (isset(self::$listeners[$event])) {
            foreach (self::$listeners[$event] as $listener) {
                $listener(...$payload);
            }
        }

        // Dispatch to wildcard listeners
        foreach (self::$listeners as $pattern => $listeners) {
            if (self::matchesPattern($event, $pattern)) {
                foreach ($listeners as $listener) {
                    $listener(...$payload);
                }
            }
        }
    }

    /**
     * Enable event dispatching
     */
    public static function enable(): void
    {
        self::$enabled = true;
    }

    /**
     * Flush queued events
     */
    public static function flush(): void
    {
        foreach (self::$queue as $item) {
            self::dispatch($item['event'], $item['payload']);
        }

        self::$queue = [];
    }

    /**
     * Remove event listener
     */
    public static function forget(string $event, ?callable $listener = null): void
    {
        if ($listener === null) {
            unset(self::$listeners[$event]);
            return;
        }

        if (!isset(self::$listeners[$event])) {
            return;
        }

        foreach (self::$listeners[$event] as $key => $registered) {
            if ($registered === $listener) {
                unset(self::$listeners[$event][$key]);
            }
        }

        self::$listeners[$event] = array_values(self::$listeners[$event]);
    }

    /**
     * Remove all event listeners
     */
    public static function forgetAll(): void
    {
        self::$listeners = [];
    }

    /**
     * Get all registered events
     */
    public static function getEvents(): array
    {
        return array_keys(self::$listeners);
    }

    /**
     * Get registered listeners for event
     */
    public static function getListeners(string $event): array
    {
        return self::$listeners[$event] ?? [];
    }

    /**
     * Get event statistics
     */
    public static function getStats(): array
    {
        return array_merge(self::$stats, [
            'registered_events' => count(self::$listeners),
            'queued_events' => count(self::$queue),
            'total_listeners' => array_sum(array_map('count', self::$listeners)),
        ]);
    }

    /**
     * Check if event has listeners
     */
    public static function hasListeners(string $event): bool
    {
        return !empty(self::$listeners[$event]);
    }

    /**
     * Check if events are enabled
     */
    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    /**
     * Register an event listener
     */
    public static function listen(string $event, callable $listener): void
    {
        if (!isset(self::$listeners[$event])) {
            self::$listeners[$event] = [];
        }

        self::$listeners[$event][] = $listener;
    }

    /**
     * Queue an event for later dispatch
     */
    public static function queue(string $event, array $payload = []): void
    {
        self::$queue[] = [
            'event' => $event,
            'payload' => $payload,
            'time' => microtime(true),
        ];

        self::$stats['queued']++;
    }

    /**
     * Reset statistics
     */
    public static function resetStats(): void
    {
        self::$stats = [
            'dispatched' => 0,
            'queued' => 0,
        ];
    }

    /**
     * Subscribe to multiple events
     */
    public static function subscribe(EventSubscriber $subscriber): void
    {
        foreach ($subscriber->subscribe() as $event => $listener) {
            if (is_string($listener)) {
                $listener = [$subscriber, $listener];
            }

            self::listen($event, $listener);
        }
    }

    /**
     * Check if event matches pattern
     */
    private static function matchesPattern(string $event, string $pattern): bool
    {
        // Exact match
        if ($event === $pattern) {
            return true;
        }

        // No wildcards
        if (!str_contains($pattern, '*')) {
            return false;
        }

        // Convert pattern to regex
        $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/';

        return preg_match($regex, $event) === 1;
    }
}