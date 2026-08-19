<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Schema\Dialect;

use Infocyph\DBLayer\Schema\Blueprint;

abstract class AbstractMySqlSchemaDialect extends SchemaDialect
{
    #[\Override]
    public function isMySqlFamily(): bool
    {
        return true;
    }

    #[\Override]
    public function supportsTransactionalDdl(): bool
    {
        return false;
    }

    #[\Override]
    public function wrap(string $identifier): string
    {
        Blueprint::assertIdentifier($identifier);

        return implode('.', array_map(
            static fn(string $part): string => '`' . $part . '`',
            explode('.', $identifier),
        ));
    }

    #[\Override]
    public function compileDropForeign(string $table, string $name): string
    {
        return sprintf('ALTER TABLE %s DROP FOREIGN KEY %s', $this->wrap($table), $this->wrap($name));
    }

    #[\Override]
    public function compileDropIndex(string $table, string $name): string
    {
        return sprintf('DROP INDEX %s ON %s', $this->wrap($name), $this->wrap($table));
    }

    #[\Override]
    public function compileDropPrimary(string $table, ?string $name): string
    {
        return sprintf('ALTER TABLE %s DROP PRIMARY KEY', $this->wrap($table));
    }

    #[\Override]
    public function compileRenameIndex(string $table, string $from, string $to): string
    {
        return sprintf(
            'ALTER TABLE %s RENAME INDEX %s TO %s',
            $this->wrap($table),
            $this->wrap($from),
            $this->wrap($to),
        );
    }

    #[\Override]
    public function maxIdentifierLength(): ?int
    {
        return 64;
    }

    /** @return list<string> */
    #[\Override]
    public function beforeDropAllStatements(): array
    {
        return ['SET FOREIGN_KEY_CHECKS = 0'];
    }

    /** @return list<string> */
    #[\Override]
    public function afterDropAllStatements(): array
    {
        return ['SET FOREIGN_KEY_CHECKS = 1'];
    }

    /** @return array{sql:string,bindings:list<mixed>,key:?string,value:mixed} */
    #[\Override]
    public function columnExistsQuery(
        ?string $namespace,
        string $table,
        string $column,
        string $database,
    ): array {
        return [
            'sql' => 'SELECT 1 FROM information_schema.columns WHERE table_schema = ? '
                . 'AND table_name = ? AND column_name = ? LIMIT 1',
            'bindings' => [$namespace ?? $database, $table, $column],
            'key' => null,
            'value' => null,
        ];
    }

    /** @return array{sql:string,bindings:list<mixed>,key:?string,value:mixed} */
    #[\Override]
    public function tableExistsQuery(?string $namespace, string $table, string $database): array
    {
        return [
            'sql' => 'SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name = ? LIMIT 1',
            'bindings' => [$namespace ?? $database, $table],
            'key' => null,
            'value' => null,
        ];
    }

    /** @return array{sql:string,bindings:list<mixed>,key:string} */
    #[\Override]
    public function tablesQuery(string $database): array
    {
        return [
            'sql' => 'SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_type = ?',
            'bindings' => [$database, 'BASE TABLE'],
            'key' => 'table_name',
        ];
    }
}
