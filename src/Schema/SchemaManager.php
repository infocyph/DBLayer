<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Schema;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Exceptions\MigrationException;
use Throwable;

/**
 * Opt-in schema entry point for one resolved connection.
 */
final readonly class SchemaManager
{
    private SchemaGrammar $grammar;

    public function __construct(private Connection $connection)
    {
        $this->grammar = new SchemaGrammar(
            $connection->getDriverName(),
            $connection->getTablePrefix(),
        );
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    /** @param callable(Blueprint):void $definition */
    public function create(string $table, callable $definition): void
    {
        $this->execute($this->build($table, true, $definition));
        $this->invalidateTables([$table]);
    }

    public function drop(string $table): void
    {
        $this->connection->statement($this->grammar->compileDrop($table));
        $this->invalidateTables([$table]);
    }

    /**
     * Drop every user table. This low-level destructive API requires an
     * explicit true value; higher layers should add environment confirmation.
     */
    public function dropAllTables(bool $authorized = false): void
    {
        if (!$authorized) {
            throw \Infocyph\DBLayer\Exceptions\MigrationException::destructiveDenied('drop-all-tables');
        }

        $tables = $this->tables();
        if ($tables === []) {
            return;
        }

        if ($this->grammar->driver() === 'mysql') {
            $this->connection->statement('SET FOREIGN_KEY_CHECKS = 0');
        } elseif ($this->grammar->driver() === 'sqlite') {
            $this->connection->statement('PRAGMA foreign_keys = OFF');
        }

        try {
            foreach ($tables as $table) {
                $sql = $this->grammar->compileDrop($table, true);
                if ($this->grammar->driver() === 'pgsql') {
                    $sql .= ' CASCADE';
                }

                $this->connection->statement($sql);
            }
        } catch (Throwable $primary) {
            try {
                $this->restoreForeignKeyChecks();
            } catch (Throwable $cleanup) {
                throw MigrationException::cleanupAlsoFailed($primary, $cleanup);
            }

            throw $primary;
        }

        $this->restoreForeignKeyChecks();
        $this->invalidateTables($tables);
    }

    public function dropIfExists(string $table): void
    {
        $this->connection->statement($this->grammar->compileDrop($table, true));
        $this->invalidateTables([$table]);
    }

    public function hasColumn(string $table, string $column): bool
    {
        Blueprint::assertIdentifier($table);
        Blueprint::assertIdentifier($column);
        [$namespace, $table] = $this->splitQualifiedTable($this->physicalTable($table));

        if ($this->grammar->driver() === 'sqlite') {
            $quote = '"' . str_replace('"', '""', $table) . '"';
            $pragma = $namespace === null
                ? sprintf('PRAGMA table_info(%s)', $quote)
                : sprintf('PRAGMA "%s".table_info(%s)', str_replace('"', '""', $namespace), $quote);
            $statement = $this->connection->execute($pragma);
            /** @var list<array<string,mixed>> $rows */
            $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

            return array_any($rows, fn($row) => ($row['name'] ?? null) === $column);
        }

        [$sql, $bindings] = $this->grammar->driver() === 'mysql'
            ? [
                'SELECT 1 FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ? LIMIT 1',
                [$namespace ?? $this->connection->getDatabaseName(), $table, $column],
            ]
            : [
                'SELECT 1 FROM information_schema.columns WHERE table_schema = COALESCE(?, current_schema()) AND table_name = ? AND column_name = ? LIMIT 1',
                [$namespace, $table, $column],
            ];

        return $this->connection->select($sql, $bindings) !== [];
    }

    public function hasTable(string $table): bool
    {
        Blueprint::assertIdentifier($table);
        [$namespace, $table] = $this->splitQualifiedTable($this->physicalTable($table));

        [$sql, $bindings] = match ($this->grammar->driver()) {
            'mysql' => [
                'SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name = ? LIMIT 1',
                [$namespace ?? $this->connection->getDatabaseName(), $table],
            ],
            'pgsql' => [
                'SELECT 1 FROM information_schema.tables WHERE table_schema = COALESCE(?, current_schema()) AND table_name = ? LIMIT 1',
                [$namespace, $table],
            ],
            default => [
                'SELECT 1 FROM ' . ($namespace === null ? '' : '"' . str_replace('"', '""', $namespace) . '".') . "sqlite_master WHERE type = 'table' AND name = ? LIMIT 1",
                [$table],
            ],
        };

        return $this->connection->select($sql, $bindings) !== [];
    }

    public function rename(string $from, string $to): void
    {
        $this->connection->statement($this->grammar->compileRename($from, $to));
        $this->invalidateTables([$from, $to]);
    }

    public function supportsTransactionalDdl(): bool
    {
        return $this->grammar->supportsTransactionalDdl();
    }

    /** @param callable(Blueprint):void $definition */
    public function table(string $table, callable $definition): void
    {
        $this->execute($this->build($table, false, $definition));
        $this->invalidateTables([$table]);
    }

    /**
     * List user tables in the selected database/schema.
     *
     * @return list<string>
     */
    public function tables(): array
    {
        [$sql, $key, $bindings] = match ($this->grammar->driver()) {
            'mysql' => [
                'SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_type = ?',
                'table_name',
                [$this->connection->getDatabaseName(), 'BASE TABLE'],
            ],
            'pgsql' => [
                'SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname = current_schema()',
                'tablename',
                [],
            ],
            default => [
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'",
                'name',
                [],
            ],
        };

        $tables = [];

        foreach ($this->connection->select($sql, $bindings) as $row) {
            $name = $row[$key] ?? null;
            if (!is_string($name)) {
                throw \Infocyph\DBLayer\Exceptions\SchemaException::invalid(
                    sprintf('Database returned an invalid table name for key "%s".', $key),
                );
            }

            $tables[] = $name;
        }

        return $tables;
    }

    /**
     * Compile without executing. Used by migration previews and tooling.
     *
     * @param callable(Blueprint):void $definition
     * @return non-empty-list<string>
     */
    public function toSql(string $table, bool $creating, callable $definition): array
    {
        return $this->build($table, $creating, $definition);
    }

    /**
     * @param callable(Blueprint):void $definition
     * @return non-empty-list<string>
     */
    private function build(string $table, bool $creating, callable $definition): array
    {
        $blueprint = new Blueprint($table, $creating);
        $definition($blueprint);

        return $this->grammar->compile($blueprint);
    }

    /** @param non-empty-list<string> $statements */
    private function execute(array $statements): void
    {
        foreach ($statements as $statement) {
            $this->connection->statement($statement);
        }
    }

    /** @param list<string> $tables */
    private function invalidateTables(array $tables): void
    {
        $tags = array_map(
            fn(string $table): string => $this->connection->cacheTableTag($table),
            array_values(array_unique($tables)),
        );
        DB::invalidateCacheTagsAfterCommit($tags, $this->connection->getName());
    }

    private function physicalTable(string $table): string
    {
        $prefix = $this->connection->getTablePrefix();

        if ($prefix === '' || str_contains($table, '.')) {
            return $table;
        }

        return str_starts_with($table, $prefix) ? $table : $prefix . $table;
    }

    private function restoreForeignKeyChecks(): void
    {
        if ($this->grammar->driver() === 'mysql') {
            $this->connection->statement('SET FOREIGN_KEY_CHECKS = 1');
        } elseif ($this->grammar->driver() === 'sqlite') {
            $this->connection->statement('PRAGMA foreign_keys = ON');
        }
    }

    /** @return array{0:string|null,1:string} */
    private function splitQualifiedTable(string $table): array
    {
        $parts = explode('.', $table);
        if (count($parts) === 1) {
            return [null, $parts[0]];
        }

        $name = array_pop($parts);

        return [implode('.', $parts), $name];
    }
}
