<?php

declare(strict_types=1);

use Infocyph\ArrayKit\Collection\Collection;
use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Repository\RepositoryQuery;
use Infocyph\DBLayer\Tests\Fixtures\BrokenTableRepository;
use Infocyph\DBLayer\Tests\Fixtures\TableRepositoryUser;

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

it('delegates fluent reads through the repository-aware query boundary', function (string $driver): void {
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
    expect(TableRepositoryUser::query())->toBeInstanceOf(RepositoryQuery::class);
    expect(TableRepositoryUser::builder()->count())->toBe(2);
})->with('dblayer_drivers');

it('replaces duplicate order columns to keep generated SQL portable', function (string $driver): void {
    setupTableRepositoryFixture($driver);

    $query = TableRepositoryUser::query()->orderBy('id', 'desc')->raw();

    expect($query->getComponents()['orders'])->toBe([
        ['column' => 'id', 'direction' => 'desc'],
    ]);
})->with('dblayer_drivers');

it('keeps infrastructure explicit while retaining raw SQL helpers', function (string $driver): void {
    setupTableRepositoryFixture($driver);

    expect(TableRepositoryUser::sqlStatement(
        'insert into users (tenant_id, email, name, active, deleted_at) values (?, ?, ?, ?, ?)',
        [10, 'raw@example.test', 'Raw Insert', 1, null],
    ))->toBeTrue();

    $rows = TableRepositoryUser::sqlSelect('select count(*) as c from users');
    expect((int) ($rows[0]['c'] ?? 0))->toBe(1);
    expect(TableRepositoryUser::sqlScalar('select count(*) from users'))->toBe(1);

    $lastId = TableRepositoryUser::connection()->lastInsertId();
    expect($lastId)->not->toBeFalse();

    $stats = DB::stats('table_repository_conn');
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
    expect(static fn(): RepositoryQuery => BrokenTableRepository::query())
        ->toThrow(InvalidArgumentException::class, 'must define a non-empty static $table value');
});

it('binds inherited model instances with scoped data and a custom primary key', function (string $driver): void {
    setupTableRepositoryFixture($driver);
    $row = \Infocyph\DBLayer\Tests\Fixtures\TableRepositoryBoundUser::create([
        'email' => 'bound@example.test', 'name' => 'Bound', 'active' => 1,
    ]);
    $model = \Infocyph\DBLayer\Tests\Fixtures\TableRepositoryBoundUser::fromBoundValue((string) $row['id']);
    expect($model->name)->toBe('Bound')->and($model->active)->toBe(1)
        ->and($model->exists)->toBeTrue()->and($model->getKey())->toBe($row['id'])
        ->and($model->getDirty())->toBe([])->and($model->toArray())->toBe($row)
        ->and(json_decode(json_encode($model, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR))->toBe($row)
        ->and(isset($model->email))->toBeTrue()->and(isset($model->deleted_at))->toBeFalse()
        ->and(\Infocyph\DBLayer\Tests\Fixtures\TableRepositoryBoundUser::fromBoundValue($model))->toBe($model);
    $custom = \Infocyph\DBLayer\Tests\Fixtures\TableRepositoryEmailUser::fromBoundValue('bound@example.test');
    expect($custom)->toBeInstanceOf(\Infocyph\DBLayer\Tests\Fixtures\TableRepositoryEmailUser::class)
        ->and($custom->getKey())->toBe('bound@example.test')->and($custom->name)->toBe('Bound');
    $custom->name = 'Custom';
    expect($custom->save())->toBeTrue()->and(TableRepositoryUser::find($row['id'])['name'])->toBe('Custom');
})->with('dblayer_drivers');

it('keeps instance updates explicit and preserves static mutations and write policy', function (string $driver): void {
    setupTableRepositoryFixture($driver);
    $model = new \Infocyph\DBLayer\Tests\Fixtures\TableRepositoryBoundUser([
        'email' => 'save@example.test', 'name' => 'Original', 'active' => 1,
    ]);
    expect($model->exists)->toBeFalse()->and($model->save())->toBeTrue()->and($model->exists)->toBeTrue();
    $id = $model->getKey();
    $model->name = 'Edited';
    expect(TableRepositoryUser::find($id)['name'])->toBe('Original')
        ->and($model->getDirty())->toBe(['name' => 'Edited'])
        ->and($model->save())->toBeTrue()->and($model->getDirty())->toBe([])
        ->and(TableRepositoryUser::find($id)['name'])->toBe('Edited');
    expect($model->update(['active' => 0]))->toBeTrue();
    $model->refresh();
    expect($model->active)->toBe(0)
        ->and(\Infocyph\DBLayer\Tests\Fixtures\TableRepositoryBoundUser::where('id', '=', $id)->update(['name' => 'Static']))->toBe(1)
        ->and($model->refresh()->name)->toBe('Static');
    $model->email = 'forbidden@example.test';
    expect(fn() => $model->save())->toThrow(\Infocyph\DBLayer\Exceptions\UnwritableAttributeException::class)
        ->and(TableRepositoryUser::find($id)['email'])->toBe('save@example.test');
    $model->refresh();
    expect($model->delete())->toBeTrue()->and($model->exists)->toBeFalse()
        ->and($model->delete())->toBeFalse()->and($model->save())->toBeFalse()
        ->and(TableRepositoryUser::find($id))->toBeNull()
        ->and(TableRepositoryUser::withTrashed()->find($id)['deleted_at'])->not->toBeNull();
})->with('dblayer_drivers');

it('rejects invisible and missing model bindings without exposing supplied identifiers', function (string $driver): void {
    setupTableRepositoryFixture($driver);
    DB::table('users', 'table_repository_conn')->insert([
        ['tenant_id' => 20, 'email' => 'hidden@example.test', 'name' => 'Hidden', 'active' => 1, 'deleted_at' => null],
        ['tenant_id' => 10, 'email' => 'trashed-bind@example.test', 'name' => 'Trashed', 'active' => 1, 'deleted_at' => '2026-01-01 00:00:00'],
    ]);
    foreach ([1, 2, '999999'] as $value) {
        expect(fn() => \Infocyph\DBLayer\Tests\Fixtures\TableRepositoryBoundUser::fromBoundValue($value))
            ->toThrow(\Infocyph\DBLayer\Exceptions\RecordNotFoundException::class);
    }
    expect(fn() => \Infocyph\DBLayer\Tests\Fixtures\TableRepositoryEmailUser::fromBoundValue("private' OR 1=1 --"))
        ->toThrow(\Infocyph\DBLayer\Exceptions\RecordNotFoundException::class);
    expect(fn() => \Infocyph\DBLayer\Tests\Fixtures\TableRepositoryEmailUser::fromBoundValue('hidden@example.test'))
        ->toThrow(\Infocyph\DBLayer\Exceptions\RecordNotFoundException::class);
})->with('dblayer_drivers');

it('rejects invalid bound identifiers without coercing their type', function (mixed $value): void {
    expect(fn() => \Infocyph\DBLayer\Tests\Fixtures\TableRepositoryBoundUser::fromBoundValue($value))
        ->toThrow(InvalidArgumentException::class);
})->with([null, true, 1.5, [[]], [new stdClass()]]);

it('keeps bound rows independent and detects missing rows before syncing failed writes', function (): void {
    setupTableRepositoryFixture('sqlite');
    $row = \Infocyph\DBLayer\Tests\Fixtures\TableRepositoryBoundUser::create([
        'email' => 'fresh@example.test', 'name' => 'Fresh', 'active' => 1,
    ]);
    $first = \Infocyph\DBLayer\Tests\Fixtures\TableRepositoryBoundUser::fromBoundValue($row['id']);
    $second = \Infocyph\DBLayer\Tests\Fixtures\TableRepositoryBoundUser::fromBoundValue($row['id']);
    $first->name = 'Private edit';
    expect($second->name)->toBe('Fresh')->and($first)->not->toBe($second);
    TableRepositoryUser::forceDeleteById($row['id']);
    expect($first->save())->toBeFalse()->and($first->getDirty())->toBe(['name' => 'Private edit']);
    expect(fn() => $second->refresh())->toThrow(\Infocyph\DBLayer\Exceptions\RecordNotFoundException::class);
});


it('does not construct bound objects during ordinary static queries', function (): void {
    setupTableRepositoryFixture('sqlite');
    $class = \Infocyph\DBLayer\Tests\Fixtures\TableRepositoryBoundUser::class;
    $class::$constructions = 0;
    $row = $class::create(['email' => 'static@example.test', 'name' => 'Static', 'active' => 1]);
    expect($class::find($row['id']))->toBeArray()->and($class::count())->toBe(1)
        ->and($class::$constructions)->toBe(0);
    $class::fromBoundValue($row['id']);
    expect($class::$constructions)->toBe(1);
});
