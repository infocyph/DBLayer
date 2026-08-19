<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Schema;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Exceptions\MigrationException;
use Infocyph\DBLayer\Exceptions\SchemaException;
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

    public function dropAllTables(bool $authorized = false): void
    {
        if (!$authorized) {
            throw MigrationException::destructiveDenied('drop-all-tables');
        }

        $tables = $this->tables();
        if ($tables === []) {
            return;
        }

        foreach ($this->grammar->beforeDropAllStatements() as $statement) {
            $this->connection->statement($statement);
        }

        try {
            foreach ($tables as $table) {
                $this->connection->statement(
                    $this->grammar->compileDrop($table, true) . $this->grammar->dropTableSuffix(),
                );
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

        $query = $this->grammar->columnExistsQuery(
            $namespace,
            $table,
            $column,
            $this->connection->getDatabaseName(),
        );
        $rows = $this->connection->select($query['sql'], $query['bindings']);

        if ($query['key'] === null) {
            return $rows !== [];
        }

        return array_any(
            $rows,
            fn(array $row): bool => ($row[$query['key']] ?? null) === $query['value'],
        );
    }

    public function hasTable(string $table): bool
    {
        Blueprint::assertIdentifier($table);
        [$namespace, $table] = $this->splitQualifiedTable($this->physicalTable($table));

        $query = $this->grammar->tableExistsQuery(
            $namespace,
            $table,
            $this->connection->getDatabaseName(),
        );
        $rows = $this->connection->select($query['sql'], $query['bindings']);

        if ($query['key'] === null) {
            return $rows !== [];
        }

        return array_any(
            $rows,
            fn(array $row): bool => ($row[$query['key']] ?? null) === $query['value'],
        );
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

    /** @return list<string> */
    public function tables(): array
    {
        $query = $this->grammar->tablesQuery($this->connection->getDatabaseName());
        $key = $query['key'];
        $tables = [];

        foreach ($this->connection->select($query['sql'], $query['bindings']) as $row) {
            $name = $row[$key] ?? null;

            if (!is_string($name)) {
                throw SchemaException::invalid(
                    sprintf('Database returned an invalid table name for key "%s".', $key),
                );
            }

            $tables[] = $name;
        }

        return $tables;
    }

    /**
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
        foreach ($this->grammar->afterDropAllStatements() as $statement) {
            $this->connection->statement($statement);
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
