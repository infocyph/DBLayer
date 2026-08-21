<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Schema\Dialect;

use Infocyph\DBLayer\Exceptions\SchemaException;
use Infocyph\DBLayer\Schema\Blueprint;

final class SQLServerSchemaDialect extends SchemaDialect
{
    #[\Override]
    public function beforeDropAllStatements(): array
    {
        return ["DECLARE @dblayer_sql nvarchar(max) = N''; SELECT @dblayer_sql += N'ALTER TABLE ' + QUOTENAME(SCHEMA_NAME(t.schema_id)) + N'.' + QUOTENAME(t.name) + N' DROP CONSTRAINT ' + QUOTENAME(fk.name) + N';' FROM sys.foreign_keys fk JOIN sys.tables t ON t.object_id = fk.parent_object_id WHERE t.is_ms_shipped = 0; IF @dblayer_sql <> N'' EXEC sp_executesql @dblayer_sql"];
    }

    #[\Override]
    public function columnExistsQuery(?string $namespace, string $table, string $column, string $database): array
    {
        return ['sql' => "SELECT TOP (1) 1 FROM information_schema.columns WHERE table_catalog = ? AND table_schema = COALESCE(?, 'dbo') AND table_name = ? AND column_name = ?", 'bindings' => [$database, $namespace, $table, $column], 'key' => null, 'value' => null];
    }

    #[\Override]
    public function compileAddColumn(string $table, string $definition): string
    {
        return sprintf('ALTER TABLE %s ADD %s', $this->wrap($table), $definition);
    }

    #[\Override]
    public function compileDropIndex(string $table, string $name): string
    {
        return sprintf('DROP INDEX %s ON %s', $this->wrap($name), $this->wrap($table));
    }

    #[\Override]
    public function compileDropPrimary(string $table, ?string $name): string
    {
        $name ??= $this->defaultPrimaryName($table);

        return sprintf('ALTER TABLE %s DROP CONSTRAINT %s', $this->wrap($table), $this->wrap($name));
    }

    #[\Override]
    public function compileRenameColumn(string $table, string $from, string $to): string
    {
        return sprintf("EXEC sp_rename N'%s', N'%s', N'COLUMN'", str_replace("'", "''", $table . '.' . $from), str_replace("'", "''", $to));
    }

    #[\Override]
    public function compileRenameIndex(string $table, string $from, string $to): string
    {
        return sprintf("EXEC sp_rename N'%s', N'%s', N'INDEX'", str_replace("'", "''", $table . '.' . $from), str_replace("'", "''", $to));
    }

    #[\Override]
    public function compileRenameTable(string $from, string $to): string
    {
        $fromParts = explode('.', $from);
        $toParts = explode('.', $to);
        $fromSchema = count($fromParts) > 1 ? implode('.', array_slice($fromParts, 0, -1)) : null;
        $toSchema = count($toParts) > 1 ? implode('.', array_slice($toParts, 0, -1)) : null;
        if ($fromSchema !== $toSchema) {
            throw SchemaException::unsupported('mssql', 'rename table across schemas');
        }

        return sprintf("EXEC sp_rename N'%s', N'%s'", str_replace("'", "''", $from), str_replace("'", "''", end($toParts)));
    }

    #[\Override]
    public function defaultPrimaryName(string $table): string
    {
        $name = strtolower(str_replace('.', '_', $table) . '_primary');
        $max = $this->maxIdentifierLength();
        if (strlen($name) <= $max) {
            return $name;
        }
        $hash = '_' . substr(hash('xxh3', $name), 0, 8);

        return substr($name, 0, $max - strlen($hash)) . $hash;
    }

    #[\Override]
    public function isSqlServer(): bool
    {
        return true;
    }

    #[\Override]
    public function maxIdentifierLength(): int
    {
        return 128;
    }

    #[\Override]
    public function name(): string
    {
        return 'mssql';
    }

    #[\Override]
    public function normalizeForeignAction(string $action): string
    {
        return $action === 'RESTRICT' ? 'NO ACTION' : $action;
    }

    #[\Override]
    public function tableExistsQuery(?string $namespace, string $table, string $database): array
    {
        return ['sql' => "SELECT TOP (1) 1 FROM information_schema.tables WHERE table_catalog = ? AND table_schema = COALESCE(?, 'dbo') AND table_name = ?", 'bindings' => [$database, $namespace, $table], 'key' => null, 'value' => null];
    }

    #[\Override]
    public function tablesQuery(string $database): array
    {
        return ['sql' => "SELECT table_schema + '.' + table_name AS qualified_name FROM information_schema.tables WHERE table_catalog = ? AND table_type = 'BASE TABLE' AND table_schema NOT IN ('sys', 'INFORMATION_SCHEMA')", 'bindings' => [$database], 'key' => 'qualified_name'];
    }

    #[\Override]
    public function wrap(string $identifier): string
    {
        Blueprint::assertIdentifier($identifier);

        return implode('.', array_map(static fn(string $part): string => '[' . str_replace(']', ']]', $part) . ']', explode('.', $identifier)));
    }
}
