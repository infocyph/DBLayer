<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Model\TableModel;
use Infocyph\DBLayer\Query\QueryBuilder;
use Infocyph\DBLayer\Query\Repository;
use Infocyph\DBLayer\Support\Collection;

final class TableModelUser extends TableModel
{
    protected static string $table = 'users';

    protected static ?string $connection = 'table_model_conn';

    protected static function configureRepository(Repository $repository): Repository
    {
        return $repository
            ->forTenant(10)
            ->enableSoftDeletes()
            ->setDefaultOrder('id', 'asc');
    }
}

final class BrokenTableModel extends TableModel {}

function setupTableModelFixture(string $driver): void
{
    dblayerAddConnectionForDriver($driver, 'table_model_conn');
    $schemaDriver = dblayerConnectionDriver('table_model_conn');
    dblayerDropTable('users', 'table_model_conn');

    DB::statement(
        sprintf('create table users (
            %s,
            tenant_id integer not null,
            email %s not null unique,
            name %s not null,
            active integer not null default 1,
            deleted_at %s null
        )',
            dblayerAutoIncrementPrimaryKey($schemaDriver),
            dblayerStringType($schemaDriver, 191),
            dblayerStringType($schemaDriver),
            dblayerDateTimeType($schemaDriver),
        ),
        [],
        'table_model_conn',
    );
}

function setupTableModelAltFixture(): void
{
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ], 'table_model_alt');

    DB::statement(
        'create table users (
            id integer primary key autoincrement,
            tenant_id integer not null,
            email text not null unique,
            name text not null,
            active integer not null default 1,
            deleted_at text null
        )',
        [],
        'table_model_alt',
    );
}

it('delegates to repository API with model-level repository defaults', function (string $driver): void {
    setupTableModelFixture($driver);

    DB::table('users', 'table_model_conn')->insert([
        ['tenant_id' => 10, 'email' => 'visible@example.test', 'name' => 'Visible', 'active' => 1, 'deleted_at' => null],
        ['tenant_id' => 10, 'email' => 'trashed@example.test', 'name' => 'Trashed', 'active' => 1, 'deleted_at' => '2026-01-01 00:00:00'],
        ['tenant_id' => 20, 'email' => 'other-tenant@example.test', 'name' => 'Other Tenant', 'active' => 1, 'deleted_at' => null],
    ]);

    expect(TableModelUser::all())->toBeInstanceOf(Collection::class);
    expect(TableModelUser::all()->count())->toBe(1);
    expect(TableModelUser::find(1)['email'] ?? null)->toBe('visible@example.test');
    expect(TableModelUser::find(2))->toBeNull();
    expect(TableModelUser::find(3))->toBeNull();

    $created = TableModelUser::create([
        'email' => 'created@example.test',
        'name' => 'Created',
        'active' => 1,
    ]);

    expect($created['tenant_id'] ?? null)->toBe(10);
    expect(TableModelUser::count())->toBe(2);
})->with('dblayer_drivers');

it('delegates to query builder API while preserving model policies', function (string $driver): void {
    setupTableModelFixture($driver);

    DB::table('users', 'table_model_conn')->insert([
        ['tenant_id' => 10, 'email' => 'a@example.test', 'name' => 'A', 'active' => 1, 'deleted_at' => null],
        ['tenant_id' => 10, 'email' => 'b@example.test', 'name' => 'B', 'active' => 0, 'deleted_at' => null],
        ['tenant_id' => 20, 'email' => 'c@example.test', 'name' => 'C', 'active' => 1, 'deleted_at' => null],
    ]);

    $emails = TableModelUser::where('active', '=', 1)
        ->orderBy('id')
        ->pluck('email');

    expect($emails)->toBe(['a@example.test']);
    expect(TableModelUser::query())->toBeInstanceOf(QueryBuilder::class);
    expect(TableModelUser::builder()->count())->toBe(2);
})->with('dblayer_drivers');

it('forwards DB facade methods and injects configured connection when available', function (string $driver): void {
    setupTableModelFixture($driver);

    expect(TableModelUser::statement(
        'insert into users (tenant_id, email, name, active, deleted_at) values (?, ?, ?, ?, ?)',
        [10, 'raw@example.test', 'Raw Insert', 1, null],
    ))->toBeTrue();

    $rows = TableModelUser::sqlSelect('select count(*) as c from users');
    expect((int) ($rows[0]['c'] ?? 0))->toBe(1);
    expect(TableModelUser::sqlScalar('select count(*) from users'))->toBe(1);

    $lastId = (int) TableModelUser::lastInsertId();
    expect($lastId)->toBe(1);

    $stats = TableModelUser::stats();
    expect($stats['database'] ?? null)->not->toBeNull();
    expect($stats['driver'] ?? null)->toBe(dblayerConnectionDriver('table_model_conn'));
})->with('dblayer_drivers');

it('supports per-call connection override for repository, query, and raw SQL helpers', function (string $driver): void {
    setupTableModelFixture($driver);
    setupTableModelAltFixture();

    DB::table('users', 'table_model_conn')->insert([
        'tenant_id' => 10,
        'email' => 'default@example.test',
        'name' => 'Default',
        'active' => 1,
        'deleted_at' => null,
    ]);

    DB::table('users', 'table_model_alt')->insert([
        'tenant_id' => 10,
        'email' => 'alt@example.test',
        'name' => 'Alt',
        'active' => 1,
        'deleted_at' => null,
    ]);

    expect(TableModelUser::repository()->count())->toBe(1);
    expect(TableModelUser::repository('table_model_alt')->count())->toBe(1);

    expect(TableModelUser::query()->pluck('email'))->toBe(['default@example.test']);
    expect(TableModelUser::query('table_model_alt')->pluck('email'))->toBe(['alt@example.test']);

    expect((int) TableModelUser::sqlScalar('select count(*) from users'))->toBe(1);
    expect((int) TableModelUser::sqlScalar('select count(*) from users', [], 'table_model_alt'))->toBe(1);
})->with('dblayer_drivers');

it('throws a clear exception when table is not configured', function (): void {
    expect(static fn(): QueryBuilder => BrokenTableModel::query())
        ->toThrow(InvalidArgumentException::class, 'must define a non-empty static $table value');
});
