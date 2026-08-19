<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Schema\Dialect;

final class SQLiteSchemaDialect extends SchemaDialect
{
    #[\Override]
    public function afterDropAllStatements(): array
    {
        return ['PRAGMA foreign_keys = ON'];
    }

    #[\Override]
    public function beforeDropAllStatements(): array
    {
        return ['PRAGMA foreign_keys = OFF'];
    }

    #[\Override]
    public function columnExistsQuery(?string $namespace, string $table, string $column, string $database): array
    {
        unset($database);
        $quote = '"' . str_replace('"', '""', $table) . '"';
        $sql = $namespace === null ? sprintf('PRAGMA table_info(%s)', $quote) : sprintf('PRAGMA "%s".table_info(%s)', str_replace('"', '""', $namespace), $quote);

        return ['sql' => $sql, 'bindings' => [], 'key' => 'name', 'value' => $column];
    }

    #[\Override]
    public function compileDropForeign(string $table, string $name): string
    {
        unset($table, $name);
        $this->unsupported('drop foreign key');
    }

    #[\Override]
    public function compileDropPrimary(string $table, ?string $name): string
    {
        unset($table, $name);
        $this->unsupported('drop primary key');
    }

    #[\Override]
    public function compileRenameIndex(string $table, string $from, string $to): string
    {
        unset($table, $from, $to);
        $this->unsupported('rename index');
    }

    #[\Override]
    public function isSqlite(): bool
    {
        return true;
    }

    #[\Override]
    public function name(): string
    {
        return 'sqlite';
    }

    #[\Override]
    public function supportsAddPrimaryKey(): bool
    {
        return false;
    }

    #[\Override]
    public function supportsAlterForeignKeys(): bool
    {
        return false;
    }

    #[\Override]
    public function tableExistsQuery(?string $namespace, string $table, string $database): array
    {
        unset($database);
        $prefix = $namespace === null ? '' : '"' . str_replace('"', '""', $namespace) . '".';

        return ['sql' => 'SELECT 1 FROM ' . $prefix . "sqlite_master WHERE type = 'table' AND name = ? LIMIT 1", 'bindings' => [$table], 'key' => null, 'value' => null];
    }

    #[\Override]
    public function tablesQuery(string $database): array
    {
        unset($database);

        return ['sql' => "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'", 'bindings' => [], 'key' => 'name'];
    }
}
