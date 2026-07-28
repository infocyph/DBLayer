<?php

declare(strict_types=1);

use Infocyph\DBLayer\Driver\MySQL\MySQLDriver;
use Infocyph\DBLayer\Driver\PostgreSQL\PostgreSQLDriver;
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

it('compiles MySQL and MariaDB plan dialects', function (): void {
    $driver = new MySQLDriver();

    expect($driver->compileExplain('select * from users'))
        ->toBe('EXPLAIN FORMAT=JSON select * from users')
        ->and($driver->compileExplain('select * from users', analyze: true, serverVersion: '8.4.0'))
        ->toBe('EXPLAIN ANALYZE select * from users')
        ->and($driver->compileExplain('select * from users', analyze: true, serverVersion: '11.4.2-MariaDB'))
        ->toBe('ANALYZE FORMAT=JSON select * from users');
});

it('rejects unsupported SQLite execution options', function (): void {
    (new SQLiteDriver())->compileExplain(
        'select * from users',
        analyze: true,
    );
})->throws(QueryException::class, 'SQLite supports query-plan inspection only');
