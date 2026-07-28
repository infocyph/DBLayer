<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Events\Events;
use Infocyph\DBLayer\Exceptions\QueryException;

it('supports CTEs and subquery sources', function (string $driver): void {
    dblayerAddConnectionForDriver($driver, 'advanced_query_features');
    $schemaDriver = dblayerConnectionDriver('advanced_query_features');
    $table = dblayerTable('orders');

    DB::statement(
        sprintf(
            'create table %s (%s, amount integer)',
            $table,
            dblayerAutoIncrementPrimaryKey($schemaDriver),
        ),
        [],
        'advanced_query_features',
    );
    DB::statement(sprintf('insert into %s (amount) values (5), (15), (25)', $table), [], 'advanced_query_features');

    $rows = DB::table($table, 'advanced_query_features')
        ->with('big_orders', static function ($query) use ($table): void {
            $query->from($table)->select('id', 'amount')->where('amount', '>', 10);
        })
        ->from('big_orders')
        ->selectRaw('sum(amount) as total_amount')
        ->get();

    expect((int) ($rows[0]['total_amount'] ?? 0))->toBe(40);

    $fromSubRows = DB::table($table, 'advanced_query_features')
        ->fromSub(static function ($query) use ($table): void {
            $query->from($table)->select('id', 'amount')->where('amount', '>=', 15);
        }, 'filtered')
        ->selectRaw('count(*) as c')
        ->get();

    expect((int) ($fromSubRows[0]['c'] ?? 0))->toBe(2);
})->with('dblayer_drivers');

it('supports window-function select helper', function (string $driver): void {
    dblayerAddConnectionForDriver($driver, 'advanced_window_features');
    $schemaDriver = dblayerConnectionDriver('advanced_window_features');
    $table = dblayerTable('events');

    DB::statement(
        sprintf(
            'create table %s (%s, tenant_id integer not null, payload %s)',
            $table,
            dblayerAutoIncrementPrimaryKey($schemaDriver),
            dblayerStringType($schemaDriver),
        ),
        [],
        'advanced_window_features',
    );
    DB::statement(
        sprintf("insert into %s (tenant_id, payload) values (1, 'a'), (1, 'b'), (2, 'c')", $table),
        [],
        'advanced_window_features',
    );

    $rows = DB::table($table, 'advanced_window_features')
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
    $schemaDriver = dblayerConnectionDriver('advanced_upsert_returning');
    $table = dblayerTable('users');

    DB::statement(
        sprintf(
            'create table %s (%s, email %s not null unique, name %s not null)',
            $table,
            dblayerAutoIncrementPrimaryKey($schemaDriver),
            dblayerStringType($schemaDriver, 191),
            dblayerStringType($schemaDriver),
        ),
        [],
        'advanced_upsert_returning',
    );

    $returned = DB::table($table, 'advanced_upsert_returning')
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

it('joins aggregate subqueries without changing outer join semantics', function (string $driver): void {
    $connection = 'performance_subquery_' . $driver;
    dblayerAddConnectionForDriver($driver, $connection);
    $schemaDriver = dblayerConnectionDriver($connection);
    $customers = dblayerTable('customers');
    $orders = dblayerTable('orders');

    DB::statement(
        sprintf(
            'create table %s (%s, name %s not null, status %s not null)',
            $customers,
            dblayerAutoIncrementPrimaryKey($schemaDriver),
            dblayerStringType($schemaDriver),
            dblayerStringType($schemaDriver, 20),
        ),
        [],
        $connection,
    );
    DB::statement(
        sprintf(
            'create table %s (%s, customer_id integer not null, order_date %s not null, total_amount integer not null)',
            $orders,
            dblayerAutoIncrementPrimaryKey($schemaDriver),
            dblayerStringType($schemaDriver, 10),
        ),
        [],
        $connection,
    );

    DB::table($customers, $connection)->insert([
        ['name' => 'Active With Orders', 'status' => 'active'],
        ['name' => 'Active Without Orders', 'status' => 'active'],
        ['name' => 'Inactive', 'status' => 'inactive'],
    ]);
    DB::table($orders, $connection)->insert([
        ['customer_id' => 1, 'order_date' => '2024-01-05', 'total_amount' => 40],
        ['customer_id' => 1, 'order_date' => '2024-01-20', 'total_amount' => 60],
        ['customer_id' => 3, 'order_date' => '2024-01-10', 'total_amount' => 90],
        ['customer_id' => 1, 'order_date' => '2024-02-01', 'total_amount' => 200],
    ]);

    $orderStats = DB::table($orders, $connection)
        ->select('customer_id')
        ->selectRaw('count(*) as total_orders')
        ->selectRaw('sum(total_amount) as total_spent')
        ->where('order_date', '>=', '2024-01-01')
        ->where('order_date', '<', '2024-02-01')
        ->groupBy('customer_id');

    $query = DB::table($customers, $connection)
        ->select($customers . '.id', $customers . '.name')
        ->selectRaw('coalesce(order_stats.total_orders, ?) as total_orders', [0])
        ->selectRaw('coalesce(order_stats.total_spent, 0) as total_spent')
        ->where($customers . '.status', '=', 'active')
        ->leftJoinSub(
            $orderStats,
            'order_stats',
            $customers . '.id',
            '=',
            'order_stats.customer_id',
        )
        ->orderBy($customers . '.id');

    expect($query->getBindings())->toBe([
        0,
        '2024-01-01',
        '2024-02-01',
        'active',
    ]);

    $rows = $query->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['name'])->toBe('Active With Orders')
        ->and((int) $rows[0]['total_orders'])->toBe(2)
        ->and((int) $rows[0]['total_spent'])->toBe(100)
        ->and($rows[1]['name'])->toBe('Active Without Orders')
        ->and((int) $rows[1]['total_orders'])->toBe(0)
        ->and((int) $rows[1]['total_spent'])->toBe(0);
})->with('dblayer_drivers');

it('returns a native execution plan for builder and facade queries', function (string $driver): void {
    $connection = 'performance_explain_' . $driver;
    dblayerAddConnectionForDriver($driver, $connection);
    $schemaDriver = dblayerConnectionDriver($connection);
    $table = dblayerTable('explain_items');

    DB::statement(
        sprintf(
            'create table %s (%s, lookup_value integer not null)',
            $table,
            dblayerAutoIncrementPrimaryKey($schemaDriver),
        ),
        [],
        $connection,
    );
    DB::table($table, $connection)->insert(['lookup_value' => 10]);

    $builder = DB::table($table, $connection)
        ->select('id')
        ->where('lookup_value', '=', 10);

    $builderPlan = $builder->explain();
    $facadePlan = DB::explain(
        $builder->toSql(),
        $builder->getBindings(),
        connection: $connection,
    );

    expect($builderPlan)->not->toBeEmpty()
        ->and($facadePlan)->not->toBeEmpty();

    expect(static fn(): array => DB::explain(
        sprintf('delete from %s', $table),
        connection: $connection,
    ))->toThrow(QueryException::class, 'Execution plans accept SELECT statements only');
    expect(DB::table($table, $connection)->count())->toBe(1);
})->with('dblayer_drivers');

it('aggregates enabled telemetry by parameterized query shape', function (string $driver): void {
    $connection = 'performance_shapes_' . $driver;
    dblayerAddConnectionForDriver($driver, $connection);
    $schemaDriver = dblayerConnectionDriver($connection);
    $table = dblayerTable('shape_items');

    DB::statement(
        sprintf(
            'create table %s (%s, name %s not null)',
            $table,
            dblayerAutoIncrementPrimaryKey($schemaDriver),
            dblayerStringType($schemaDriver),
        ),
        [],
        $connection,
    );
    DB::table($table, $connection)->insert([
        ['name' => 'one'],
        ['name' => 'two'],
    ]);

    DB::enableTelemetry();

    DB::table($table, $connection)->where('id', '=', 1)->get();
    DB::table($table, $connection)->where('id', '=', 2)->get();
    DB::table($table, $connection)->where('name', '=', 'one')->get();

    $report = DB::queryShapeReport([50, 95], 0.0, null);
    $twoCallShape = null;

    foreach ($report['shapes'] as $shape) {
        if (($shape['calls'] ?? 0) === 2) {
            $twoCallShape = $shape;

            break;
        }
    }

    expect($report['query_count'])->toBe(3)
        ->and($report['shape_count'])->toBe(2)
        ->and($twoCallShape)->not->toBeNull()
        ->and($twoCallShape['success_count'])->toBe(2)
        ->and($twoCallShape['failure_count'])->toBe(0)
        ->and($twoCallShape['fingerprint'])->toMatch('/^[a-f0-9]{16}$/')
        ->and($twoCallShape['percentiles'])->toHaveKeys(['50', '95']);

    DB::flushTelemetry();
    Events::forgetAll();
    DB::enableTelemetry();
    DB::table($table, $connection)->where('id', '=', 1)->get();

    expect(DB::telemetry()['summary']['query_count'] ?? 0)->toBe(1);
})->with('dblayer_drivers');
