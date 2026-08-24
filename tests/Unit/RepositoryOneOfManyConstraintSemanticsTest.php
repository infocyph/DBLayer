<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Tests\Unit;

use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Query\QueryBuilder;
use Infocyph\DBLayer\Repository\Relation;
use Infocyph\DBLayer\Repository\RelationDefinition;
use Infocyph\DBLayer\Repository\TableRepository;

final class RepositoryWinnerParent extends TableRepository
{
    protected static string $table = 'repository_winner_parents';

    /** @return array<string,RelationDefinition> */
    protected static function relations(): array
    {
        return [
            'latest_child' => Relation::hasMany(
                RepositoryWinnerChild::class,
                'parent_id',
            )->latestOfMany('published_at'),
        ];
    }
}

final class RepositoryWinnerChild extends TableRepository
{
    protected static string $table = 'repository_winner_children';
}

final class RepositoryWinnerCountry extends TableRepository
{
    protected static string $table = 'repository_winner_countries';

    /** @return array<string,RelationDefinition> */
    protected static function relations(): array
    {
        return [
            'latest_post' => Relation::hasManyThrough(
                RepositoryWinnerPost::class,
                RepositoryWinnerUser::class,
                firstKey: 'country_id',
                secondKey: 'user_id',
            )->latestOfMany('published_at'),
        ];
    }
}

final class RepositoryWinnerUser extends TableRepository
{
    protected static string $table = 'repository_winner_users';
}

final class RepositoryWinnerPost extends TableRepository
{
    protected static string $table = 'repository_winner_posts';
}

beforeEach(function (): void {
    foreach ([
        RepositoryWinnerParent::class,
        RepositoryWinnerChild::class,
        RepositoryWinnerCountry::class,
        RepositoryWinnerUser::class,
        RepositoryWinnerPost::class,
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
        'create table repository_winner_parents (
            id integer primary key autoincrement,
            name text not null
        )',
    );
    DB::statement(
        'create table repository_winner_children (
            id integer primary key autoincrement,
            parent_id integer not null,
            title text not null,
            active integer not null,
            amount integer not null,
            published_at text not null
        )',
    );
    DB::statement(
        'create table repository_winner_countries (
            id integer primary key autoincrement,
            name text not null
        )',
    );
    DB::statement(
        'create table repository_winner_users (
            id integer primary key autoincrement,
            country_id integer not null
        )',
    );
    DB::statement(
        'create table repository_winner_posts (
            id integer primary key autoincrement,
            user_id integer not null,
            title text not null,
            active integer not null,
            amount integer not null,
            published_at text not null
        )',
    );

    DB::table('repository_winner_parents')->insert(['name' => 'Parent']);
    DB::table('repository_winner_children')->insert([
        [
            'parent_id' => 1,
            'title' => 'Older Active',
            'active' => 1,
            'amount' => 10,
            'published_at' => '2026-01-01 00:00:00',
        ],
        [
            'parent_id' => 1,
            'title' => 'Latest Inactive',
            'active' => 0,
            'amount' => 20,
            'published_at' => '2026-01-02 00:00:00',
        ],
    ]);

    DB::table('repository_winner_countries')->insert(['name' => 'Country']);
    DB::table('repository_winner_users')->insert([
        ['country_id' => 1],
        ['country_id' => 1],
    ]);
    DB::table('repository_winner_posts')->insert([
        [
            'user_id' => 1,
            'title' => 'Older Through Active',
            'active' => 1,
            'amount' => 30,
            'published_at' => '2026-02-01 00:00:00',
        ],
        [
            'user_id' => 2,
            'title' => 'Latest Through Inactive',
            'active' => 0,
            'amount' => 40,
            'published_at' => '2026-02-02 00:00:00',
        ],
    ]);
});

afterEach(function (): void {
    DB::purge();
});

it('does not promote an older direct row for constrained eager loading', function (): void {
    $parent = RepositoryWinnerParent::repositoryQuery()
        ->with([
            'latest_child' => static function (QueryBuilder $query): void {
                $query->where('active', '=', 1);
            },
        ])
        ->first();

    expect($parent['latest_child'])->toBeNull();
});

it('filters the selected direct winner for constrained aggregates', function (): void {
    $parent = RepositoryWinnerParent::repositoryQuery()
        ->withCount([
            'latest_child' => static function (QueryBuilder $query): void {
                $query->where('active', '=', 1);
            },
        ])
        ->withAggregate(
            'latest_child',
            'amount',
            'sum',
            'active_latest_amount',
            static function (QueryBuilder $query): void {
                $query->where('active', '=', 1);
            },
        )
        ->first();

    expect($parent['latest_child_count'])->toBe(0)
        ->and($parent['active_latest_amount'])->toBeNull();
});

it('does not promote an older through row for constrained eager loading', function (): void {
    $country = RepositoryWinnerCountry::repositoryQuery()
        ->with([
            'latest_post' => static function (QueryBuilder $query): void {
                $query->where('active', '=', 1);
            },
        ])
        ->first();

    expect($country['latest_post'])->toBeNull();
});

it('filters the selected through winner for constrained aggregates', function (): void {
    $country = RepositoryWinnerCountry::repositoryQuery()
        ->withCount([
            'latest_post' => static function (QueryBuilder $query): void {
                $query->where('active', '=', 1);
            },
        ])
        ->withAggregate(
            'latest_post',
            'amount',
            'sum',
            'active_latest_amount',
            static function (QueryBuilder $query): void {
                $query->where('active', '=', 1);
            },
        )
        ->first();

    expect($country['latest_post_count'])->toBe(0)
        ->and($country['active_latest_amount'])->toBeNull();
});
