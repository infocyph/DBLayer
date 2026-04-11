<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Repository\TableRepository;
use Infocyph\DBLayer\Query\QueryBuilder;
use Infocyph\DBLayer\Query\Repository;
use Infocyph\DBLayer\Support\Collection;

final class TableRepositoryUser extends TableRepository
{
    protected static ?string $connection = 'table_repository_conn';
    protected static string $table = 'users';

    protected static function configureRepository(Repository $repository): Repository
    {
        return $repository
            ->forTenant(10)
            ->enableSoftDeletes()
            ->setDefaultOrder('id', 'asc');
    }
}

final class BrokenTableRepository extends TableRepository {}

function setupTableRepositoryFixture(string $driver): void
{
    // Keep repository fixtures independent from facade-level hardening defaults.
    DB::setSecurityDefaults([], false);
    dblayerAddConnectionForDriver($driver, 'table_repository_conn');
    $schemaDriver = dblayerConnectionDriver('table_repository_conn');
    dblayerDropTable('users', 'table_repository_conn');

    DB::statement(
        sprintf(
            'create table users (
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
        'table_repository_conn',
    );
}

function setupTableRepositoryAltFixture(): void
{
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ], 'table_repository_alt');

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
        'table_repository_alt',
    );
}

it('delegates to repository API with class-level repository defaults', function (string $driver): void {
    setupTableRepositoryFixture($driver);

    DB::table('users', 'table_repository_conn')->insert([
        ['tenant_id' => 10, 'email' => 'visible@example.test', 'name' => 'Visible', 'active' => 1, 'deleted_at' => null],
        ['tenant_id' => 10, 'email' => 'trashed@example.test', 'name' => 'Trashed', 'active' => 1, 'deleted_at' => '2026-01-01 00:00:00'],
        ['tenant_id' => 20, 'email' => 'other-tenant@example.test', 'name' => 'Other Tenant', 'active' => 1, 'deleted_at' => null],
    ]);

    expect(TableRepositoryUser::all())->toBeInstanceOf(Collection::class);
    expect(TableRepositoryUser::all()->count())->toBe(1);
    expect(TableRepositoryUser::find(1)['email'] ?? null)->toBe('visible@example.test');
    expect(TableRepositoryUser::find(2))->toBeNull();
    expect(TableRepositoryUser::find(3))->toBeNull();

    $created = TableRepositoryUser::create([
        'email' => 'created@example.test',
        'name' => 'Created',
        'active' => 1,
    ]);

    expect($created['tenant_id'] ?? null)->toBe(10);
    expect(TableRepositoryUser::count())->toBe(2);
})->with('dblayer_drivers');

it('delegates to query builder API while preserving repository policies', function (string $driver): void {
    setupTableRepositoryFixture($driver);

    DB::table('users', 'table_repository_conn')->insert([
        ['tenant_id' => 10, 'email' => 'a@example.test', 'name' => 'A', 'active' => 1, 'deleted_at' => null],
        ['tenant_id' => 10, 'email' => 'b@example.test', 'name' => 'B', 'active' => 0, 'deleted_at' => null],
        ['tenant_id' => 20, 'email' => 'c@example.test', 'name' => 'C', 'active' => 1, 'deleted_at' => null],
    ]);

    $emails = TableRepositoryUser::where('active', '=', 1)
        ->orderBy('id')
        ->pluck('email');

    expect($emails)->toBe(['a@example.test']);
    expect(TableRepositoryUser::query())->toBeInstanceOf(QueryBuilder::class);
    expect(TableRepositoryUser::builder()->count())->toBe(2);
})->with('dblayer_drivers');

it('forwards DB facade methods and injects configured connection when available', function (string $driver): void {
    setupTableRepositoryFixture($driver);

    expect(TableRepositoryUser::statement(
        'insert into users (tenant_id, email, name, active, deleted_at) values (?, ?, ?, ?, ?)',
        [10, 'raw@example.test', 'Raw Insert', 1, null],
    ))->toBeTrue();

    $rows = TableRepositoryUser::sqlSelect('select count(*) as c from users');
    expect((int) ($rows[0]['c'] ?? 0))->toBe(1);
    expect(TableRepositoryUser::sqlScalar('select count(*) from users'))->toBe(1);

    $lastId = TableRepositoryUser::lastInsertId();
    expect($lastId)->not->toBeFalse();

    $stats = TableRepositoryUser::stats();
    expect($stats['database'] ?? null)->not->toBeNull();
    expect($stats['driver'] ?? null)->toBe(dblayerConnectionDriver('table_repository_conn'));
})->with('dblayer_drivers');

it('supports per-call connection override for repository, query, and raw SQL helpers', function (string $driver): void {
    setupTableRepositoryFixture($driver);
    setupTableRepositoryAltFixture();

    DB::table('users', 'table_repository_conn')->insert([
        'tenant_id' => 10,
        'email' => 'default@example.test',
        'name' => 'Default',
        'active' => 1,
        'deleted_at' => null,
    ]);

    DB::table('users', 'table_repository_alt')->insert([
        'tenant_id' => 10,
        'email' => 'alt@example.test',
        'name' => 'Alt',
        'active' => 1,
        'deleted_at' => null,
    ]);

    expect(TableRepositoryUser::repository()->count())->toBe(1);
    expect(TableRepositoryUser::repository('table_repository_alt')->count())->toBe(1);

    expect(TableRepositoryUser::query()->pluck('email'))->toBe(['default@example.test']);
    expect(TableRepositoryUser::query('table_repository_alt')->pluck('email'))->toBe(['alt@example.test']);

    expect((int) TableRepositoryUser::sqlScalar('select count(*) from users'))->toBe(1);
    expect((int) TableRepositoryUser::sqlScalar('select count(*) from users', [], 'table_repository_alt'))->toBe(1);
})->with('dblayer_drivers');

it('throws a clear exception when table is not configured', function (): void {
    expect(static fn(): QueryBuilder => BrokenTableRepository::query())
        ->toThrow(InvalidArgumentException::class, 'must define a non-empty static $table value');
});
