<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Schema\Dialect;

final class PostgreSQLSchemaDialect extends SchemaDialect
{
    #[\Override]
    public function columnExistsQuery(?string $namespace, string $table, string $column, string $database): array
    {
        unset($database);

        return ['sql' => 'SELECT 1 FROM information_schema.columns WHERE table_schema = COALESCE(?, current_schema()) AND table_name = ? AND column_name = ? LIMIT 1', 'bindings' => [$namespace, $table, $column], 'key' => null, 'value' => null];
    }

    #[\Override]
    public function dropTableSuffix(): string
    {
        return ' CASCADE';
    }

    #[\Override]
    public function isPostgreSql(): bool
    {
        return true;
    }

    #[\Override]
    public function name(): string
    {
        return 'pgsql';
    }

    #[\Override]
    public function tableExistsQuery(?string $namespace, string $table, string $database): array
    {
        unset($database);

        return ['sql' => 'SELECT 1 FROM information_schema.tables WHERE table_schema = COALESCE(?, current_schema()) AND table_name = ? LIMIT 1', 'bindings' => [$namespace, $table], 'key' => null, 'value' => null];
    }

    #[\Override]
    public function tablesQuery(string $database): array
    {
        unset($database);

        return ['sql' => 'SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname = current_schema()', 'bindings' => [], 'key' => 'tablename'];
    }
}
