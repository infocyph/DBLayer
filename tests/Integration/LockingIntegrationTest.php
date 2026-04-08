<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Exceptions\ConnectionException;

it('compiles lock clauses according to each SQL dialect', function (): void {
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ], 'sqlite_conn');

    DB::addConnection([
        'driver' => 'mysql',
        'host' => 'localhost',
        'database' => 'app',
        'username' => 'root',
        'password' => '',
    ], 'mysql_conn');

    DB::addConnection([
        'driver' => 'pgsql',
        'host' => 'localhost',
        'database' => 'app',
        'username' => 'postgres',
        'password' => '',
    ], 'pgsql_conn');

    $sqliteUpdateSql = strtolower(DB::table('users', 'sqlite_conn')->lockForUpdate()->toSql());
    $sqliteSharedSql = strtolower(DB::table('users', 'sqlite_conn')->sharedLock()->toSql());
    expect($sqliteUpdateSql)->not->toContain('for update');
    expect($sqliteUpdateSql)->not->toContain('share');
    expect($sqliteSharedSql)->not->toContain('for share');
    expect($sqliteSharedSql)->not->toContain('share mode');

    $mysqlUpdateSql = strtolower(DB::table('users', 'mysql_conn')->lockForUpdate()->toSql());
    $mysqlSharedSql = strtolower(DB::table('users', 'mysql_conn')->sharedLock()->toSql());
    expect($mysqlUpdateSql)->toContain('for update');
    expect($mysqlSharedSql)->toContain('lock in share mode');

    $pgsqlUpdateSql = strtolower(DB::table('users', 'pgsql_conn')->lockForUpdate()->toSql());
    $pgsqlSharedSql = strtolower(DB::table('users', 'pgsql_conn')->sharedLock()->toSql());
    expect($pgsqlUpdateSql)->toContain('for update');
    expect($pgsqlSharedSql)->toContain('for share');
});

it('surfaces sqlite write-lock contention across concurrent connections', function (): void {
    $databaseFile = '/tmp/dblayer-lock-' . bin2hex(random_bytes(8)) . '.sqlite';

    DB::addConnection([
        'driver' => 'sqlite',
        'database' => $databaseFile,
        'options' => [PDO::ATTR_TIMEOUT => 1],
    ], 'writer_one');

    DB::addConnection([
        'driver' => 'sqlite',
        'database' => $databaseFile,
        'options' => [PDO::ATTR_TIMEOUT => 1],
    ], 'writer_two');

    DB::statement(
        'create table locks (
            id integer primary key autoincrement,
            value integer
        )',
        [],
        'writer_one',
    );

    DB::beginTransaction('writer_one');
    DB::statement('insert into locks (value) values (1)', [], 'writer_one');

    try {
        expect(static function (): bool {
            return DB::statement('insert into locks (value) values (2)', [], 'writer_two');
        })->toThrow(ConnectionException::class);
    } finally {
        DB::rollBack('writer_one');

        if (is_file($databaseFile)) {
            unlink($databaseFile);
        }
    }
});
