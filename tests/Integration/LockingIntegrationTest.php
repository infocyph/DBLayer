<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Exceptions\ConnectionException;

it('compiles lock clauses according to each SQL dialect', function (string $driver): void {
    $connectionName = 'lock_compile_' . $driver;
    dblayerAddConnectionForDriver($driver, $connectionName);
    $schemaDriver = dblayerConnectionDriver($connectionName);

    $updateSql = strtolower(DB::table('users', $connectionName)->lockForUpdate()->toSql());
    $sharedSql = strtolower(DB::table('users', $connectionName)->sharedLock()->toSql());

    if ($schemaDriver === 'sqlite') {
        expect($updateSql)->not->toContain('for update');
        expect($updateSql)->not->toContain('share');
        expect($sharedSql)->not->toContain('for share');
        expect($sharedSql)->not->toContain('share mode');

        return;
    }

    if ($schemaDriver === 'mssql') {
        expect($updateSql)->toContain('with (updlock, rowlock)')
            ->and($sharedSql)->toContain('with (holdlock, rowlock)');

        return;
    }

    expect($updateSql)->toContain('for update');

    if (in_array($schemaDriver, ['mysql', 'mariadb'], true)) {
        expect($sharedSql)->toContain('lock in share mode');

        return;
    }

    expect($sharedSql)->toContain('for share');
})->with('dblayer_drivers');

it('executes lockForUpdate flows inside transactions on available drivers', function (string $driver): void {
    $connectionName = 'lock_runtime_' . $driver;
    dblayerAddConnectionForDriver($driver, $connectionName);
    $schemaDriver = dblayerConnectionDriver($connectionName);
    $table = dblayerTable('locked_rows');

    DB::statement(sprintf(
        'create table %s (%s, value integer)',
        $table,
        dblayerAutoIncrementPrimaryKey($schemaDriver),
    ), [], $connectionName);

    DB::table($table, $connectionName)->insert([
        'value' => 10,
    ]);

    DB::transaction(static function ($connection) use ($table): void {
        $row = $connection->table($table)
            ->where('id', '=', 1)
            ->lockForUpdate()
            ->first();

        expect((int) ($row['value'] ?? 0))->toBe(10);
    }, 1, $connectionName);
})->with('dblayer_drivers');

it('surfaces write-lock contention across concurrent connections', function (string $driver): void {
    $table = 'lock_contention_' . bin2hex(random_bytes(4));

    if ($driver === 'sqlite') {
        $databaseFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'dblayer-lock-'
            . bin2hex(random_bytes(8))
            . '.sqlite';

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

        DB::statement(sprintf(
            'create table %s (%s, value integer)',
            $table,
            dblayerAutoIncrementPrimaryKey('sqlite'),
        ), [], 'writer_one');

        DB::beginTransaction('writer_one');

        try {
            DB::statement(sprintf('insert into %s (value) values (1)', $table), [], 'writer_one');
            DB::beginTransaction('writer_two');

            expect(static function () use ($table): bool {
                return DB::statement(
                    sprintf('insert into %s (value) values (2)', $table),
                    [],
                    'writer_two',
                );
            })->toThrow(ConnectionException::class);
        } finally {
            if (DB::transactionLevel('writer_two') > 0) {
                DB::rollBack('writer_two');
            }

            DB::rollBack('writer_one');
            DB::connection('writer_one')->disconnect();
            DB::connection('writer_two')->disconnect();

            if (is_file($databaseFile)) {
                unlink($databaseFile);
            }
        }

        return;
    }

    $config = dblayerRequireDriver($driver);
    DB::addConnection($config, 'writer_one');
    DB::addConnection($config, 'writer_two');
    $schemaDriver = dblayerConnectionDriver('writer_one');

    DB::statement(sprintf('drop table if exists %s', $table), [], 'writer_one');
    DB::statement(sprintf(
        'create table %s (%s, value integer)',
        $table,
        dblayerAutoIncrementPrimaryKey($schemaDriver),
    ), [], 'writer_one');
    DB::table($table, 'writer_one')->insert([
        'value' => 1,
    ]);

    DB::beginTransaction('writer_one');

    try {
        DB::table($table, 'writer_one')
            ->where('id', '=', 1)
            ->lockForUpdate()
            ->first();

        if (in_array($schemaDriver, ['mysql', 'mariadb'], true)) {
            DB::statement('set innodb_lock_wait_timeout = 1', [], 'writer_two');
        } elseif ($schemaDriver === 'mssql') {
            DB::statement('set lock_timeout 250', [], 'writer_two');
        } else {
            DB::statement("set lock_timeout = '250ms'", [], 'writer_two');
        }

        expect(static function () use ($table): bool {
            return DB::statement(
                sprintf('update %s set value = value + 1 where id = 1', $table),
                [],
                'writer_two',
            );
        })->toThrow(ConnectionException::class);
    } finally {
        DB::rollBack('writer_one');
        DB::statement(sprintf('drop table if exists %s', $table), [], 'writer_one');
    }
})->with('dblayer_drivers');

it('allows concurrent SQLite readers with the default deferred transaction mode', function (): void {
    $databaseFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . 'dblayer-readers-'
        . bin2hex(random_bytes(8))
        . '.sqlite';

    foreach (['reader_one', 'reader_two'] as $connection) {
        DB::addConnection([
            'driver' => 'sqlite',
            'database' => $databaseFile,
            'options' => [PDO::ATTR_TIMEOUT => 0],
        ], $connection);
    }

    DB::statement('create table reader_rows (id integer primary key)', [], 'reader_one');
    DB::statement('insert into reader_rows (id) values (1)', [], 'reader_one');

    try {
        DB::beginTransaction('reader_one');
        DB::beginTransaction('reader_two');

        expect(DB::scalar('select count(*) from reader_rows', [], 'reader_one'))->toBe(1)
            ->and(DB::scalar('select count(*) from reader_rows', [], 'reader_two'))->toBe(1);
    } finally {
        DB::rollBack('reader_two');
        DB::rollBack('reader_one');
        DB::connection('reader_one')->disconnect();
        DB::connection('reader_two')->disconnect();

        if (is_file($databaseFile)) {
            unlink($databaseFile);
        }
    }
});
