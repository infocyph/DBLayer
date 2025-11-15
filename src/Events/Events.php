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
 * This is intentionally minimal and static; higher-level code
 * can wrap it in an instance-based dispatcher if needed.
 *
 * @package Infocyph\DBLayer\Events
 * @author Hasan
 */
final class Events
{
    /**
     * Enable/disable event dispatching globally.
     */
    private static bool $enabled = true;

    /**
     * Registered event listeners.
     *
     * @var array<string, array<int, callable>>
     */
    private static array $listeners = [];

    /**
     * Event queue for deferred dispatch.
     *
     * @var array<int, array{event:string,payload:array,time:float}>
     */
    private static array $queue = [];

    /**
     * Event statistics.
     *
     * @var array{dispatched:int,queued:int}
     */
    private static array $stats = [
      'dispatched' => 0,
      'queued'     => 0,
    ];

    /**
     * Clear queued events without dispatching.
     */
    public static function clearQueue(): void
    {
        self::$queue = [];
    }

    /**
     * Disable event dispatching.
     */
    public static function disable(): void
    {
        self::$enabled = false;
    }

    /**
     * Enable event dispatching.
     */
    public static function enable(): void
    {
        self::$enabled = true;
    }

    /**
     * Dispatch an event.
     *
     * @param string $event   Event name (class name, "db.query.executed", etc.)
     * @param array  $payload Arguments passed to listeners.
     */
    public static function dispatch(string $event, array $payload = []): void
    {
        if (!self::$enabled) {
            return;
        }

        self::$stats['dispatched']++;

        // 1) Exact listeners
        if (isset(self::$listeners[$event])) {
            foreach (self::$listeners[$event] as $listener) {
                $listener(...$payload);
            }
        }

        // 2) Wildcard listeners (skip exact key to avoid double-dispatch)
        foreach (self::$listeners as $pattern => $listeners) {
            if ($pattern === $event) {
                continue;
            }

            if (!self::matchesPattern($event, $pattern)) {
                continue;
            }

            foreach ($listeners as $listener) {
                $listener(...$payload);
            }
        }
    }

    /**
     * Flush queued events (dispatch them in FIFO order).
     */
    public static function flush(): void
    {
        foreach (self::$queue as $item) {
            self::dispatch($item['event'], $item['payload']);
        }

        self::$queue = [];
    }

    /**
     * Queue an event for later dispatch.
     *
     * @param string $event
     * @param array  $payload
     */
    public static function queue(string $event, array $payload = []): void
    {
        self::$queue[] = [
          'event'   => $event,
          'payload' => $payload,
          'time'    => microtime(true),
        ];

        self::$stats['queued']++;
    }

    /**
     * Register an event listener.
     */
    public static function listen(string $event, callable $listener): void
    {
        self::$listeners[$event][] = $listener;
    }

    /**
     * Subscribe to multiple events using a subscriber.
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
     * Remove event listener(s).
     *
     * If $listener is null, all listeners for the event are removed.
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
     * Remove all event listeners.
     */
    public static function forgetAll(): void
    {
        self::$listeners = [];
    }

    /**
     * Check if event has listeners.
     */
    public static function hasListeners(string $event): bool
    {
        return !empty(self::$listeners[$event]);
    }

    /**
     * Check if events are enabled.
     */
    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    /**
     * Get all registered event names.
     *
     * @return list<string>
     */
    public static function getEvents(): array
    {
        return array_keys(self::$listeners);
    }

    /**
     * Get registered listeners for an event.
     *
     * @return list<callable>
     */
    public static function getListeners(string $event): array
    {
        return self::$listeners[$event] ?? [];
    }

    /**
     * Get event statistics and some derived counts.
     */
    public static function getStats(): array
    {
        $totalListeners = array_sum(array_map('count', self::$listeners));

        return array_merge(self::$stats, [
          'registered_events' => count(self::$listeners),
          'queued_events'     => count(self::$queue),
          'total_listeners'   => $totalListeners,
        ]);
    }

    /**
     * Reset statistics counters.
     */
    public static function resetStats(): void
    {
        self::$stats = [
          'dispatched' => 0,
          'queued'     => 0,
        ];
    }

    /**
     * Check if event name matches a wildcard pattern.
     *
     * Examples:
     *   pattern "db.*"       matches "db.query.executed"
     *   pattern "db.query.*" matches "db.query.executed"
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
