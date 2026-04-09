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
     * @var array<int, array{event:string,payload:array<int,mixed>,time:float}>
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
     * Wildcard listeners keyed by pattern.
     *
     * @var array<string, array<int, callable>>
     */
    private static array $wildcardListeners = [];

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
     * Dispatch an event.
     *
     * @param string           $event   Event name (class name, "db.query.executed", etc.)
     * @param array<int,mixed> $payload Arguments passed to listeners.
     */
    public static function dispatch(string $event, array $payload = []): void
    {
        if (
            ! self::$enabled
            || (self::$listeners === [] && self::$wildcardListeners === [])
        ) {
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
        foreach (self::$wildcardListeners as $pattern => $listeners) {
            if (! self::matchesPattern($event, $pattern)) {
                continue;
            }

            foreach ($listeners as $listener) {
                $listener(...$payload);
            }
        }
    }

    /**
     * Enable event dispatching.
     */
    public static function enable(): void
    {
        self::$enabled = true;
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
     * Remove event listener(s).
     *
     * If $listener is null, all listeners for the event are removed.
     */
    public static function forget(string $event, ?callable $listener = null): void
    {
        if ($listener === null) {
            self::forgetAllListenersForEvent($event);
            return;
        }

        if (self::isWildcardEvent($event)) {
            self::forgetListenerFromBucket(self::$wildcardListeners, $event, $listener);
            return;
        }

        self::forgetListenerFromBucket(self::$listeners, $event, $listener);
    }

    /**
     * Remove all event listeners.
     */
    public static function forgetAll(): void
    {
        self::$listeners = [];
        self::$wildcardListeners = [];
    }

    /**
     * Get all registered event names.
     *
     * @return list<string>
     */
    public static function getEvents(): array
    {
        return \array_values(\array_merge(
            \array_keys(self::$listeners),
            \array_keys(self::$wildcardListeners),
        ));
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
     *
     * @return array{
     *   dispatched:int,
     *   queued:int,
     *   registered_events:int,
     *   queued_events:int,
     *   total_listeners:int
     * }
     */
    public static function getStats(): array
    {
        $totalListeners = \array_sum(\array_map(count(...), self::$listeners))
          + \array_sum(\array_map(count(...), self::$wildcardListeners));

        return [
            'dispatched'        => self::$stats['dispatched'],
            'queued'            => self::$stats['queued'],
            'registered_events' => \count(self::$listeners) + \count(self::$wildcardListeners),
            'queued_events'     => \count(self::$queue),
            'total_listeners'   => $totalListeners,
        ];
    }

    /**
     * Check if event has listeners (exact name only).
     */
    public static function hasListeners(string $event): bool
    {
        return ! empty(self::$listeners[$event]);
    }

    /**
     * Check if events are enabled.
     */
    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    /**
     * Register an event listener.
     */
    public static function listen(string $event, callable $listener): void
    {
        if (\str_contains($event, '*')) {
            self::$wildcardListeners[$event][] = $listener;

            return;
        }

        self::$listeners[$event][] = $listener;
    }

    /**
     * Queue an event for later dispatch.
     *
     * @param array<int,mixed> $payload
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
     * Subscribe to multiple events using a subscriber.
     */
    public static function subscribe(EventSubscriber $subscriber): void
    {
        foreach ($subscriber->subscribe() as $event => $listener) {
            if (\is_string($listener)) {
                $listener = [$subscriber, $listener];
            }

            self::listen($event, $listener);
        }
    }

    /**
     * Remove all listeners for the given event key.
     */
    private static function forgetAllListenersForEvent(string $event): void
    {
        if (self::isWildcardEvent($event)) {
            unset(self::$wildcardListeners[$event]);
            return;
        }

        unset(self::$listeners[$event]);
    }

    /**
     * Remove one listener from a listener bucket.
     *
     * @param  array<string, array<int, callable>>  $bucket
     */
    private static function forgetListenerFromBucket(array &$bucket, string $event, callable $listener): void
    {
        if (! isset($bucket[$event])) {
            return;
        }

        foreach ($bucket[$event] as $key => $registered) {
            if ($registered === $listener) {
                unset($bucket[$event][$key]);
            }
        }

        $bucket[$event] = \array_values($bucket[$event]);

        if ($bucket[$event] === []) {
            unset($bucket[$event]);
        }
    }

    /**
     * Determine whether an event key is a wildcard pattern.
     */
    private static function isWildcardEvent(string $event): bool
    {
        return \str_contains($event, '*');
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
        if (! \str_contains($pattern, '*')) {
            return false;
        }

        // Convert pattern to regex
        $regex = '/^' . \str_replace('\*', '.*', \preg_quote($pattern, '/')) . '$/';

        return \preg_match($regex, $event) === 1;
    }
}
