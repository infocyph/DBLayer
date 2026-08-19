<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Schema\Dialect;

use Infocyph\DBLayer\Exceptions\SchemaException;
use Infocyph\DBLayer\Schema\Blueprint;

/**
 * Driver-specific schema behavior shared by SchemaGrammar and SchemaManager.
 */
abstract class SchemaDialect
{
    abstract public function name(): string;

    public function isMySqlFamily(): bool
    {
        return false;
    }

    public function isPostgreSql(): bool
    {
        return false;
    }

    public function isSqlite(): bool
    {
        return false;
    }

    public function isSqlServer(): bool
    {
        return false;
    }

    public function supportsTransactionalDdl(): bool
    {
        return true;
    }

    public function supportsAddPrimaryKey(): bool
    {
        return true;
    }

    public function supportsAlterForeignKeys(): bool
    {
        return true;
    }

    public function wrap(string $identifier): string
    {
        Blueprint::assertIdentifier($identifier);

        return implode('.', array_map(
            static fn(string $part): string => '"' . $part . '"',
            explode('.', $identifier),
        ));
    }

    public function compileAddColumn(string $table, string $definition): string
    {
        return sprintf('ALTER TABLE %s ADD COLUMN %s', $this->wrap($table), $definition);
    }

    public function compileRenameTable(string $from, string $to): string
    {
        return sprintf('ALTER TABLE %s RENAME TO %s', $this->wrap($from), $this->wrap($to));
    }

    public function compileRenameColumn(string $table, string $from, string $to): string
    {
        return sprintf(
            'ALTER TABLE %s RENAME COLUMN %s TO %s',
            $this->wrap($table),
            $this->wrap($from),
            $this->wrap($to),
        );
    }

    public function compileDropForeign(string $table, string $name): string
    {
        return sprintf('ALTER TABLE %s DROP CONSTRAINT %s', $this->wrap($table), $this->wrap($name));
    }

    public function compileDropIndex(string $table, string $name): string
    {
        return 'DROP INDEX ' . $this->wrap($name);
    }

    public function compileDropPrimary(string $table, ?string $name): string
    {
        $name ??= str_replace('.', '_', $table) . '_pkey';

        return sprintf('ALTER TABLE %s DROP CONSTRAINT %s', $this->wrap($table), $this->wrap($name));
    }

    public function compileRenameIndex(string $table, string $from, string $to): string
    {
        return sprintf('ALTER INDEX %s RENAME TO %s', $this->wrap($from), $this->wrap($to));
    }

    public function defaultPrimaryName(string $table): ?string
    {
        return null;
    }

    public function maxIdentifierLength(): ?int
    {
        return null;
    }

    public function normalizeForeignAction(string $action): string
    {
        return $action;
    }

    /** @return list<string> */
    public function beforeDropAllStatements(): array
    {
        return [];
    }

    /** @return list<string> */
    public function afterDropAllStatements(): array
    {
        return [];
    }

    public function dropTableSuffix(): string
    {
        return '';
    }

    /** @return array{sql:string,bindings:list<mixed>,key:?string,value:mixed} */
    abstract public function columnExistsQuery(
        ?string $namespace,
        string $table,
        string $column,
        string $database,
    ): array;

    /** @return array{sql:string,bindings:list<mixed>,key:?string,value:mixed} */
    abstract public function tableExistsQuery(?string $namespace, string $table, string $database): array;

    /** @return array{sql:string,bindings:list<mixed>,key:string} */
    abstract public function tablesQuery(string $database): array;

    protected function unsupported(string $operation): never
    {
        throw SchemaException::unsupported($this->name(), $operation);
    }
}
