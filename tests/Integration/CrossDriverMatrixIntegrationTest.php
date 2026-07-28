<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\DB;

it('runs core query and transaction flow on available drivers', function (string $driver): void {
    $config = dblayerRequireDriver($driver);

    DB::addConnection($config, 'matrix');
    DB::setDefaultConnection('matrix');

    $table = 'matrix_items_' . $driver;

    DB::statement("drop table if exists {$table}");
    DB::statement(
        "create table {$table} (
            id integer primary key,
            name varchar(100) not null,
            qty integer not null
        )"
    );

    expect(DB::table($table)->insert([
        'id' => 1,
        'name' => 'alpha',
        'qty' => 1,
    ]))->toBeTrue();

    expect((int) DB::table($table)->count())->toBe(1);

    DB::transaction(static function (Connection $connection) use ($table): void {
        $connection->table($table)
            ->where('id', '=', 1)
            ->update(['qty' => 2]);
    });

    expect((int) DB::table($table)->where('id', '=', 1)->value('qty'))->toBe(2);

    try {
        DB::transaction(static function (Connection $connection) use ($table): void {
            $connection->table($table)->insert([
                'id' => 2,
                'name' => 'rolled-back',
                'qty' => 99,
            ]);

            throw new RuntimeException('rollback me');
        });
    } catch (Throwable) {
        // expected rollback path
    }

    expect((int) DB::table($table)->count())->toBe(1);

    DB::statement("drop table if exists {$table}");
    DB::purge();
})->with('dblayer_drivers');

it('applies effective vendor-specific connection configuration', function (string $driver): void {
    $config = dblayerRequireDriver($driver);

    if ($driver === 'mysql') {
        $config['charset'] = 'utf8mb4';
        $config['collation'] = 'utf8mb4_unicode_ci';
    } elseif ($driver === 'pgsql') {
        $config['charset'] = 'UTF8';
        $config['schema'] = 'public';
        $config['timeout'] = 5;
    }

    DB::addConnection($config, 'effective_config');
    $connection = DB::connection('effective_config');

    if ($driver === 'mysql') {
        expect($connection->scalar('select @@character_set_connection'))->toBe('utf8mb4')
            ->and($connection->scalar('select @@collation_connection'))->toBe('utf8mb4_unicode_ci');
    } elseif ($driver === 'pgsql') {
        expect($connection->scalar('show client_encoding'))->toBe('UTF8')
            ->and($connection->scalar('show search_path'))->toBe('public');
    } else {
        expect($connection->scalar('select 1'))->toBe(1)
            ->and($connection->getConfig()->toArray())->not->toHaveKeys([
                'host',
                'charset',
                'collation',
                'schema',
            ]);
    }
})->with('dblayer_drivers');
