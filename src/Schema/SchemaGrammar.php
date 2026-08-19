<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Schema;

use Infocyph\DBLayer\Exceptions\SchemaException;
use Infocyph\DBLayer\Schema\Dialect\SchemaDialect;
use Infocyph\DBLayer\Schema\Dialect\SchemaDialectRegistry;

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
    private SchemaDialect $dialect;

    public function __construct(string $driver, private string $tablePrefix = '')
    {
        $this->dialect = SchemaDialectRegistry::resolve($driver);
        $this->columnCompiler = new SchemaColumnCompiler($this->dialect);
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

        return $this->dialect->compileRenameTable($this->physicalTable($from), $this->physicalTable($to));
    }

    public function driver(): string
    {
        return $this->dialect->name();
    }

    /** @return list<string> */
    public function beforeDropAllStatements(): array
    {
        return $this->dialect->beforeDropAllStatements();
    }

    /** @return list<string> */
    public function afterDropAllStatements(): array
    {
        return $this->dialect->afterDropAllStatements();
    }

    public function dropTableSuffix(): string
    {
        return $this->dialect->dropTableSuffix();
    }

    /** @return array{sql:string,bindings:list<mixed>,key:?string,value:mixed} */
    public function columnExistsQuery(?string $namespace, string $table, string $column, string $database): array
    {
        return $this->dialect->columnExistsQuery($namespace, $table, $column, $database);
    }

    /** @return array{sql:string,bindings:list<mixed>,key:?string,value:mixed} */
    public function tableExistsQuery(?string $namespace, string $table, string $database): array
    {
        return $this->dialect->tableExistsQuery($namespace, $table, $database);
    }

    /** @return array{sql:string,bindings:list<mixed>,key:string} */
    public function tablesQuery(string $database): array
    {
        return $this->dialect->tablesQuery($database);
    }

    public function supportsTransactionalDdl(): bool
    {
        return $this->dialect->supportsTransactionalDdl();
    }

    /** @return non-empty-list<string> */
    private function compileAlter(Blueprint $blueprint): array
    {
        $statements = $this->compileAlterColumns($blueprint);

        foreach ($this->effectiveIndexes($blueprint) as $index) {
            if ($index['type'] === 'primary') {
                if (!$this->dialect->supportsAddPrimaryKey()) {
                    throw SchemaException::unsupported($this->driver(), 'add primary key');
                }

                $statements[] = sprintf(
                    'ALTER TABLE %s ADD %s',
                    $this->wrapTable($blueprint->table),
                    $this->compilePrimaryConstraint($index['columns'], $index['name'], $blueprint->table),
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
            if (!$this->dialect->supportsAlterForeignKeys()) {
                throw SchemaException::unsupported($this->driver(), 'add foreign key to existing table');
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

            $statements[] = $this->dialect->compileAddColumn(
                $this->physicalTable($blueprint->table),
                $this->columnCompiler->compile($column),
            );
        }

        return $statements;
    }

    /** @param array{type:string,from?:string,to?:string,name?:string} $command */
    private function compileCommand(string $table, array $command): string
    {
        $wrappedTable = $this->wrapTable($table);

        return match ($command['type']) {
            'dropColumn' => sprintf('ALTER TABLE %s DROP COLUMN %s', $wrappedTable, $this->wrap($command['name'] ?? '')),
            'renameColumn' => $this->dialect->compileRenameColumn(
                $this->physicalTable($table),
                $command['from'] ?? '',
                $command['to'] ?? '',
            ),
            'dropIndex' => $this->compileDropIndex($table, $command['name'] ?? ''),
            'dropPrimary' => $this->compileDropPrimary($table, $command['name'] ?? null),
            'dropUnique' => $this->compileDropIndex($table, $command['name'] ?? ''),
            'dropForeign' => $this->compileDropForeign($table, $command['name'] ?? ''),
            'renameIndex' => $this->compileRenameIndex($table, $command['from'] ?? '', $command['to'] ?? ''),
            default => throw SchemaException::invalid(sprintf('Unknown schema command "%s".', $command['type'])),
        };
    }

    /** @return non-empty-list<string> */
    private function compileCreate(Blueprint $blueprint): array
    {
        $columns = $blueprint->columns();

        if ($columns === []) {
            throw SchemaException::invalid('A new table requires at least one column.');
        }

        if (array_any($columns, static fn(ColumnDefinition $column): bool => $column->change)) {
            throw SchemaException::invalid('Columns cannot be marked change() while creating a table.');
        }

        $definitions = array_map($this->columnCompiler->compile(...), $columns);
        $primaryCount = count(array_filter(
            $columns,
            static fn(ColumnDefinition $column): bool => $column->primary,
        )) + count(array_filter(
            $blueprint->indexes(),
            static fn(array $index): bool => $index['type'] === 'primary',
        ));

        if ($primaryCount > 1) {
            throw SchemaException::invalid('A table may define only one primary-key constraint.');
        }

        $primaryColumns = array_values(array_map(
            static fn(ColumnDefinition $column): string => $column->name,
            array_filter($columns, static fn(ColumnDefinition $column): bool => $column->primary),
        ));

        if ($primaryColumns !== [] && $this->dialect->defaultPrimaryName($this->physicalTable($blueprint->table)) !== null) {
            $definitions[] = $this->compilePrimaryConstraint($primaryColumns, null, $blueprint->table);
        }

        foreach ($blueprint->indexes() as $index) {
            if ($index['type'] === 'primary') {
                $definitions[] = $this->compilePrimaryConstraint($index['columns'], $index['name'], $blueprint->table);
            }
        }

        foreach ($blueprint->foreignKeys() as $foreign) {
            $definitions[] = $this->compileForeignConstraint($blueprint->table, $foreign);
        }

        $statements = [sprintf(
            'CREATE TABLE %s (%s)',
            $this->wrapTable($blueprint->table),
            implode(', ', $definitions),
        )];

        foreach ($this->effectiveIndexes($blueprint) as $index) {
            if ($index['type'] === 'primary') {
                continue;
            }

            $statements[] = $this->compileCreateIndex(
                $blueprint->table,
                $index['columns'],
                $index['type'] === 'unique',
                $index['name'],
            );
        }

        return $statements;
    }

    /** @param list<string> $columns */
    private function compileCreateIndex(string $table, array $columns, bool $unique, ?string $name): string
    {
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
        return $this->dialect->compileDropForeign($this->physicalTable($table), $name);
    }

    private function compileDropIndex(string $table, string $name): string
    {
        return $this->dialect->compileDropIndex($this->physicalTable($table), $name);
    }

    private function compileDropPrimary(string $table, ?string $name): string
    {
        return $this->dialect->compileDropPrimary($this->physicalTable($table), $name);
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
    private function compilePrimaryConstraint(array $columns, ?string $name, ?string $table = null): string
    {
        if ($name === null && $table !== null) {
            $name = $this->dialect->defaultPrimaryName($this->physicalTable($table));
        }

        $prefix = $name === null ? '' : 'CONSTRAINT ' . $this->wrap($name) . ' ';

        return sprintf('%sPRIMARY KEY (%s)', $prefix, $this->wrapList($columns));
    }

    private function compileRenameIndex(string $table, string $from, string $to): string
    {
        return $this->dialect->compileRenameIndex($this->physicalTable($table), $from, $to);
    }

    /** @param list<string> $columns */
    private function defaultIndexName(string $table, array $columns, string $type): string
    {
        $name = strtolower(str_replace('.', '_', $table) . '_' . implode('_', $columns) . '_' . $type);
        $maxLength = $this->dialect->maxIdentifierLength();

        if ($maxLength !== null && strlen($name) > $maxLength) {
            $hash = '_' . substr(hash('xxh3', $name), 0, 8);

            return substr($name, 0, $maxLength - strlen($hash)) . $hash;
        }

        return $name;
    }

    /** @return list<array{type:string,columns:list<string>,name:?string}> */
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

        return $this->dialect->normalizeForeignAction($action);
    }

    private function wrap(string $identifier): string
    {
        return $this->dialect->wrap($identifier);
    }

    /** @param list<string> $identifiers */
    private function wrapList(array $identifiers): string
    {
        return implode(', ', array_map($this->wrap(...), $identifiers));
    }

    private function physicalTable(string $table): string
    {
        if ($this->tablePrefix !== '' && !str_contains($table, '.') && !str_starts_with($table, $this->tablePrefix)) {
            return $this->tablePrefix . $table;
        }

        return $table;
    }

    private function wrapTable(string $table): string
    {
        return $this->wrap($this->physicalTable($table));
    }
}
