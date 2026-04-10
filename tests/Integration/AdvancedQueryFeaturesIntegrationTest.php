<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;

it('supports CTEs and subquery sources', function (string $driver): void {
    dblayerAddConnectionForDriver($driver, 'advanced_query_features');

    DB::statement(
        sprintf(
            'create table orders (%s, amount integer)',
            dblayerAutoIncrementPrimaryKey($driver),
        ),
        [],
        'advanced_query_features',
    );
    DB::statement('insert into orders (amount) values (5), (15), (25)', [], 'advanced_query_features');

    $rows = DB::table('orders', 'advanced_query_features')
        ->with('big_orders', static function ($query): void {
            $query->from('orders')->select('id', 'amount')->where('amount', '>', 10);
        })
        ->from('big_orders')
        ->selectRaw('sum(amount) as total_amount')
        ->get();

    expect((int) ($rows[0]['total_amount'] ?? 0))->toBe(40);

    $fromSubRows = DB::table('orders', 'advanced_query_features')
        ->fromSub(static function ($query): void {
            $query->from('orders')->select('id', 'amount')->where('amount', '>=', 15);
        }, 'filtered')
        ->selectRaw('count(*) as c')
        ->get();

    expect((int) ($fromSubRows[0]['c'] ?? 0))->toBe(2);
})->with('dblayer_drivers');

it('supports window-function select helper', function (string $driver): void {
    dblayerAddConnectionForDriver($driver, 'advanced_window_features');

    DB::statement(
        sprintf(
            'create table events (%s, tenant_id integer not null, payload %s)',
            dblayerAutoIncrementPrimaryKey($driver),
            dblayerStringType($driver),
        ),
        [],
        'advanced_window_features',
    );
    DB::statement(
        "insert into events (tenant_id, payload) values (1, 'a'), (1, 'b'), (2, 'c')",
        [],
        'advanced_window_features',
    );

    $rows = DB::table('events', 'advanced_window_features')
        ->select('id', 'tenant_id')
        ->selectWindow('row_number()', 'row_num', ['tenant_id'], ['id asc'])
        ->orderBy('id')
        ->get();

    expect($rows)->toHaveCount(3);
    expect((int) ($rows[0]['row_num'] ?? 0))->toBe(1);
    expect((int) ($rows[1]['row_num'] ?? 0))->toBe(2);
    expect((int) ($rows[2]['row_num'] ?? 0))->toBe(1);
})->with('dblayer_drivers');

it('supports upsertReturning fallback semantics', function (string $driver): void {
    dblayerAddConnectionForDriver($driver, 'advanced_upsert_returning');

    DB::statement(
        sprintf(
            'create table users (%s, email %s not null unique, name %s not null)',
            dblayerAutoIncrementPrimaryKey($driver),
            dblayerStringType($driver, 191),
            dblayerStringType($driver),
        ),
        [],
        'advanced_upsert_returning',
    );

    $returned = DB::table('users', 'advanced_upsert_returning')
        ->upsertReturning(
            [
                'email' => 'alice@example.test',
                'name' => 'Alice',
            ],
            ['email'],
            ['name'],
            ['email', 'name'],
        );

    expect($returned)->toHaveCount(1);
    expect($returned[0]['email'] ?? null)->toBe('alice@example.test');
    expect($returned[0]['name'] ?? null)->toBe('Alice');
})->with('dblayer_drivers');
