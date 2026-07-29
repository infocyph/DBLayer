<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Repository\RelationLoader;
use Infocyph\DBLayer\Schema\Blueprint;
use Infocyph\DBLayer\Schema\SchemaManager;

function relationTestLoader(int $batchSize = 500): RelationLoader
{
    DB::purge();
    DB::addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $connection = DB::connection();
    $schema = new SchemaManager($connection);

    $schema->create('users', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });
    $schema->create('posts', static function (Blueprint $table): void {
        $table->id();
        $table->bigInteger('user_id');
        $table->string('title');
    });
    $schema->create('roles', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });
    $schema->create('role_user', static function (Blueprint $table): void {
        $table->bigInteger('user_id');
        $table->bigInteger('role_id');
    });

    $connection->table('users')->insert([
        ['id' => 1, 'name' => 'Ada'],
        ['id' => 2, 'name' => 'Linus'],
        ['id' => 3, 'name' => 'Grace'],
    ]);
    $connection->table('posts')->insert([
        ['id' => 10, 'user_id' => 1, 'title' => 'A'],
        ['id' => 11, 'user_id' => 1, 'title' => 'B'],
        ['id' => 12, 'user_id' => 2, 'title' => 'C'],
    ]);
    $connection->table('roles')->insert([
        ['id' => 20, 'name' => 'admin'],
        ['id' => 21, 'name' => 'writer'],
    ]);
    $connection->table('role_user')->insert([
        ['user_id' => 1, 'role_id' => 20],
        ['user_id' => 1, 'role_id' => 21],
        ['user_id' => 2, 'role_id' => 21],
    ]);
    $connection->resetStats();

    return new RelationLoader($connection, $batchSize);
}

it('loads one and many relations in bounded batches', function (): void {
    $loader = relationTestLoader(2);
    $parents = [
        ['id' => 1],
        ['id' => 2],
        ['id' => 3],
    ];

    $one = $loader->one($parents, 'id', 'posts', 'user_id', 'first_post');

    expect($one[0]['first_post']['title'])->toBe('A')
        ->and($one[1]['first_post']['title'])->toBe('C')
        ->and($one[2]['first_post'])->toBeNull()
        ->and($loader->lastQueryCount())->toBe(2)
        ->and($loader->lastRelatedRowCount())->toBe(3);

    $many = $loader->many($parents, 'id', 'posts', 'user_id', 'posts', ['title']);

    expect($many[0]['posts'])->toHaveCount(2)
        ->and($many[1]['posts'])->toHaveCount(1)
        ->and($many[2]['posts'])->toBe([])
        ->and($many[0]['posts'][0])->toHaveKeys(['title', 'user_id'])
        ->and($loader->lastQueryCount())->toBe(2);
});

it('loads many-to-many relations without per-parent queries', function (): void {
    $loader = relationTestLoader(2);
    $parents = [
        ['id' => 1],
        ['id' => 2],
        ['id' => 3],
    ];

    $loaded = $loader->manyToMany(
        $parents,
        'id',
        'role_user',
        'user_id',
        'role_id',
        'roles',
        'id',
        'roles',
    );

    expect(array_column($loaded[0]['roles'], 'name'))->toBe(['admin', 'writer'])
        ->and(array_column($loaded[1]['roles'], 'name'))->toBe(['writer'])
        ->and($loaded[2]['roles'])->toBe([])
        ->and($loader->lastQueryCount())->toBe(3)
        ->and($loader->lastRelatedRowCount())->toBe(5);
});

it('performs no relation query when parent keys are absent', function (): void {
    $loader = relationTestLoader();

    $loaded = $loader->many([['id' => null], []], 'id', 'posts', 'user_id', 'posts');

    expect($loaded[0]['posts'])->toBe([])
        ->and($loaded[1]['posts'])->toBe([])
        ->and($loader->lastQueryCount())->toBe(0);
});
