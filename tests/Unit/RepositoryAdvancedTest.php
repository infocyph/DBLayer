<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Query\QueryBuilder;

final class RepositoryUserDto
{
    public function __construct(
        public int $id,
        public int $tenant_id,
        public string $email,
        public string $name,
        public int $active = 1,
    ) {}
}

final class RepositoryHydratedDto
{
    public int $id = 0;

    public string $email = '';
}

abstract class RepositoryAbstractDto
{
    public int $id;
}

/**
 * @param list<array<string,mixed>> $rows
 */
function seedRepositoryUsers(string $table, array $rows): void
{
    DB::table($table)->insert($rows);
}

function setupRepositoryFixture(string $driver): string
{
    dblayerAddConnectionForDriver($driver, 'repo');
    DB::setDefaultConnection('repo');
    $schemaDriver = dblayerConnectionDriver('repo');
    $table = dblayerTable('repository_users');

    DB::statement(
        sprintf('create table %s (
            %s,
            tenant_id integer not null,
            email %s not null unique,
            name %s not null,
            active integer not null default 1
        )',
            $table,
            dblayerAutoIncrementPrimaryKey($schemaDriver),
            dblayerStringType($schemaDriver, 191),
            dblayerStringType($schemaDriver),
        ),
    );

    return $table;
}

it('supports repository write helpers and convenience create-or-update operations', function (string $driver): void {
    $table = setupRepositoryFixture($driver);

    $repository = DB::repository($table)->forTenant(10);

    $created = $repository->create([
        'email' => 'alice@example.test',
        'name' => 'Alice',
        'active' => 1,
    ]);

    expect($created['tenant_id'] ?? null)->toBe(10);
    expect($created['id'] ?? null)->not->toBeNull();

    $bulkInserted = $repository->bulkInsert([
        [
            'email' => 'bob@example.test',
            'name' => 'Bob',
            'active' => 1,
        ],
        [
            'email' => 'charlie@example.test',
            'name' => 'Charlie',
            'active' => 0,
        ],
    ]);

    expect($bulkInserted)->toBeTrue();
    expect($repository->count())->toBe(3);

    $aliceId = $created['id'];
    expect($repository->updateById($aliceId, ['name' => 'Alice Updated']))->toBe(1);
    expect($repository->find($aliceId)['name'] ?? null)->toBe('Alice Updated');

    $existing = $repository->firstOrCreate(
        ['email' => 'alice@example.test'],
        ['name' => 'Should Not Override'],
    );
    expect($existing['id'] ?? null)->toBe($aliceId);
    expect($existing['name'] ?? null)->toBe('Alice Updated');

    $newlyCreated = $repository->firstOrCreate(
        ['email' => 'diana@example.test'],
        ['name' => 'Diana', 'active' => 1],
    );
    expect($newlyCreated['tenant_id'] ?? null)->toBe(10);
    expect($repository->count())->toBe(4);

    $updatedOrCreated = $repository->updateOrCreate(
        ['email' => 'diana@example.test'],
        ['name' => 'Diana Updated'],
    );
    expect($updatedOrCreated['name'] ?? null)->toBe('Diana Updated');

    $createdViaUpdateOrCreate = $repository->updateOrCreate(
        ['email' => 'eve@example.test'],
        ['name' => 'Eve', 'active' => 1],
    );
    expect($createdViaUpdateOrCreate['tenant_id'] ?? null)->toBe(10);
    expect($repository->count())->toBe(5);

    $upserted = $repository->upsert(
        [
            'email' => 'frank@example.test',
            'name' => 'Frank',
            'active' => 1,
        ],
        ['email'],
        ['name'],
    );
    expect($upserted)->toBeTrue();
    expect($repository->first(
        static function (QueryBuilder $query): void {
            $query->where('email', '=', 'frank@example.test');
        },
    )['name'] ?? null)->toBe('Frank');

    $deleted = $repository->deleteById($newlyCreated['id']);
    expect($deleted)->toBe(1);
    expect($repository->count())->toBe(5);
})->with('dblayer_drivers');

it('supports repository pagination and streaming helpers', function (string $driver): void {
    $table = setupRepositoryFixture($driver);

    $repository = DB::repository($table)
        ->forTenant(20)
        ->setDefaultOrder('id', 'asc');

    seedRepositoryUsers($table, [
        ['tenant_id' => 20, 'email' => 'u1@example.test', 'name' => 'U1', 'active' => 1],
        ['tenant_id' => 20, 'email' => 'u2@example.test', 'name' => 'U2', 'active' => 1],
        ['tenant_id' => 20, 'email' => 'u3@example.test', 'name' => 'U3', 'active' => 1],
        ['tenant_id' => 20, 'email' => 'u4@example.test', 'name' => 'U4', 'active' => 1],
        ['tenant_id' => 20, 'email' => 'u5@example.test', 'name' => 'U5', 'active' => 1],
        ['tenant_id' => 20, 'email' => 'u6@example.test', 'name' => 'U6', 'active' => 1],
    ]);

    $pageTwo = $repository->paginate(2, 2);
    expect($pageTwo->meta()['total'] ?? null)->toBe(6);
    expect($pageTwo->meta()['current_page'] ?? null)->toBe(2);
    expect($pageTwo->count())->toBe(2);

    $simple = $repository->simplePaginate(5, 1);
    expect($simple->count())->toBe(5);
    expect($simple->hasMorePages())->toBeTrue();

    $cursorPage = $repository->cursorPaginate(2, null, 'id', 'asc');
    expect($cursorPage->count())->toBe(2);
    expect($cursorPage->hasMorePages())->toBeTrue();
    expect($cursorPage->nextCursor())->not->toBeNull();

    $chunkedCount = 0;
    $repository->chunk(2, static function (array $rows, int $page) use (&$chunkedCount): bool {
        unset($page);
        $chunkedCount += count($rows);

        return true;
    });
    expect($chunkedCount)->toBe(6);

    $chunkedByIdCount = 0;
    $repository->chunkById(3, static function (array $rows, int $page) use (&$chunkedByIdCount): bool {
        unset($page);
        $chunkedByIdCount += count($rows);

        return true;
    });
    expect($chunkedByIdCount)->toBe(6);

    $cursorCount = 0;
    foreach ($repository->cursor(4) as $row) {
        expect($row['tenant_id'] ?? null)->toBe(20);
        $cursorCount++;
    }
    expect($cursorCount)->toBe(6);

    $lazyCount = 0;
    foreach ($repository->lazy(4) as $row) {
        expect($row['tenant_id'] ?? null)->toBe(20);
        $lazyCount++;
    }
    expect($lazyCount)->toBe(6);
})->with('dblayer_drivers');

it('covers repository read helpers and grouping accessors', function (string $driver): void {
    $table = setupRepositoryFixture($driver);

    seedRepositoryUsers($table, [
        ['tenant_id' => 40, 'email' => 'a1@example.test', 'name' => 'A1', 'active' => 1],
        ['tenant_id' => 40, 'email' => 'a2@example.test', 'name' => 'A2', 'active' => 0],
        ['tenant_id' => 41, 'email' => 'b1@example.test', 'name' => 'B1', 'active' => 1],
    ]);

    $repository = DB::repository($table);

    $builder = $repository->builder();
    expect($builder)->toBeInstanceOf(QueryBuilder::class);
    expect($builder->count())->toBe(3);

    expect($repository->exists(
        static function (QueryBuilder $query): void {
            $query->where('email', '=', 'a1@example.test');
        },
    ))->toBeTrue();
    expect($repository->exists(
        static function (QueryBuilder $query): void {
            $query->where('email', '=', 'missing@example.test');
        },
    ))->toBeFalse();

    $many = $repository->findMany([1, 3]);
    expect($many->count())->toBe(2);
    expect(array_column($many->toArray(), 'email'))->toBe(['a1@example.test', 'b1@example.test']);

    $grouped = $repository->groupByKey('tenant_id');
    expect(array_keys($grouped))->toBe([40, 41]);
    expect(count($grouped[40]))->toBe(2);
    expect(count($grouped[41]))->toBe(1);

    $value = $repository->value(
        'email',
        static function (QueryBuilder $query): void {
            $query->where('id', '=', 3);
        },
    );
    expect($value)->toBe('b1@example.test');

    $all = $repository->all();
    expect($all->count())->toBe(3);

    $firstMapped = $repository->firstMap(
        static fn(array $row): string => strtoupper((string) $row['email']),
        static function (QueryBuilder $query): void {
            $query->where('id', '=', 1);
        },
    );
    expect($firstMapped)->toBe('A1@EXAMPLE.TEST');

    $firstMappedMissing = $repository->firstMap(
        static fn(array $row): string => (string) $row['email'],
        static function (QueryBuilder $query): void {
            $query->where('id', '=', 999);
        },
    );
    expect($firstMappedMissing)->toBeNull();
})->with('dblayer_drivers');

it('supports repository global scopes, default ordering, tenant filtering, and DTO mapping', function (string $driver): void {
    $table = setupRepositoryFixture($driver);

    seedRepositoryUsers($table, [
        ['tenant_id' => 30, 'email' => 'a@example.test', 'name' => 'Anna', 'active' => 1],
        ['tenant_id' => 30, 'email' => 'b@example.test', 'name' => 'Bella', 'active' => 0],
        ['tenant_id' => 30, 'email' => 'c@example.test', 'name' => 'Cara', 'active' => 1],
        ['tenant_id' => 31, 'email' => 'd@example.test', 'name' => 'Dora', 'active' => 1],
    ]);

    $repository = DB::repository($table)
        ->forTenant(30)
        ->addGlobalScope(static function (QueryBuilder $query): void {
            $query->where('active', '=', 1);
        })
        ->addDefaultOrder('name', 'desc');

    $names = $repository->pluck('name');
    expect($names)->toBe(['Cara', 'Anna']);

    $mappedNames = $repository->map(
        static fn(array $row): string => strtoupper((string) $row['name']),
    );
    expect($mappedNames->toArray())->toBe(['CARA', 'ANNA']);

    $dtos = $repository->mapInto(RepositoryUserDto::class);
    expect($dtos->count())->toBe(2);
    expect($dtos->first())->toBeInstanceOf(RepositoryUserDto::class);
    expect($dtos->first()->tenant_id ?? null)->toBe(30);

    $firstDto = $repository->firstInto(
        RepositoryUserDto::class,
        static function (QueryBuilder $query): void {
            $query->where('email', '=', 'a@example.test');
        },
    );
    expect($firstDto)->toBeInstanceOf(RepositoryUserDto::class);
    expect($firstDto?->name)->toBe('Anna');

    $hydrated = $repository->firstInto(
        RepositoryHydratedDto::class,
        static function (QueryBuilder $query): void {
            $query->where('email', '=', 'c@example.test');
        },
    );
    expect($hydrated)->toBeInstanceOf(RepositoryHydratedDto::class);
    expect($hydrated?->id)->toBeGreaterThan(0);
    expect($hydrated?->email)->toBe('c@example.test');

    $tenantCount = $repository->count();
    expect($tenantCount)->toBe(2);

    $allVisible = $repository
        ->withoutTenant()
        ->clearGlobalScopes()
        ->setDefaultOrder('id', 'asc')
        ->clearDefaultOrders()
        ->count();
    expect($allVisible)->toBe(4);
})->with('dblayer_drivers');

it('throws clear exceptions for invalid repository configuration and DTO mapping', function (string $driver): void {
    $table = setupRepositoryFixture($driver);

    seedRepositoryUsers($table, [
        ['tenant_id' => 50, 'email' => 'x@example.test', 'name' => 'X', 'active' => 1],
    ]);

    $repository = DB::repository($table);

    expect(static function () use ($repository): void {
        $repository->addDefaultOrder('id', 'sideways');
    })->toThrow(InvalidArgumentException::class);

    expect(static function () use ($repository): void {
        $repository->mapInto('MissingDtoClass');
    })->toThrow(InvalidArgumentException::class);

    expect(static function () use ($repository): void {
        $repository->mapInto(RepositoryAbstractDto::class);
    })->toThrow(InvalidArgumentException::class);

    expect(static function () use ($repository): void {
        $repository->firstInto(RepositoryUserDto::class, null, ['name']);
    })->toThrow(InvalidArgumentException::class);
})->with('dblayer_drivers');

it('supports soft deletes, optimistic locking, casts, and lifecycle hooks', function (string $driver): void {
    setupRepositoryFixture($driver);
    $schemaDriver = dblayerConnectionDriver('repo');
    $table = dblayerTable('repository_features');

    DB::statement(
        sprintf('create table %s (
            %s,
            name %s not null,
            meta %s null,
            version integer not null default 1,
            deleted_at %s null
        )',
            $table,
            dblayerAutoIncrementPrimaryKey($schemaDriver),
            dblayerStringType($schemaDriver),
            dblayerStringType($schemaDriver),
            dblayerDateTimeType($schemaDriver),
        ),
    );

    $events = [
        'before_create' => 0,
        'after_create' => 0,
        'before_update' => 0,
        'after_update' => 0,
        'before_delete' => 0,
        'after_delete' => 0,
    ];

    $repository = DB::repository($table)
        ->enableSoftDeletes()
        ->enableOptimisticLocking('version')
        ->setCasts([
            'meta' => 'json',
            'version' => 'int',
        ])
        ->beforeCreate(static function (array $payload) use (&$events): array {
            $events['before_create']++;
            $payload['name'] = strtoupper((string) ($payload['name'] ?? ''));

            return $payload;
        })
        ->afterCreate(static function () use (&$events): void {
            $events['after_create']++;
        })
        ->beforeUpdate(static function (array $payload) use (&$events): array {
            $events['before_update']++;

            return $payload;
        })
        ->afterUpdate(static function () use (&$events): void {
            $events['after_update']++;
        })
        ->beforeDelete(static function () use (&$events): void {
            $events['before_delete']++;
        })
        ->afterDelete(static function () use (&$events): void {
            $events['after_delete']++;
        });

    $created = $repository->create([
        'name' => 'alpha',
        'meta' => ['flag' => true],
    ]);

    expect($created['name'] ?? null)->toBe('ALPHA');
    expect($created['meta'] ?? null)->toBe(['flag' => true]);
    expect($events['before_create'])->toBeGreaterThan(0);
    expect($events['after_create'])->toBeGreaterThan(0);

    $id = $created['id'];
    expect($repository->updateByIdWithVersion($id, ['name' => 'beta'], 1))->toBeTrue();
    expect($repository->updateByIdWithVersion($id, ['name' => 'gamma'], 1))->toBeFalse();

    $updated = $repository->find($id);
    expect($updated['version'] ?? null)->toBe(2);
    expect($events['before_update'])->toBeGreaterThan(0);
    expect($events['after_update'])->toBeGreaterThan(0);

    expect($repository->deleteById($id))->toBe(1);
    expect($repository->find($id))->toBeNull();
    expect($repository->withTrashed()->find($id))->not->toBeNull();
    expect($repository->onlyTrashed()->count())->toBe(1);
    expect($events['before_delete'])->toBeGreaterThan(0);
    expect($events['after_delete'])->toBeGreaterThan(0);

    expect($repository->restoreById($id))->toBe(1);
    expect($repository->withoutTrashed()->find($id))->not->toBeNull();

    expect($repository->forceDeleteById($id))->toBe(1);
    expect($repository->withTrashed()->find($id))->toBeNull();
})->with('dblayer_drivers');
