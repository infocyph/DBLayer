<?php

declare(strict_types=1);

namespace Infocyph\DBLayer;

use PDO;
use PDOStatement;
use Generator;

/**
 * Query executor with result hydration strategies
 */
class Executor
{
    private Connection $connection;
    private ?Profiler $profiler = null;
    private ?Cache $cache = null;
    private array $preparedCache = [];
    private const MAX_PREPARED_CACHE = 100;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function select(string $sql, array $bindings = [], bool $useReadPdo = true): array
    {
        return $this->run($sql, $bindings, function ($sql, $bindings) use ($useReadPdo) {
            $pdo = $this->connection->shouldUseWriteConnection() || !$useReadPdo
                ? $this->connection->getWritePdo()
                : $this->connection->getReadPdo();

            $statement = $pdo->prepare($sql);
            $this->bindValues($statement, $bindings);
            $statement->execute();

            return $statement->fetchAll();
        });
    }

    public function selectOne(string $sql, array $bindings = []): ?array
    {
        $results = $this->select($sql, $bindings);
        return $results[0] ?? null;
    }

    public function selectScalar(string $sql, array $bindings = []): mixed
    {
        return $this->run($sql, $bindings, function ($sql, $bindings) {
            $pdo = $this->connection->shouldUseWriteConnection()
                ? $this->connection->getWritePdo()
                : $this->connection->getReadPdo();

            $statement = $pdo->prepare($sql);
            $this->bindValues($statement, $bindings);
            $statement->execute();

            return $statement->fetchColumn();
        });
    }

    public function insert(string $sql, array $bindings = []): bool
    {
        $this->connection->recordsHaveBeenModified();

        return $this->run($sql, $bindings, function ($sql, $bindings) {
            $statement = $this->connection->getWritePdo()->prepare($sql);
            $this->bindValues($statement, $bindings);
            return $statement->execute();
        });
    }

    public function insertGetId(string $sql, array $bindings = [], ?string $sequence = null): int
    {
        $this->connection->recordsHaveBeenModified();

        $this->run($sql, $bindings, function ($sql, $bindings) {
            $statement = $this->connection->getWritePdo()->prepare($sql);
            $this->bindValues($statement, $bindings);
            $statement->execute();
        });

        return (int) $this->connection->lastInsertId($sequence);
    }

    public function update(string $sql, array $bindings = []): int
    {
        $this->connection->recordsHaveBeenModified();

        return $this->run($sql, $bindings, function ($sql, $bindings) {
            $statement = $this->connection->getWritePdo()->prepare($sql);
            $this->bindValues($statement, $bindings);
            $statement->execute();
            return $statement->rowCount();
        });
    }

    public function delete(string $sql, array $bindings = []): int
    {
        $this->connection->recordsHaveBeenModified();

        return $this->run($sql, $bindings, function ($sql, $bindings) {
            $statement = $this->connection->getWritePdo()->prepare($sql);
            $this->bindValues($statement, $bindings);
            $statement->execute();
            return $statement->rowCount();
        });
    }

    public function statement(string $sql, array $bindings = []): bool
    {
        return $this->run($sql, $bindings, function ($sql, $bindings) {
            $statement = $this->connection->getPdo()->prepare($sql);
            $this->bindValues($statement, $bindings);
            return $statement->execute();
        });
    }

    public function unprepared(string $sql): bool
    {
        return $this->run($sql, [], function ($sql) {
            return $this->connection->getPdo()->exec($sql) !== false;
        });
    }

    public function cursor(string $sql, array $bindings = []): Generator
    {
        $pdo = $this->connection->shouldUseWriteConnection()
            ? $this->connection->getWritePdo()
            : $this->connection->getReadPdo();

        $statement = $pdo->prepare($sql);
        $this->bindValues($statement, $bindings);
        
        $start = microtime(true);
        $statement->execute();
        
        $this->logQuery($sql, $bindings, microtime(true) - $start);

        while ($row = $statement->fetch()) {
            yield $row;
        }

        $statement->closeCursor();
    }

    public function batchInsert(string $table, array $columns, array $values): int
    {
        if (empty($values)) {
            return 0;
        }

        $this->connection->recordsHaveBeenModified();

        $placeholders = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
        $allPlaceholders = implode(',', array_fill(0, count($values), $placeholders));
        
        $columnList = implode(',', array_map(
            fn($col) => Security::escapeIdentifier($col, $this->connection->getDriver()),
            $columns
        ));

        $sql = "INSERT INTO " . Security::escapeIdentifier($table, $this->connection->getDriver()) 
             . " ({$columnList}) VALUES {$allPlaceholders}";

        $bindings = [];
        foreach ($values as $row) {
            foreach ($columns as $column) {
                $bindings[] = $row[$column] ?? null;
            }
        }

        return $this->run($sql, $bindings, function ($sql, $bindings) {
            $statement = $this->connection->getWritePdo()->prepare($sql);
            $this->bindValues($statement, $bindings);
            $statement->execute();
            return $statement->rowCount();
        });
    }

    private function run(string $sql, array $bindings, \Closure $callback): mixed
    {
        // Validate query
        Security::checkRateLimit();
        Security::validateQuerySize($sql, $bindings);
        Security::incrementQueryCount();

        $start = microtime(true);

        try {
            $result = $callback($sql, $bindings);
            $time = microtime(true) - $start;

            $this->logQuery($sql, $bindings, $time);
            Events::dispatch('query.executed', [
                new QueryExecuted($sql, $bindings, $time, $this->connection)
            ]);

            return $result;
        } catch (\Throwable $e) {
            $time = microtime(true) - $start;
            
            Events::dispatch('query.failed', [
                new QueryFailed($sql, $bindings, $e, $this->connection)
            ]);

            $this->handleException($e, $sql, $bindings);
        }
    }

    private function bindValues(PDOStatement $statement, array $bindings): void
    {
        foreach ($bindings as $key => $value) {
            $statement->bindValue(
                is_string($key) ? $key : $key + 1,
                $value,
                $this->getPdoType($value)
            );
        }
    }

    private function getPdoType(mixed $value): int
    {
        return match (true) {
            is_int($value) => PDO::PARAM_INT,
            is_bool($value) => PDO::PARAM_BOOL,
            is_null($value) => PDO::PARAM_NULL,
            default => PDO::PARAM_STR
        };
    }

    private function logQuery(string $sql, array $bindings, float $time): void
    {
        if ($this->profiler && $this->profiler->isEnabled()) {
            $this->profiler->logQuery($sql, $bindings, $time);
        }

        Security::logQuery($sql, $bindings, $time);
    }

    private function handleException(\Throwable $e, string $sql, array $bindings): never
    {
        throw new QueryException(
            $e->getMessage(),
            $sql,
            $bindings,
            $e
        );
    }

    public function setProfiler(Profiler $profiler): void
    {
        $this->profiler = $profiler;
    }

    public function getProfiler(): ?Profiler
    {
        return $this->profiler;
    }

    public function setCache(Cache $cache): void
    {
        $this->cache = $cache;
    }

    public function getCache(): ?Cache
    {
        return $this->cache;
    }

    public function clearPreparedCache(): void
    {
        $this->preparedCache = [];
    }
}
