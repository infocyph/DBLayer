<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\DBLayer\Driver\MariaDB\MariaDBDriver;
use Infocyph\DBLayer\Driver\MySQL\MySQLDriver;
use Infocyph\DBLayer\Driver\PostgreSQL\PostgreSQLDriver;
use Infocyph\DBLayer\Driver\SQLServer\SQLServerDriver;
use Infocyph\DBLayer\Driver\SQLite\SQLiteDriver;
use Infocyph\DBLayer\Exceptions\QueryException;

it('compiles PostgreSQL JSON explain options explicitly', function (): void {
    $sql = (new PostgreSQLDriver())->compileExplain(
        'select * from users where id = ?',
        analyze: true,
        buffers: true,
        verbose: true,
    );

    expect($sql)->toBe(
        'EXPLAIN (FORMAT JSON, ANALYZE TRUE, BUFFERS TRUE, VERBOSE TRUE) select * from users where id = ?',
    );
});

it('requires PostgreSQL analysis before collecting buffers', function (): void {
    (new PostgreSQLDriver())->compileExplain(
        'select * from users',
        buffers: true,
    );
})->throws(QueryException::class, 'PostgreSQL BUFFERS requires analyze=true');

it('compiles MySQL and MariaDB plan dialects through separate drivers', function (): void {
    $mysql = new MySQLDriver();
    $maria = new MariaDBDriver();

    expect($mysql->compileExplain('select * from users'))
        ->toBe('EXPLAIN FORMAT=JSON select * from users')
        ->and($mysql->compileExplain('select * from users', analyze: true))
        ->toBe('EXPLAIN ANALYZE select * from users')
        ->and($maria->compileExplain('select * from users'))
        ->toBe('EXPLAIN FORMAT=JSON select * from users')
        ->and($maria->compileExplain('select * from users', analyze: true))
        ->toBe('ANALYZE FORMAT=JSON select * from users');
});

it('rejects unsupported SQLite execution options', function (): void {
    (new SQLiteDriver())->compileExplain(
        'select * from users',
        analyze: true,
    );
})->throws(QueryException::class, 'SQLite supports query-plan inspection only');

it('keeps SQL Server execution plans on the connection-scoped executor', function (): void {
    expect(static fn(): string => (new SQLServerDriver())->compileExplain('select 1'))
        ->toThrow(QueryException::class, 'session scoped');

    $connection = new Connection(new ConnectionConfig([
        'driver' => 'mssql',
        'database' => 'app',
        'username' => 'app',
    ]), 'mssql-explain');
    $result = null;

    $queries = $connection->pretend(function (Connection $connection) use (&$result): void {
        $result = $connection->explain('select 1');
    });

    expect($result)->toBe([])
        ->and($queries)->toBe([]);
});
