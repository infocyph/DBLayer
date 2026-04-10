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

    expect($updateSql)->toContain('for update');

    if ($schemaDriver === 'mysql') {
        expect($sharedSql)->toContain('lock in share mode');

        return;
    }

    expect($sharedSql)->toContain('for share');
})->with('dblayer_drivers');

it('executes lockForUpdate flows inside transactions on available drivers', function (string $driver): void {
    $connectionName = 'lock_runtime_' . $driver;
    dblayerAddConnectionForDriver($driver, $connectionName);
    $schemaDriver = dblayerConnectionDriver($connectionName);

    DB::statement(sprintf(
        'create table locked_rows (%s, value integer)',
        dblayerAutoIncrementPrimaryKey($schemaDriver),
    ), [], $connectionName);

    DB::table('locked_rows', $connectionName)->insert([
        'id' => 1,
        'value' => 10,
    ]);

    DB::transaction(static function ($connection): void {
        $row = $connection->table('locked_rows')
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
        DB::statement(sprintf('insert into %s (value) values (1)', $table), [], 'writer_one');

        try {
            expect(static function () use ($table): bool {
                return DB::statement(sprintf('insert into %s (value) values (2)', $table), [], 'writer_two');
            })->toThrow(ConnectionException::class);
        } finally {
            DB::rollBack('writer_one');
            DB::connection('writer_one')->disconnect();
            DB::connection('writer_two')->disconnect();

            if (is_file($databaseFile)) {
                @unlink($databaseFile);
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
        'id' => 1,
        'value' => 1,
    ]);

    DB::beginTransaction('writer_one');

    try {
        DB::table($table, 'writer_one')
            ->where('id', '=', 1)
            ->lockForUpdate()
            ->first();

        if ($schemaDriver === 'mysql') {
            DB::statement('set innodb_lock_wait_timeout = 1', [], 'writer_two');
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
