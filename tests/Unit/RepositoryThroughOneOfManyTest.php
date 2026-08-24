<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Tests\Unit;

use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Query\QueryBuilder;
use Infocyph\DBLayer\Repository\Relation;
use Infocyph\DBLayer\Repository\RelationDefinition;
use Infocyph\DBLayer\Repository\TableRepository;

final class RepositoryThroughCountry extends TableRepository
{
    protected static string $table = 'repository_through_countries';

    /** @return array<string,RelationDefinition> */
    protected static function relations(): array
    {
        $posts = Relation::hasManyThrough(
            RepositoryThroughPost::class,
            RepositoryThroughUser::class,
            firstKey: 'country_id',
            secondKey: 'user_id',
        );

        return [
            'posts' => $posts,
            'latest_post' => $posts->latestOfMany('created_at'),
            'top_post' => $posts->one()->ofMany('score', 'max'),
            'projected_latest_post' => $posts
                ->latestOfMany('created_at')
                ->select(['title']),
            'profile' => Relation::hasOneThrough(
                RepositoryThroughProfile::class,
                RepositoryThroughUser::class,
                firstKey: 'country_id',
                secondKey: 'user_id',
            ),
        ];
    }
}

final class RepositoryThroughUser extends TableRepository
{
    protected static string $table = 'repository_through_users';

    /** @return array<string,RelationDefinition> */
    protected static function relations(): array
    {
        $posts = Relation::hasMany(RepositoryThroughPost::class, 'user_id');

        return [
            'posts' => $posts,
            'latest_post' => $posts->latestOfMany('created_at'),
            'oldest_post' => $posts->oldestOfMany('created_at'),
            'top_post_via_one' => $posts->one()->ofMany('score', 'max'),
            'top_active_post' => $posts->ofMany(
                'score',
                'max',
                static function (QueryBuilder $query): void {
                    $query->where('active', '=', 1);
                },
            ),
            'projected_latest_post' => $posts
                ->latestOfMany('created_at')
                ->select(['title']),
            'latest_image' => Relation::morphMany(
                RepositoryThroughImage::class,
                name: 'imageable',
                morph: 'user',
            )->latestOfMany('created_at'),
        ];
    }
}

final class RepositoryThroughPost extends TableRepository
{
    protected static string $table = 'repository_through_posts';

    /** @return array<string,RelationDefinition> */
    protected static function relations(): array
    {
        return [
            'author' => Relation::belongsTo(RepositoryThroughUser::class, 'user_id'),
        ];
    }
}

final class RepositoryThroughProfile extends TableRepository
{
    protected static string $table = 'repository_through_profiles';
}

final class RepositoryThroughImage extends TableRepository
{
    protected static string $table = 'repository_through_images';
}

beforeEach(function (): void {
    foreach ([
        RepositoryThroughCountry::class,
        RepositoryThroughUser::class,
        RepositoryThroughPost::class,
        RepositoryThroughProfile::class,
        RepositoryThroughImage::class,
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
        'create table repository_through_countries (
            id integer primary key autoincrement,
            name text not null
        )',
    );
    DB::statement(
        'create table repository_through_users (
            id integer primary key autoincrement,
            country_id integer not null,
            name text not null
        )',
    );
    DB::statement(
        'create table repository_through_posts (
            id integer primary key autoincrement,
            user_id integer not null,
            title text not null,
            score integer not null,
            active integer not null,
            created_at text not null
        )',
    );
    DB::statement(
        'create table repository_through_profiles (
            id integer primary key autoincrement,
            user_id integer not null,
            bio text not null
        )',
    );
    DB::statement(
        'create table repository_through_images (
            id integer primary key autoincrement,
            imageable_type text not null,
            imageable_id integer not null,
            path text not null,
            created_at text not null
        )',
    );

    DB::table('repository_through_countries')->insert([
        ['name' => 'Country One'],
        ['name' => 'Country Two'],
    ]);
    DB::table('repository_through_users')->insert([
        ['country_id' => 1, 'name' => 'User One'],
        ['country_id' => 1, 'name' => 'User Two'],
        ['country_id' => 2, 'name' => 'User Three'],
    ]);
    DB::table('repository_through_posts')->insert([
        [
            'user_id' => 1,
            'title' => 'Older U1',
            'score' => 5,
            'active' => 1,
            'created_at' => '2026-01-01 00:00:00',
        ],
        [
            'user_id' => 1,
            'title' => 'Latest U1',
            'score' => 10,
            'active' => 0,
            'created_at' => '2026-01-03 00:00:00',
        ],
        [
            'user_id' => 2,
            'title' => 'Latest U2',
            'score' => 20,
            'active' => 1,
            'created_at' => '2026-01-02 00:00:00',
        ],
        [
            'user_id' => 2,
            'title' => 'Older U2',
            'score' => 30,
            'active' => 1,
            'created_at' => '2025-12-31 00:00:00',
        ],
        [
            'user_id' => 3,
            'title' => 'Country Two Post',
            'score' => 7,
            'active' => 1,
            'created_at' => '2026-01-04 00:00:00',
        ],
    ]);
    DB::table('repository_through_profiles')->insert([
        ['user_id' => 3, 'bio' => 'Country Two Profile'],
    ]);
    DB::table('repository_through_images')->insert([
        [
            'imageable_type' => 'user',
            'imageable_id' => 1,
            'path' => '/older.png',
            'created_at' => '2026-01-01 00:00:00',
        ],
        [
            'imageable_type' => 'user',
            'imageable_id' => 1,
            'path' => '/latest.png',
            'created_at' => '2026-01-05 00:00:00',
        ],
        [
            'imageable_type' => 'post',
            'imageable_id' => 1,
            'path' => '/wrong-type.png',
            'created_at' => '2026-01-06 00:00:00',
        ],
    ]);
});

afterEach(function (): void {
    DB::purge();
});

it('loads has-many-through relations through repository-aware intermediate keys', function (): void {
    $countries = RepositoryThroughCountry::query()
        ->with('posts')
        ->orderBy('id')
        ->get()
        ->toArray();

    expect(array_column($countries[0]['posts'], 'title'))->toBe([
        'Older U1',
        'Latest U1',
        'Latest U2',
        'Older U2',
    ])->and(array_column($countries[1]['posts'], 'title'))->toBe(['Country Two Post']);
});

it('loads nested relations beneath a through relation', function (): void {
    $country = RepositoryThroughCountry::query()
        ->with('posts.author')
        ->where('id', '=', 1)
        ->first();

    expect($country)->not->toBeNull()
        ->and($country['posts'][0]['author']['name'])->toBe('User One')
        ->and($country['posts'][2]['author']['name'])->toBe('User Two');
});

it('loads has-one-through relations without introducing entity state', function (): void {
    $country = RepositoryThroughCountry::query()
        ->with('profile')
        ->where('id', '=', 2)
        ->first();

    expect($country['profile'])->toBeArray()
        ->and($country['profile']['bio'])->toBe('Country Two Profile');
});

it('supports aggregates and existence filters on through relations', function (): void {
    $country = RepositoryThroughCountry::query()
        ->withCount('posts')
        ->withSum('posts', 'score')
        ->withAvg('posts', 'score')
        ->withMin('posts', 'score')
        ->withMax('posts', 'score')
        ->where('id', '=', 1)
        ->first();

    expect($country['posts_count'])->toBe(4)
        ->and((float) $country['posts_sum_score'])->toBe(65.0)
        ->and((float) $country['posts_avg_score'])->toBe(16.25)
        ->and((int) $country['posts_min_score'])->toBe(5)
        ->and((int) $country['posts_max_score'])->toBe(30);

    $matching = RepositoryThroughCountry::query()
        ->whereRelation('posts', 'title', '=', 'Latest U2')
        ->get()
        ->toArray();
    $missing = RepositoryThroughCountry::query()
        ->whereDoesntHave('posts')
        ->get()
        ->toArray();

    expect(array_column($matching, 'name'))->toBe(['Country One'])
        ->and($missing)->toBe([]);
});

it('selects latest oldest and custom one-of-many rows deterministically', function (): void {
    $user = RepositoryThroughUser::query()
        ->with('latest_post', 'oldest_post', 'top_post_via_one', 'top_active_post')
        ->where('id', '=', 1)
        ->first();

    expect($user['latest_post']['title'])->toBe('Latest U1')
        ->and($user['oldest_post']['title'])->toBe('Older U1')
        ->and($user['top_post_via_one']['title'])->toBe('Latest U1')
        ->and($user['top_active_post']['title'])->toBe('Older U1');
});

it('supports polymorphic one-of-many selection with explicit morph aliases', function (): void {
    $user = RepositoryThroughUser::query()
        ->with('latest_image')
        ->where('id', '=', 1)
        ->first();

    expect($user['latest_image']['path'])->toBe('/latest.png');
});

it('selects one-of-many winners across a through relation', function (): void {
    $country = RepositoryThroughCountry::query()
        ->with('latest_post', 'top_post')
        ->where('id', '=', 1)
        ->first();

    expect($country['latest_post']['title'])->toBe('Latest U1')
        ->and($country['top_post']['title'])->toBe('Older U2');
});

it('aggregates a through one-of-many relation as the selected row only', function (): void {
    $country = RepositoryThroughCountry::query()
        ->withCount('latest_post')
        ->withSum('latest_post', 'score')
        ->where('id', '=', 1)
        ->first();

    expect($country['latest_post_count'])->toBe(1)
        ->and((int) $country['latest_post_sum_score'])->toBe(10);
});

it('applies whereRelation to the selected one-of-many winner rather than older rows', function (): void {
    $latestMatch = RepositoryThroughUser::query()
        ->whereRelation('latest_post', 'title', '=', 'Latest U1')
        ->get()
        ->toArray();
    $olderMatch = RepositoryThroughUser::query()
        ->whereRelation('latest_post', 'title', '=', 'Older U1')
        ->get()
        ->toArray();
    $throughOlderMatch = RepositoryThroughCountry::query()
        ->whereRelation('latest_post', 'title', '=', 'Older U2')
        ->get()
        ->toArray();

    expect(array_column($latestMatch, 'name'))->toBe(['User One'])
        ->and($olderMatch)->toBe([])
        ->and($throughOlderMatch)->toBe([]);
});

it('keeps one-of-many ordering keys internal for narrow projections', function (): void {
    $user = RepositoryThroughUser::query()
        ->with('projected_latest_post')
        ->where('id', '=', 1)
        ->first();
    $country = RepositoryThroughCountry::query()
        ->with('projected_latest_post')
        ->where('id', '=', 1)
        ->first();

    expect($user['projected_latest_post'])->toBe(['title' => 'Latest U1'])
        ->and($country['projected_latest_post'])->toBe(['title' => 'Latest U1']);
});
