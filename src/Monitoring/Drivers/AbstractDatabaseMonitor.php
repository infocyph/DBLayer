<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Monitoring\Drivers;

use Infocyph\DBLayer\Connection\Connection;
use PDO;
use PDOStatement;

/** @internal */
abstract class AbstractDatabaseMonitor
{
    public function __construct(protected readonly Connection $connection) {}

    /** @return list<array<string,mixed>> */
    abstract public function indexMetrics(): array;

    /** @return list<array<string,mixed>> */
    abstract public function locks(): array;

    /** @return list<array<string,mixed>> */
    abstract public function longRunningQueries(int $seconds): array;

    /** @return list<array<string,mixed>> */
    abstract public function maintenance(): array;

    /** @return list<array<string,mixed>> */
    abstract public function replication(): array;

    /** @return list<array<string,mixed>> */
    abstract public function sessions(): array;

    /** @return array<string,mixed> */
    abstract public function status(): array;

    /** @return list<array<string,mixed>> */
    abstract public function tableMetrics(): array;

    final protected function intValue(mixed $value): int
    {
        return is_int($value) || is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Execute a read-only operational query directly on the writer PDO.
     * Monitoring bypasses query logging/profiling so a snapshot does not distort
     * the application workload it is inspecting.
     *
     * @param array<int|string,mixed> $bindings
     * @return list<array<string,mixed>>
     */
    final protected function query(string $sql, array $bindings = []): array
    {
        $statement = $this->connection->getPdo()->prepare($sql);
        if (!$statement instanceof PDOStatement) {
            return [];
        }

        foreach ($bindings as $key => $value) {
            $parameter = is_int($key) ? $key + 1 : $key;
            $statement->bindValue($parameter, $value, $this->parameterType($value));
        }

        $statement->execute();
        /** @var list<array<string,mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $statement->closeCursor();

        return $rows;
    }

    /** @param array<int|string,mixed> $bindings */
    final protected function scalar(string $sql, array $bindings = []): mixed
    {
        $row = $this->query($sql, $bindings)[0] ?? [];
        if ($row === []) {
            return null;
        }

        return $row[array_key_first($row)];
    }

    final protected function serverVersion(): string
    {
        $version = $this->connection->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);

        return is_string($version) || is_int($version) || is_float($version)
            ? (string) $version
            : '';
    }

    private function parameterType(mixed $value): int
    {
        return match (true) {
            is_int($value) => PDO::PARAM_INT,
            is_bool($value) => PDO::PARAM_BOOL,
            $value === null => PDO::PARAM_NULL,
            is_resource($value) => PDO::PARAM_LOB,
            default => PDO::PARAM_STR,
        };
    }
}
