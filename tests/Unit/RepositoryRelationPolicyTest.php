<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Query\QueryBuilder;
use Infocyph\DBLayer\Repository\Relation;
use Infocyph\DBLayer\Repository\RelationDefinition;
use Infocyph\DBLayer\Repository\TableRepository;

final class RepositoryRelationPolicyParent extends TableRepository
{
    protected static string $table = 'repository_relation_policy_parents';

    /** @return array<string,RelationDefinition> */
    protected static function relations(): array
    {
        return [
            'children' => Relation::hasMany(
                RepositoryRelationPolicyChild::class,
                foreignKey: 'parent_id',
            ),
        ];
    }
}

final class RepositoryRelationPolicyChild extends TableRepository
{
    protected static string $table = 'repository_relation_policy_children';

    protected static function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    /** @return array<string,callable(QueryBuilder):void> */
    protected static function globalScopes(): array
    {
        return [
            'active' => static function (QueryBuilder $query): void {
                $query->where('active', '=', 1);
            },
        ];
    }
}

beforeEach(function (): void {
    DB::purge();
    DB::setSecurityDefaults([], false);
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]);

    DB::statement(
        'create table repository_relation_policy_parents (
            id integer primary key autoincrement,
            name text not null
        )',
    );

    DB::statement(
        'create table repository_relation_policy_children (
            id integer primary key autoincrement,
            parent_id integer not null,
            name text not null,
            active integer not null
        )',
    );

    DB::table('repository_relation_policy_parents')->insert(['name' => 'Parent']);
    DB::table('repository_relation_policy_children')->insert([
        ['parent_id' => 1, 'name' => 'Visible', 'active' => 1],
        ['parent_id' => 1, 'name' => 'Hidden', 'active' => 0],
    ]);
});

afterEach(function (): void {
    DB::purge();
});

it('preserves related repository global scopes and casts during eager loading', function (): void {
    $parent = RepositoryRelationPolicyParent::query()
        ->with('children')
        ->first();

    expect(array_column($parent['children'], 'name'))->toBe(['Visible'])
        ->and($parent['children'][0]['active'])->toBeTrue();
});

it('counts relations without hydrating rows and preserves related scopes', function (): void {
    $parent = RepositoryRelationPolicyParent::query()
        ->withCount('children')
        ->first();

    expect($parent['children_count'])->toBe(1)
        ->and(array_key_exists('children', $parent))->toBeFalse();
});

it('supports constrained and aliased relation counts', function (): void {
    $parent = RepositoryRelationPolicyParent::query()
        ->withCount([
            'children as visible_named_count' => static function (QueryBuilder $query): void {
                $query->where('name', '=', 'Visible');
            },
        ])
        ->first();

    expect($parent['visible_named_count'])->toBe(1);
});
