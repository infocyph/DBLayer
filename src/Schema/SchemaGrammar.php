<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Schema;

use Infocyph\DBLayer\Exceptions\SchemaException;

/**
 * Driver-aware DDL compiler.
 *
 * DDL values cannot be parameter-bound by PDO, so identifiers and supported
 * scalar defaults are validated and quoted here before execution.
 */
final readonly class SchemaGrammar
{
    private const array FOREIGN_ACTIONS = [
        'CASCADE',
        'NO ACTION',
        'RESTRICT',
        'SET DEFAULT',
        'SET NULL',
    ];

    private SchemaColumnCompiler $columnCompiler;

    public function __construct(
        private string $driver,
        private string $tablePrefix = '',
    ) {
        if (!in_array($driver, ['mysql', 'pgsql', 'sqlite'], true)) {
            throw SchemaException::unsupported($driver, 'schema compilation');
        }

        $this->columnCompiler = new SchemaColumnCompiler($driver);
    }

    /** @return non-empty-list<string> */
    public function compile(Blueprint $blueprint): array
    {
        return $blueprint->creating
            ? $this->compileCreate($blueprint)
            : $this->compileAlter($blueprint);
    }

    public function compileDrop(string $table, bool $ifExists = false): string
    {
        Blueprint::assertIdentifier($table);

        return sprintf('DROP TABLE %s%s', $ifExists ? 'IF EXISTS ' : '', $this->wrapTable($table));
    }

    public function compileRename(string $from, string $to): string
    {
        Blueprint::assertIdentifier($from);
        Blueprint::assertIdentifier($to);

        return sprintf('ALTER TABLE %s RENAME TO %s', $this->wrapTable($from), $this->wrapTable($to));
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function supportsTransactionalDdl(): bool
    {
        return $this->driver !== 'mysql';
    }

    /** @return non-empty-list<string> */
    private function compileAlter(Blueprint $blueprint): array
    {
        $statements = $this->compileAlterColumns($blueprint);

        foreach ($this->effectiveIndexes($blueprint) as $index) {
            if ($index['type'] === 'primary') {
                if ($this->driver === 'sqlite') {
                    throw SchemaException::unsupported($this->driver, 'add primary key');
                }

                $statements[] = sprintf(
                    'ALTER TABLE %s ADD %s',
                    $this->wrapTable($blueprint->table),
                    $this->compilePrimaryConstraint($index['columns'], $index['name']),
                );

                continue;
            }

            $statements[] = $this->compileCreateIndex(
                $blueprint->table,
                $index['columns'],
                $index['type'] === 'unique',
                $index['name'],
            );
        }

        foreach ($blueprint->foreignKeys() as $foreign) {
            if ($this->driver === 'sqlite') {
                throw SchemaException::unsupported($this->driver, 'add foreign key to existing table');
            }

            $statements[] = sprintf(
                'ALTER TABLE %s ADD %s',
                $this->wrapTable($blueprint->table),
                $this->compileForeignConstraint($blueprint->table, $foreign),
            );
        }

        foreach ($blueprint->commands() as $command) {
            $statements[] = $this->compileCommand($blueprint->table, $command);
        }

        if ($statements === []) {
            throw SchemaException::invalid(sprintf('No schema changes were defined for table "%s".', $blueprint->table));
        }

        return $statements;
    }

    /** @return list<string> */
    private function compileAlterColumns(Blueprint $blueprint): array
    {
        $statements = [];
        $table = $this->wrapTable($blueprint->table);

        foreach ($blueprint->columns() as $column) {
            if ($column->change) {
                array_push($statements, ...$this->columnCompiler->compileChange($table, $column));

                continue;
            }

            $statements[] = sprintf(
                'ALTER TABLE %s ADD COLUMN %s',
                $table,
                $this->columnCompiler->compile($column),
            );
        }

        return $statements;
    }

    /**
     * @param array{type:string,from?:string,to?:string,name?:string} $command
     */
    private function compileCommand(string $table, array $command): string
    {
        $wrappedTable = $this->wrapTable($table);

        return match ($command['type']) {
            'dropColumn' => sprintf(
                'ALTER TABLE %s DROP COLUMN %s',
                $wrappedTable,
                $this->wrap($command['name'] ?? ''),
            ),
            'renameColumn' => sprintf(
                'ALTER TABLE %s RENAME COLUMN %s TO %s',
                $wrappedTable,
                $this->wrap($command['from'] ?? ''),
                $this->wrap($command['to'] ?? ''),
            ),
            'dropIndex' => $this->compileDropIndex($table, $command['name'] ?? ''),
            'dropPrimary' => $this->compileDropPrimary($table, $command['name'] ?? null),
            'dropUnique' => $this->compileDropIndex($table, $command['name'] ?? ''),
            'dropForeign' => $this->compileDropForeign($table, $command['name'] ?? ''),
            'renameIndex' => $this->compileRenameIndex(
                $table,
                $command['from'] ?? '',
                $command['to'] ?? '',
            ),
            default => throw SchemaException::invalid(sprintf('Unknown schema command "%s".', $command['type'])),
        };
    }

    /** @return non-empty-list<string> */
    private function compileCreate(Blueprint $blueprint): array
    {
        if ($blueprint->columns() === []) {
            throw SchemaException::invalid('A new table requires at least one column.');
        }

        if (array_any(
            $blueprint->columns(),
            static fn(ColumnDefinition $column): bool => $column->change,
        )) {
            throw SchemaException::invalid('Columns cannot be marked change() while creating a table.');
        }

        $definitions = array_map($this->columnCompiler->compile(...), $blueprint->columns());
        $primaryCount = count(array_filter(
            $blueprint->columns(),
            static fn(ColumnDefinition $column): bool => $column->primary,
        )) + count(array_filter(
            $blueprint->indexes(),
            static fn(array $index): bool => $index['type'] === 'primary',
        ));

        if ($primaryCount > 1) {
            throw SchemaException::invalid('A table may define only one primary-key constraint.');
        }

        foreach ($blueprint->indexes() as $index) {
            if ($index['type'] === 'primary') {
                $definitions[] = $this->compilePrimaryConstraint($index['columns'], $index['name']);
            }
        }

        foreach ($blueprint->foreignKeys() as $foreign) {
            $definitions[] = $this->compileForeignConstraint($blueprint->table, $foreign);
        }

        $statements = [
            sprintf(
                'CREATE TABLE %s (%s)',
                $this->wrapTable($blueprint->table),
                implode(', ', $definitions),
            ),
        ];

        foreach ($this->effectiveIndexes($blueprint) as $index) {
            if ($index['type'] !== 'primary') {
                $statements[] = $this->compileCreateIndex(
                    $blueprint->table,
                    $index['columns'],
                    $index['type'] === 'unique',
                    $index['name'],
                );
            }
        }

        return $statements;
    }

    /** @param list<string> $columns */
    private function compileCreateIndex(
        string $table,
        array $columns,
        bool $unique,
        ?string $name,
    ): string {
        $resolvedName = $name ?? $this->defaultIndexName($table, $columns, $unique ? 'unique' : 'index');

        return sprintf(
            'CREATE %sINDEX %s ON %s (%s)',
            $unique ? 'UNIQUE ' : '',
            $this->wrap($resolvedName),
            $this->wrapTable($table),
            $this->wrapList($columns),
        );
    }

    private function compileDropForeign(string $table, string $name): string
    {
        if ($this->driver === 'sqlite') {
            throw SchemaException::unsupported($this->driver, 'drop foreign key');
        }

        return sprintf(
            'ALTER TABLE %s DROP %s %s',
            $this->wrapTable($table),
            $this->driver === 'mysql' ? 'FOREIGN KEY' : 'CONSTRAINT',
            $this->wrap($name),
        );
    }

    private function compileDropIndex(string $table, string $name): string
    {
        return $this->driver === 'mysql'
            ? sprintf('DROP INDEX %s ON %s', $this->wrap($name), $this->wrapTable($table))
            : sprintf('DROP INDEX %s', $this->wrap($name));
    }

    private function compileDropPrimary(string $table, ?string $name): string
    {
        if ($this->driver === 'sqlite') {
            throw SchemaException::unsupported('sqlite', 'drop primary key');
        }

        if ($this->driver === 'mysql') {
            return sprintf('ALTER TABLE %s DROP PRIMARY KEY', $this->wrapTable($table));
        }

        $name ??= str_replace('.', '_', $table) . '_pkey';

        return sprintf(
            'ALTER TABLE %s DROP CONSTRAINT %s',
            $this->wrapTable($table),
            $this->wrap($name),
        );
    }

    private function compileForeignConstraint(string $table, ForeignKeyDefinition $foreign): string
    {
        if ($foreign->referencedTable === '') {
            throw SchemaException::invalid('A foreign key must define its referenced table with on().');
        }

        Blueprint::assertIdentifier($foreign->referencedTable);

        foreach ($foreign->referencedColumns as $column) {
            Blueprint::assertIdentifier($column);
        }

        $name = $foreign->name ?? $this->defaultIndexName($table, $foreign->columns, 'foreign');
        $sql = sprintf(
            'CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s)',
            $this->wrap($name),
            $this->wrapList($foreign->columns),
            $this->wrapTable($foreign->referencedTable),
            $this->wrapList($foreign->referencedColumns),
        );

        if ($foreign->onDelete !== null) {
            $sql .= ' ON DELETE ' . $this->foreignAction($foreign->onDelete);
        }

        if ($foreign->onUpdate !== null) {
            $sql .= ' ON UPDATE ' . $this->foreignAction($foreign->onUpdate);
        }

        return $sql;
    }

    /** @param list<string> $columns */
    private function compilePrimaryConstraint(array $columns, ?string $name): string
    {
        $prefix = $name === null ? '' : 'CONSTRAINT ' . $this->wrap($name) . ' ';

        return sprintf('%sPRIMARY KEY (%s)', $prefix, $this->wrapList($columns));
    }

    private function compileRenameIndex(string $table, string $from, string $to): string
    {
        if ($this->driver === 'sqlite') {
            throw SchemaException::unsupported('sqlite', 'rename index');
        }

        if ($this->driver === 'mysql') {
            return sprintf(
                'ALTER TABLE %s RENAME INDEX %s TO %s',
                $this->wrapTable($table),
                $this->wrap($from),
                $this->wrap($to),
            );
        }

        return sprintf('ALTER INDEX %s RENAME TO %s', $this->wrap($from), $this->wrap($to));
    }

    /** @param list<string> $columns */
    private function defaultIndexName(string $table, array $columns, string $type): string
    {
        $name = strtolower(str_replace('.', '_', $table) . '_' . implode('_', $columns) . '_' . $type);

        if ($this->driver === 'mysql' && strlen($name) > 64) {
            return substr($name, 0, 55) . '_' . substr(hash('xxh3', $name), 0, 8);
        }

        return $name;
    }

    /**
     * Merge explicitly declared indexes with column-level index modifiers.
     *
     * @return list<array{type:string,columns:list<string>,name:?string}>
     */
    private function effectiveIndexes(Blueprint $blueprint): array
    {
        $indexes = $blueprint->indexes();

        foreach ($blueprint->columns() as $column) {
            if ($column->unique) {
                $indexes[] = ['type' => 'unique', 'columns' => [$column->name], 'name' => null];
            } elseif ($column->index) {
                $indexes[] = ['type' => 'index', 'columns' => [$column->name], 'name' => null];
            }
        }

        return $indexes;
    }

    private function foreignAction(string $action): string
    {
        if (!in_array($action, self::FOREIGN_ACTIONS, true)) {
            throw SchemaException::invalid(sprintf('Unsupported foreign-key action "%s".', $action));
        }

        return $action;
    }

    private function wrap(string $identifier): string
    {
        Blueprint::assertIdentifier($identifier);
        $quote = $this->driver === 'mysql' ? '`' : '"';

        return implode('.', array_map(
            static fn(string $part): string => $quote . $part . $quote,
            explode('.', $identifier),
        ));
    }

    /** @param list<string> $identifiers */
    private function wrapList(array $identifiers): string
    {
        return implode(', ', array_map($this->wrap(...), $identifiers));
    }

    private function wrapTable(string $table): string
    {
        if ($this->tablePrefix !== '' && !str_contains($table, '.') && !str_starts_with($table, $this->tablePrefix)) {
            $table = $this->tablePrefix . $table;
        }

        return $this->wrap($table);
    }
}
