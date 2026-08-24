<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Tests\Unit;

use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Query\QueryBuilder;
use Infocyph\DBLayer\Repository\Relation;
use Infocyph\DBLayer\Repository\RelationDefinition;
use Infocyph\DBLayer\Repository\TableRepository;

final class RepositoryAdvancedOneParent extends TableRepository
{
    protected static string $table = 'repository_advanced_one_parents';

    /** @return array<string,RelationDefinition> */
    protected static function relations(): array
    {
        $children = Relation::hasMany(RepositoryAdvancedOneChild::class, 'parent_id');

        return [
            'current_child' => $children->ofMany(
                [
                    'published_at' => 'max',
                    'id' => 'max',
                ],
                static function (QueryBuilder $query): void {
                    $query->where('enabled', '=', 1);
                },
            ),
        ];
    }
}

final class RepositoryAdvancedOneChild extends TableRepository
{
    protected static string $table = 'repository_advanced_one_children';
}

final class RepositoryAdvancedOneCountry extends TableRepository
{
    protected static string $table = 'repository_advanced_one_countries';

    /** @return array<string,RelationDefinition> */
    protected static function relations(): array
    {
        $posts = Relation::hasManyThrough(
            RepositoryAdvancedOnePost::class,
            RepositoryAdvancedOneUser::class,
            firstKey: 'country_id',
            secondKey: 'user_id',
        );

        return [
            'current_post' => $posts->ofMany(
                [
                    'published_at' => 'max',
                    'id' => 'max',
                ],
                static function (QueryBuilder $query): void {
                    $query->where('enabled', '=', 1);
                },
            ),
        ];
    }
}

final class RepositoryAdvancedOneUser extends TableRepository
{
    protected static string $table = 'repository_advanced_one_users';
}

final class RepositoryAdvancedOnePost extends TableRepository
{
    protected static string $table = 'repository_advanced_one_posts';
}

beforeEach(function (): void {
    foreach ([
        RepositoryAdvancedOneParent::class,
        RepositoryAdvancedOneChild::class,
        RepositoryAdvancedOneCountry::class,
        RepositoryAdvancedOneUser::class,
        RepositoryAdvancedOnePost::class,
    ] as $repository) {
        $repository::flushDefinition();
    }

    DB::purge();
    DB::setSecurityDefaults([], false);
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]);

    DB::statement(
        'create table repository_advanced_one_parents (
            id integer primary key autoincrement,
            name text not null
        )',
    );
    DB::statement(
        'create table repository_advanced_one_children (
            id integer primary key autoincrement,
            parent_id integer not null,
            title text not null,
            enabled integer not null,
            amount integer not null,
            published_at text not null
        )',
    );
    DB::statement(
        'create table repository_advanced_one_countries (
            id integer primary key autoincrement,
            name text not null
        )',
    );
    DB::statement(
        'create table repository_advanced_one_users (
            id integer primary key autoincrement,
            country_id integer not null,
            name text not null
        )',
    );
    DB::statement(
        'create table repository_advanced_one_posts (
            id integer primary key autoincrement,
            user_id integer not null,
            title text not null,
            enabled integer not null,
            amount integer not null,
            published_at text not null
        )',
    );

    DB::table('repository_advanced_one_parents')->insert(['name' => 'Parent One']);
    DB::table('repository_advanced_one_children')->insert([
        [
            'parent_id' => 1,
            'title' => 'Older Active',
            'enabled' => 1,
            'amount' => 10,
            'published_at' => '2026-01-01 00:00:00',
        ],
        [
            'parent_id' => 1,
            'title' => 'Newer Disabled',
            'enabled' => 0,
            'amount' => 20,
            'published_at' => '2026-01-03 00:00:00',
        ],
        [
            'parent_id' => 1,
            'title' => 'Tie Loser',
            'enabled' => 1,
            'amount' => 30,
            'published_at' => '2026-01-02 00:00:00',
        ],
        [
            'parent_id' => 1,
            'title' => 'Tie Winner',
            'enabled' => 1,
            'amount' => 40,
            'published_at' => '2026-01-02 00:00:00',
        ],
    ]);

    DB::table('repository_advanced_one_countries')->insert(['name' => 'Country One']);
    DB::table('repository_advanced_one_users')->insert([
        ['country_id' => 1, 'name' => 'User One'],
        ['country_id' => 1, 'name' => 'User Two'],
    ]);
    DB::table('repository_advanced_one_posts')->insert([
        [
            'user_id' => 1,
            'title' => 'Through Tie Loser',
            'enabled' => 1,
            'amount' => 50,
            'published_at' => '2026-02-01 00:00:00',
        ],
        [
            'user_id' => 2,
            'title' => 'Through Tie Winner',
            'enabled' => 1,
            'amount' => 60,
            'published_at' => '2026-02-01 00:00:00',
        ],
        [
            'user_id' => 1,
            'title' => 'Through Newer Disabled',
            'enabled' => 0,
            'amount' => 70,
            'published_at' => '2026-02-02 00:00:00',
        ],
    ]);
});

afterEach(function (): void {
    DB::purge();
});

it('selects advanced direct one-of-many rows lexicographically after candidate scope', function (): void {
    $parent = RepositoryAdvancedOneParent::repositoryQuery()
        ->with('current_child')
        ->withCount('current_child')
        ->withSum('current_child', 'amount')
        ->first();

    expect($parent['current_child']['title'])->toBe('Tie Winner')
        ->and($parent['current_child_count'])->toBe(1)
        ->and((int) $parent['current_child_sum_amount'])->toBe(40);
});

it('uses the advanced selected winner for relation existence filters', function (): void {
    $winner = RepositoryAdvancedOneParent::repositoryQuery()
        ->whereRelation('current_child', 'title', '=', 'Tie Winner')
        ->get()
        ->toArray();
    $loser = RepositoryAdvancedOneParent::repositoryQuery()
        ->whereRelation('current_child', 'title', '=', 'Tie Loser')
        ->get()
        ->toArray();
    $disabled = RepositoryAdvancedOneParent::repositoryQuery()
        ->whereRelation('current_child', 'title', '=', 'Newer Disabled')
        ->get()
        ->toArray();

    expect(array_column($winner, 'name'))->toBe(['Parent One'])
        ->and($loser)->toBe([])
        ->and($disabled)->toBe([]);
});

it('applies advanced one-of-many criteria across through relations', function (): void {
    $country = RepositoryAdvancedOneCountry::repositoryQuery()
        ->with('current_post')
        ->withCount('current_post')
        ->withSum('current_post', 'amount')
        ->first();

    expect($country['current_post']['title'])->toBe('Through Tie Winner')
        ->and($country['current_post_count'])->toBe(1)
        ->and((int) $country['current_post_sum_amount'])->toBe(60);

    $winner = RepositoryAdvancedOneCountry::repositoryQuery()
        ->whereRelation('current_post', 'title', '=', 'Through Tie Winner')
        ->get()
        ->toArray();
    $disabled = RepositoryAdvancedOneCountry::repositoryQuery()
        ->whereRelation('current_post', 'title', '=', 'Through Newer Disabled')
        ->get()
        ->toArray();

    expect(array_column($winner, 'name'))->toBe(['Country One'])
        ->and($disabled)->toBe([]);
});
