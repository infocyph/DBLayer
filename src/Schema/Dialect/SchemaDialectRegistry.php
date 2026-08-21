<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Schema\Dialect;

use Infocyph\DBLayer\Exceptions\SchemaException;

final class SchemaDialectRegistry
{
    private function __construct() {}

    public static function resolve(string $driver): SchemaDialect
    {
        return match (strtolower($driver)) {
            'mysql', 'pdo_mysql', 'mysqli' => new MySQLSchemaDialect(),
            'mariadb' => new MariaDBSchemaDialect(),
            'pgsql', 'postgres', 'postgresql', 'psql', 'pdo_pgsql' => new PostgreSQLSchemaDialect(),
            'mssql', 'sqlsrv', 'sqlserver', 'pdo_sqlsrv' => new SQLServerSchemaDialect(),
            'sqlite', 'sqlite3', 'pdo_sqlite' => new SQLiteSchemaDialect(),
            default => throw SchemaException::unsupported($driver, 'schema compilation'),
        };
    }
}
