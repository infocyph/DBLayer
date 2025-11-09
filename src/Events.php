<?php

declare(strict_types=1);

namespace Infocyph\DBLayer;

/**
 * Event dispatcher for query lifecycle events
 */
class Events
{
    private static array $listeners = [];

    public static function listen(string $event, callable $listener): void
    {
        self::$listeners[$event][] = $listener;
    }

    public static function dispatch(string $event, array $payload = []): void
    {
        if (!isset(self::$listeners[$event])) {
            return;
        }

        foreach (self::$listeners[$event] as $listener) {
            $listener(...$payload);
        }
    }

    public static function until(string $event, array $payload = []): mixed
    {
        if (!isset(self::$listeners[$event])) {
            return null;
        }

        foreach (self::$listeners[$event] as $listener) {
            $result = $listener(...$payload);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    public static function forget(string $event): void
    {
        unset(self::$listeners[$event]);
    }

    public static function flush(): void
    {
        self::$listeners = [];
    }

    public static function hasListeners(string $event): bool
    {
        return isset(self::$listeners[$event]) && !empty(self::$listeners[$event]);
    }

    public static function getListeners(string $event): array
    {
        return self::$listeners[$event] ?? [];
    }
}

/**
 * Query executed event
 */
class QueryExecuted
{
    public function __construct(
        public readonly string $sql,
        public readonly array $bindings,
        public readonly float $time,
        public readonly Connection $connection
    ) {
    }
}

/**
 * Transaction beginning event
 */
class TransactionBeginning
{
    public function __construct(
        public readonly Connection $connection
    ) {
    }
}

/**
 * Transaction committed event
 */
class TransactionCommitted
{
    public function __construct(
        public readonly Connection $connection
    ) {
    }
}

/**
 * Transaction rolled back event
 */
class TransactionRolledBack
{
    public function __construct(
        public readonly Connection $connection
    ) {
    }
}

/**
 * Query failed event
 */
class QueryFailed
{
    public function __construct(
        public readonly string $sql,
        public readonly array $bindings,
        public readonly \Throwable $exception,
        public readonly Connection $connection
    ) {
    }
}
