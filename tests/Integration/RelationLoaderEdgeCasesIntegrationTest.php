<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Query\QueryBuilder;
use Infocyph\DBLayer\Repository\RelationLoader;
use Infocyph\DBLayer\Schema\Blueprint;
use Infocyph\DBLayer\Schema\SchemaManager;

function relationEdgeLoader(int $batchSize = 500): RelationLoader
{
    DB::purge();
    DB::addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $connection = DB::connection();
    $schema = new SchemaManager($connection);

    $schema->create('edge_users', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });
    $schema->create('edge_posts', static function (Blueprint $table): void {
        $table->id();
        $table->bigInteger('user_id');
        $table->string('title');
    });
    $schema->create('edge_roles', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });
    $schema->create('edge_role_user', static function (Blueprint $table): void {
        $table->bigInteger('user_id');
        $table->bigInteger('role_id');
    });

    $connection->table('edge_users')->insert([
        ['id' => 1, 'name' => 'one'],
        ['id' => 2, 'name' => 'two'],
        ['id' => 3, 'name' => 'three'],
        ['id' => 4, 'name' => 'four'],
        ['id' => 5, 'name' => 'five'],
    ]);
    $connection->table('edge_posts')->insert([
        ['id' => 10, 'user_id' => 1, 'title' => 'first'],
        ['id' => 11, 'user_id' => 1, 'title' => 'second'],
        ['id' => 12, 'user_id' => 2, 'title' => 'third'],
        ['id' => 13, 'user_id' => 4, 'title' => 'fourth'],
        ['id' => 14, 'user_id' => 5, 'title' => 'fifth'],
    ]);
    $connection->table('edge_roles')->insert([
        ['id' => 20, 'name' => 'admin'],
        ['id' => 21, 'name' => 'writer'],
    ]);
    $connection->table('edge_role_user')->insert([
        ['user_id' => 1, 'role_id' => 20],
        ['user_id' => 1, 'role_id' => 21],
        ['user_id' => 2, 'role_id' => 21],
    ]);
    $connection->resetStats();

    return new RelationLoader($connection, $batchSize);
}

it('uses the exact bounded query count at and across chunk boundaries', function (): void {
    $loader = relationEdgeLoader(2);
    $parents = array_map(static fn(int $id): array => ['id' => $id], range(1, 5));

    $loaded = $loader->many(
        $parents,
        'id',
        'edge_posts',
        'user_id',
        'posts',
    );

    expect($loader->lastQueryCount())->toBe(3)
        ->and($loader->lastRelatedRowCount())->toBe(5)
        ->and(array_map(
            static fn(array $parent): int => count($parent['posts']),
            $loaded,
        ))->toBe([2, 1, 0, 1, 1]);
});

it('deduplicates lookup keys while preserving duplicate parent projections', function (): void {
    $loader = relationEdgeLoader(2);
    $parents = [
        ['id' => 1],
        ['id' => 1],
        ['id' => '1'],
        ['id' => null],
        [],
    ];

    $loaded = $loader->many(
        $parents,
        'id',
        'edge_posts',
        'user_id',
        'posts',
        ['title'],
    );

    expect($loader->lastQueryCount())->toBe(1)
        ->and($loaded[0]['posts'])->toHaveCount(2)
        ->and($loaded[1]['posts'])->toBe($loaded[0]['posts'])
        ->and($loaded[2]['posts'])->toBe($loaded[0]['posts'])
        ->and($loaded[0]['posts'][0])->toHaveKeys(['title', 'user_id'])
        ->and($loaded[3]['posts'])->toBe([])
        ->and($loaded[4]['posts'])->toBe([]);
});

it('applies scopes once per batch and makes one-relation ordering deterministic', function (): void {
    $loader = relationEdgeLoader(2);
    $scopeCalls = 0;
    $parents = [['id' => 1], ['id' => 2], ['id' => 3]];

    $loaded = $loader->one(
        $parents,
        'id',
        'edge_posts',
        'user_id',
        'post',
        ['id', 'title'],
        static function (QueryBuilder $query) use (&$scopeCalls): void {
            $scopeCalls++;
            $query->where('id', '>', 10)->orderBy('id', 'desc');
        },
    );

    expect($scopeCalls)->toBe(2)
        ->and($loader->lastQueryCount())->toBe(2)
        ->and($loaded[0]['post']['title'])->toBe('second')
        ->and($loaded[1]['post']['title'])->toBe('third')
        ->and($loaded[2]['post'])->toBeNull();
});

it('preserves duplicate pivot order skips missing rows and selects mapping keys', function (): void {
    $loader = relationEdgeLoader(2);
    $connection = DB::connection();
    $connection->table('edge_role_user')->insert([
        ['user_id' => 1, 'role_id' => 20],
        ['user_id' => 1, 'role_id' => 99],
    ]);
    $connection->resetStats();

    $loaded = $loader->manyToMany(
        [['id' => 1]],
        'id',
        'edge_role_user',
        'user_id',
        'role_id',
        'edge_roles',
        'id',
        'roles',
        ['name'],
    );

    expect(array_column($loaded[0]['roles'], 'name'))->toBe(['admin', 'writer', 'admin'])
        ->and($loaded[0]['roles'][0])->toHaveKeys(['id', 'name'])
        ->and($loader->lastQueryCount())->toBe(3)
        ->and($loader->lastRelatedRowCount())->toBe(6);
});

it('rejects invalid batch sizes and non-scalar relation keys', function (): void {
    expect(fn() => relationEdgeLoader(0))
        ->toThrow(InvalidArgumentException::class, 'at least one');

    $loader = relationEdgeLoader();

    expect(fn(): array => $loader->many(
        [['id' => ['invalid']]],
        'id',
        'edge_posts',
        'user_id',
        'posts',
    ))->toThrow(InvalidArgumentException::class, 'scalar or null');
});

it('performs no queries for empty direct or pivot relation inputs', function (): void {
    $loader = relationEdgeLoader(2);

    expect($loader->many([], 'id', 'edge_posts', 'user_id', 'posts'))->toBe([])
        ->and($loader->lastQueryCount())->toBe(0)
        ->and($loader->lastRelatedRowCount())->toBe(0)
        ->and($loader->manyToMany(
            [],
            'id',
            'edge_role_user',
            'user_id',
            'role_id',
            'edge_roles',
            'id',
            'roles',
        ))->toBe([])
        ->and($loader->lastQueryCount())->toBe(0)
        ->and($loader->lastRelatedRowCount())->toBe(0);
});
