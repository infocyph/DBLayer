<?php

/**
 * DBLayer Code Generator
 * Generates all remaining source files for the complete codebase
 */

$baseDir = __DIR__;
$srcDir = $baseDir . '/src';

// File definitions with their content
$files = [
    'Profiler.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace Infocyph\DBLayer;

class Profiler
{
    private array $queries = [];
    private bool $enabled = false;
    private float $slowQueryThreshold = 1.0;

    public function enable(): void
    {
        $this->enabled = true;
    }

    public function disable(): void
    {
        $this->enabled = false;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function logQuery(string $sql, array $bindings, float $time, array $context = []): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->queries[] = [
            'sql' => $sql,
            'bindings' => $bindings,
            'time' => $time,
            'context' => $context,
            'timestamp' => microtime(true),
        ];
    }

    public function getQueries(): array
    {
        return $this->queries;
    }

    public function getSlowQueries(?float $threshold = null): array
    {
        $threshold = $threshold ?? $this->slowQueryThreshold;

        return array_filter($this->queries, fn($query) => $query['time'] >= $threshold);
    }

    public function getTotalQueries(): int
    {
        return count($this->queries);
    }

    public function getTotalTime(): float
    {
        return array_sum(array_column($this->queries, 'time'));
    }

    public function getAverageTime(): float
    {
        $count = $this->getTotalQueries();
        return $count === 0 ? 0 : $this->getTotalTime() / $count;
    }

    public function reset(): void
    {
        $this->queries = [];
    }

    public function setSlowQueryThreshold(float $seconds): void
    {
        $this->slowQueryThreshold = $seconds;
    }

    public function getSlowQueryThreshold(): float
    {
        return $this->slowQueryThreshold;
    }

    public function toArray(): array
    {
        return [
            'total_queries' => $this->getTotalQueries(),
            'total_time' => $this->getTotalTime(),
            'average_time' => $this->getAverageTime(),
            'slow_queries' => count($this->getSlowQueries()),
            'queries' => $this->queries,
        ];
    }
}
PHP,

    'Events.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace Infocyph\DBLayer;

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

class TransactionBeginning
{
    public function __construct(public readonly Connection $connection)
    {
    }
}

class TransactionCommitted
{
    public function __construct(public readonly Connection $connection)
    {
    }
}

class TransactionRolledBack
{
    public function __construct(public readonly Connection $connection)
    {
    }
}
PHP,

    'Cache.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace Infocyph\DBLayer;

class Cache
{
    private array $store = [];
    private array $tags = [];
    private int $defaultTtl = 3600;
    private array $expirations = [];

    public function get(string $key, mixed $default = null): mixed
    {
        if ($this->has($key) && !$this->isExpired($key)) {
            return unserialize($this->store[$key]);
        }

        return $default;
    }

    public function put(string $key, mixed $value, ?int $ttl = null): bool
    {
        $ttl = $ttl ?? $this->defaultTtl;
        $this->store[$key] = serialize($value);
        $this->expirations[$key] = time() + $ttl;

        return true;
    }

    public function forever(string $key, mixed $value): bool
    {
        $this->store[$key] = serialize($value);
        $this->expirations[$key] = PHP_INT_MAX;

        return true;
    }

    public function forget(string $key): bool
    {
        unset($this->store[$key], $this->expirations[$key]);
        return true;
    }

    public function flush(): bool
    {
        $this->store = [];
        $this->expirations = [];
        $this->tags = [];

        return true;
    }

    public function has(string $key): bool
    {
        return isset($this->store[$key]);
    }

    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        if (($value = $this->get($key)) !== null) {
            return $value;
        }

        $value = $callback();
        $this->put($key, $value, $ttl);

        return $value;
    }

    public function rememberForever(string $key, callable $callback): mixed
    {
        if (($value = $this->get($key)) !== null) {
            return $value;
        }

        $value = $callback();
        $this->forever($key, $value);

        return $value;
    }

    private function isExpired(string $key): bool
    {
        if (!isset($this->expirations[$key])) {
            return false;
        }

        if ($this->expirations[$key] <= time()) {
            $this->forget($key);
            return true;
        }

        return false;
    }
}
PHP,

    'Transaction.php' => <<<'PHP'
<?php

declare(strict_types=1);

namespace Infocyph\DBLayer;

class Transaction
{
    private Connection $connection;
    private int $level = 0;
    private array $savepoints = [];
    private ?float $startTime = null;
    private const MAX_TRANSACTION_TIME = 30;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function begin(): void
    {
        if ($this->level === 0) {
            $this->connection->getPdo()->beginTransaction();
            $this->startTime = microtime(true);
            Events::dispatch('transaction.beginning', [new TransactionBeginning($this->connection)]);
        } else {
            $savepointName = $this->createSavepoint();
            $this->connection->getPdo()->exec("SAVEPOINT {$savepointName}");
        }

        $this->level++;
    }

    public function commit(): void
    {
        if ($this->level === 0) {
            throw new TransactionException("No active transaction to commit");
        }

        $this->checkTimeout();
        $this->level--;

        if ($this->level === 0) {
            $this->connection->getPdo()->commit();
            $this->startTime = null;
            Events::dispatch('transaction.committed', [new TransactionCommitted($this->connection)]);
        } else {
            $savepointName = array_pop($this->savepoints);
            $this->connection->getPdo()->exec("RELEASE SAVEPOINT {$savepointName}");
        }
    }

    public function rollback(?int $toLevel = null): void
    {
        $toLevel = $toLevel ?? 0;

        if ($this->level === 0) {
            throw new TransactionException("No active transaction to rollback");
        }

        while ($this->level > $toLevel) {
            $this->level--;

            if ($this->level === 0) {
                $this->connection->getPdo()->rollBack();
                $this->startTime = null;
                Events::dispatch('transaction.rolledback', [new TransactionRolledBack($this->connection)]);
            } else {
                $savepointName = array_pop($this->savepoints);
                $this->connection->getPdo()->exec("ROLLBACK TO SAVEPOINT {$savepointName}");
            }
        }
    }

    public function execute(callable $callback, int $attempts = 1): mixed
    {
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $this->begin();

            try {
                $result = $callback($this->connection);
                $this->commit();

                return $result;
            } catch (\Throwable $e) {
                $this->rollback();

                if ($attempt === $attempts || !$this->causedByDeadlock($e)) {
                    throw $e;
                }

                usleep(100000 * $attempt);
            }
        }

        throw new TransactionException("Transaction failed after {$attempts} attempts");
    }

    public function level(): int
    {
        return $this->level;
    }

    public function inTransaction(): bool
    {
        return $this->level > 0;
    }

    private function createSavepoint(): string
    {
        $name = 'sp_' . (count($this->savepoints) + 1);
        $this->savepoints[] = $name;
        return $name;
    }

    private function checkTimeout(): void
    {
        if ($this->startTime !== null) {
            Security::checkTransactionTimeout($this->startTime, self::MAX_TRANSACTION_TIME);
        }
    }

    private function causedByDeadlock(\Throwable $e): bool
    {
        $message = $e->getMessage();
        return str_contains($message, 'Deadlock') || 
               str_contains($message, 'deadlock') ||
               str_contains($message, '1213');
    }
}
PHP,
];

echo "Generating remaining core files...\n";

foreach ($files as $filename => $content) {
    $filepath = $srcDir . '/' . $filename;
    file_put_contents($filepath, $content);
    echo "✓ Created {$filename}\n";
}

echo "\nAll core files generated successfully!\n";
echo "Next: Run 'php generate_advanced.php' to generate Grammar, Schema, ORM, and Async components.\n";
PHP
