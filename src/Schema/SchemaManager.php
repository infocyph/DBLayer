<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Schema;

use Infocyph\DBLayer\Connection\Connection;

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
    }

    public function drop(string $table): void
    {
        $this->connection->statement($this->grammar->compileDrop($table));
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
        } finally {
            if ($this->grammar->driver() === 'mysql') {
                $this->connection->statement('SET FOREIGN_KEY_CHECKS = 1');
            } elseif ($this->grammar->driver() === 'sqlite') {
                $this->connection->statement('PRAGMA foreign_keys = ON');
            }
        }
    }

    public function dropIfExists(string $table): void
    {
        $this->connection->statement($this->grammar->compileDrop($table, true));
    }

    public function hasColumn(string $table, string $column): bool
    {
        Blueprint::assertIdentifier($table);
        Blueprint::assertIdentifier($column);
        $table = $this->physicalTable($table);

        if ($this->grammar->driver() === 'sqlite') {
            $quote = '"' . str_replace('"', '""', $table) . '"';
            $rows = $this->connection->select(sprintf('PRAGMA table_info(%s)', $quote));

            return array_any($rows, fn($row) => ($row['name'] ?? null) === $column);
        }

        [$sql, $bindings] = $this->grammar->driver() === 'mysql'
            ? [
                'SELECT 1 FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ? LIMIT 1',
                [$this->connection->getDatabaseName(), $table, $column],
            ]
            : [
                'SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ? LIMIT 1',
                [$table, $column],
            ];

        return $this->connection->select($sql, $bindings) !== [];
    }

    public function hasTable(string $table): bool
    {
        Blueprint::assertIdentifier($table);
        $table = $this->physicalTable($table);

        [$sql, $bindings] = match ($this->grammar->driver()) {
            'mysql' => [
                'SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name = ? LIMIT 1',
                [$this->connection->getDatabaseName(), $table],
            ],
            'pgsql' => [
                'SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ? LIMIT 1',
                [$table],
            ],
            default => [
                "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1",
                [$table],
            ],
        };

        return $this->connection->select($sql, $bindings) !== [];
    }

    public function rename(string $from, string $to): void
    {
        $this->connection->statement($this->grammar->compileRename($from, $to));
    }

    public function supportsTransactionalDdl(): bool
    {
        return $this->grammar->supportsTransactionalDdl();
    }

    /** @param callable(Blueprint):void $definition */
    public function table(string $table, callable $definition): void
    {
        $this->execute($this->build($table, false, $definition));
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

    private function physicalTable(string $table): string
    {
        $prefix = $this->connection->getTablePrefix();

        if ($prefix === '' || str_contains($table, '.')) {
            return $table;
        }

        return str_starts_with($table, $prefix) ? $table : $prefix . $table;
    }
}
