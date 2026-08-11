<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Migration;

use Closure;
use Infocyph\CacheLayer\Cache\Lock\LockHandle;
use Infocyph\CacheLayer\Cache\Lock\LockProviderInterface;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Exceptions\MigrationException;
use Infocyph\DBLayer\Schema\SchemaManager;
use Throwable;

/**
 * Deterministic migration runner over an explicit, precompiled migration list.
 *
 * No filesystem or package discovery occurs here. Foundation may compile
 * application and package migration sources before constructing this runner.
 */
final class MigrationRunner
{
    private readonly string $lockKey;

    private readonly MigrationRepository $repository;

    private readonly SchemaManager $schema;

    /** @var array<string,Migration> */
    private array $migrations;

    /**
     * @param iterable<Migration> $migrations
     */
    public function __construct(
        private readonly Connection $connection,
        iterable $migrations,
        private readonly ?LockProviderInterface $locks = null,
        string $table = 'migrations',
        private readonly float $lockWaitSeconds = 10.0,
        private readonly float $leaseSeconds = 300.0,
    ) {
        if ($lockWaitSeconds < 0.0 || $leaseSeconds <= 0.0) {
            throw new \InvalidArgumentException('Migration lock wait must be non-negative and lease duration must be positive.');
        }

        $this->migrations = $this->normalize($migrations);
        $this->repository = new MigrationRepository($connection, $table);
        $this->schema = new SchemaManager($connection);
        $config = $connection->getConfig();
        $identity = implode("\0", [
            $connection->getDriverName(),
            self::lockIdentityPart($config->get('host', '')),
            self::lockIdentityPart($config->get('unix_socket', '')),
            self::lockIdentityPart($config->get('port', '')),
            $connection->getDatabaseName(),
            self::lockIdentityPart($config->get('schema', '')),
            $table,
        ]);
        $this->lockKey = 'dblayer:migrations:' . hash('sha256', $identity);
    }

    /**
     * Drop all user tables and rerun migrations after explicit approval.
     *
     * @param callable(string):bool|bool $authorized
     * @return list<string>
     */
    public function fresh(callable|bool $authorized = false): array
    {
        $this->authorize('fresh', $authorized);

        return $this->withOwnership(function (?LockHandle $handle): array {
            $this->schema->dropAllTables(true);
            $this->checkpoint($handle);
            $this->repository->ensureExists();

            return $this->applyPending($handle);
        });
    }

    /**
     * Compile pending migration SQL without executing it.
     *
     * @return array<string,list<array{sql:string,bindings:array<int|string,mixed>}>>
     */
    public function pretend(): array
    {
        $applied = $this->repository->applied();
        $preview = [];

        foreach ($this->migrations as $id => $migration) {
            if (isset($applied[$id]) || !$this->shouldRun($migration)) {
                continue;
            }

            $preview[$id] = array_values($this->connection->pretend(function () use ($migration): void {
                $migration->up($this->schema, $this->context(null, true));
            }));
        }

        return $preview;
    }

    /**
     * Roll back and rerun migrations after explicit destructive approval.
     *
     * @param callable(string):bool|bool $authorized
     * @return list<string>
     */
    public function refresh(callable|bool $authorized = false): array
    {
        $this->authorize('refresh', $authorized);

        return $this->withOwnership(function (?LockHandle $handle): array {
            $this->revertRows($this->repository->all(), $handle);
            $this->repository->ensureExists();

            return $this->applyPending($handle);
        });
    }

    /**
     * Roll back every applied migration after explicit destructive approval.
     *
     * @param callable(string):bool|bool $authorized
     * @return list<string>
     */
    public function reset(callable|bool $authorized = false): array
    {
        $this->authorize('reset', $authorized);

        return $this->withOwnership(fn(?LockHandle $handle): array => $this->revertRows($this->repository->all(), $handle));
    }

    /**
     * Roll back the most recent migration batches.
     *
     * @return list<string> rolled-back identifiers
     */
    public function rollback(int $batches = 1): array
    {
        if ($batches < 1) {
            return [];
        }

        return $this->withOwnership(function (?LockHandle $handle) use ($batches): array {
            $applied = $this->repository->all();
            $selected = $this->selectLatestBatches($applied, $batches);

            return $this->revertRows($selected, $handle);
        });
    }

    /**
     * Roll back one exact migration batch.
     *
     * @return list<string>
     */
    public function rollbackBatch(int $batch): array
    {
        if ($batch < 1) {
            throw new \InvalidArgumentException('Migration batch must be positive.');
        }

        return $this->withOwnership(function (?LockHandle $handle) use ($batch): array {
            $selected = array_filter(
                $this->repository->all(),
                static fn(array $row): bool => $row['batch'] === $batch,
            );

            return $this->revertRows(array_values($selected), $handle);
        });
    }

    /**
     * Apply every pending migration.
     *
     * @return list<string> applied migration identifiers
     */
    public function run(bool $step = false): array
    {
        return $this->withOwnership(function (?LockHandle $handle) use ($step): array {
            $this->repository->ensureExists();

            return $this->applyPending($handle, $step);
        });
    }

    /**
     * Report all registered and applied migrations.
     *
     * @return list<array{id:string,applied:bool,batch:int|null}>
     */
    public function status(): array
    {
        $applied = $this->repository->applied();
        $status = [];

        foreach ($this->migrations as $id => $_migration) {
            $status[] = [
                'id' => $id,
                'applied' => isset($applied[$id]),
                'batch' => $applied[$id] ?? null,
            ];
        }

        return $status;
    }

    private static function lockIdentityPart(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function apply(Migration $migration, int $batch, ?LockHandle $handle): void
    {
        $operation = function () use ($migration, $batch, $handle): void {
            $migration->up($this->schema, $this->context($handle));
            $this->checkpoint($handle);
            $this->repository->log($migration->id(), $batch);
        };

        try {
            if ($this->schema->supportsTransactionalDdl()) {
                $this->connection->transaction(static fn() => $operation());
            } else {
                $operation();
            }
        } catch (Throwable $error) {
            throw MigrationException::failed($migration->id(), $error);
        }
    }

    /** @return list<string> */
    private function applyPending(?LockHandle $handle, bool $step = false): array
    {
        $applied = $this->repository->applied();
        $batch = $this->repository->nextBatch();
        $ran = [];

        foreach ($this->migrations as $id => $migration) {
            if (isset($applied[$id]) || !$this->shouldRun($migration)) {
                continue;
            }

            $this->checkpoint($handle);
            $this->apply($migration, $batch, $handle);
            $ran[] = $id;

            if ($step) {
                ++$batch;
            }
        }

        return $ran;
    }

    /** @param callable(string):bool|bool $authorized */
    private function authorize(string $operation, callable|bool $authorized): void
    {
        $allowed = is_bool($authorized) ? $authorized : $authorized($operation);

        if (!$allowed) {
            throw MigrationException::destructiveDenied($operation);
        }
    }

    private function checkpoint(?LockHandle $handle): void
    {
        if ($handle !== null && !$this->locks?->refresh($handle, $this->leaseSeconds)) {
            throw MigrationException::leaseLost($this->lockKey);
        }
    }

    private function context(?LockHandle $handle, bool $pretending = false): MigrationContext
    {
        return new MigrationContext(
            $pretending,
            fn(): bool => $pretending || $handle === null
                || ($this->locks?->refresh($handle, $this->leaseSeconds) ?? false),
            $this->lockKey,
        );
    }

    /**
     * @param iterable<Migration> $migrations
     * @return array<string,Migration>
     */
    private function normalize(iterable $migrations): array
    {
        $normalized = [];

        foreach ($migrations as $migration) {
            $id = trim($migration->id());
            if ($id === '') {
                throw new MigrationException('Migration identifiers must not be empty.');
            }

            if (isset($normalized[$id])) {
                throw MigrationException::duplicate($id);
            }

            $normalized[$id] = $migration;
        }

        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    private function revert(Migration $migration, ?LockHandle $handle): void
    {
        $operation = function () use ($migration, $handle): void {
            $migration->down($this->schema, $this->context($handle));
            $this->checkpoint($handle);
            $this->repository->delete($migration->id());
        };

        try {
            if ($this->schema->supportsTransactionalDdl()) {
                $this->connection->transaction(static fn() => $operation());
            } else {
                $operation();
            }
        } catch (Throwable $error) {
            throw MigrationException::failed($migration->id(), $error);
        }
    }

    /**
     * @param list<array{migration:string,batch:int,applied_at:string}> $rows
     * @return list<string>
     */
    private function revertRows(array $rows, ?LockHandle $handle): array
    {
        $rolledBack = [];

        foreach (array_reverse($rows) as $row) {
            $id = $row['migration'];
            $migration = $this->migrations[$id] ?? null;
            if (!$migration instanceof Migration) {
                throw new MigrationException(sprintf(
                    'Applied migration "%s" is not present in the explicit migration manifest.',
                    $id,
                ));
            }

            $this->checkpoint($handle);
            $this->revert($migration, $handle);
            $rolledBack[] = $id;
        }

        return $rolledBack;
    }

    /**
     * @param list<array{migration:string,batch:int,applied_at:string}> $rows
     * @return list<array{migration:string,batch:int,applied_at:string}>
     */
    private function selectLatestBatches(array $rows, int $batches): array
    {
        $batchNumbers = array_values(array_unique(array_column($rows, 'batch')));
        rsort($batchNumbers, SORT_NUMERIC);
        $selected = array_flip(array_slice($batchNumbers, 0, $batches));

        return array_values(array_filter(
            $rows,
            static fn(array $row): bool => isset($selected[$row['batch']]),
        ));
    }

    private function shouldRun(Migration $migration): bool
    {
        return !$migration instanceof ConditionalMigration || $migration->shouldRun();
    }

    /**
     * @template TResult
     * @param Closure(?LockHandle):TResult $operation
     * @return TResult
     */
    private function withOwnership(Closure $operation): mixed
    {
        if ($this->locks === null) {
            return $operation(null);
        }

        $handle = $this->locks->acquire($this->lockKey, $this->lockWaitSeconds, $this->leaseSeconds);
        if (!$handle instanceof LockHandle) {
            throw MigrationException::lockUnavailable($this->lockKey);
        }

        try {
            $result = $operation($handle);
        } catch (Throwable $primary) {
            try {
                $this->locks->release($handle);
            } catch (Throwable $cleanup) {
                throw MigrationException::cleanupAlsoFailed($primary, $cleanup);
            }

            throw $primary;
        }

        try {
            $this->locks->release($handle);
        } catch (Throwable $cleanup) {
            throw new MigrationException('Migration lock release failed: ' . $cleanup->getMessage(), 0, $cleanup);
        }

        return $result;
    }
}
