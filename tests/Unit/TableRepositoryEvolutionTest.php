<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Tests\Unit;

use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Exceptions\UnwritableAttributeException;
use Infocyph\DBLayer\Query\QueryBuilder;
use Infocyph\DBLayer\Repository\Relation;
use Infocyph\DBLayer\Repository\RelationDefinition;
use Infocyph\DBLayer\Repository\TableRepository;

final class RepositoryEvolutionUser extends TableRepository
{
    protected static string $table = 'repository_evolution_users';

    protected static string $primaryKey = 'user_key';

    protected static function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    protected static function configureQuery(QueryBuilder $query): QueryBuilder
    {
        return $query->where('active', '=', 1);
    }
}

final class RepositoryEvolutionPagedUser extends TableRepository
{
    protected static string $table = 'repository_evolution_users';

    protected static string $primaryKey = 'user_key';

    protected static int $perPage = 2;
}

final class RepositoryEvolutionDefinitionProbe extends TableRepository
{
    public static int $castCalls = 0;

    protected static string $table = 'repository_evolution_users';

    protected static string $primaryKey = 'user_key';

    protected static function casts(): array
    {
        self::$castCalls++;

        return [
            'active' => 'boolean',
        ];
    }
}

final class RepositoryEvolutionScopedUser extends TableRepository
{
    protected static string $table = 'repository_evolution_users';

    protected static string $primaryKey = 'user_key';

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

final class RepositoryEvolutionPost extends TableRepository
{
    protected static string $table = 'repository_evolution_posts';

    protected static string $primaryKey = 'post_key';

    protected static bool $timestamps = true;

    protected static string $createdAt = 'created_on';

    protected static string $updatedAt = 'updated_on';

    protected static array $defaults = [
        'status' => 'draft',
    ];

    protected static array $creatable = [
        'title',
        'status',
    ];

    protected static array $updatable = [
        'title',
        'status',
    ];
}

final class RepositoryEvolutionParent extends TableRepository
{
    protected static string $table = 'repository_evolution_parents';

    /** @return array<string,RelationDefinition> */
    protected static function relations(): array
    {
        return [
            'children' => Relation::hasMany(
                RepositoryEvolutionChild::class,
                foreignKey: 'parent_id',
            ),
        ];
    }
}

final class RepositoryEvolutionChild extends TableRepository
{
    protected static string $table = 'repository_evolution_children';
}

beforeEach(function (): void {
    RepositoryEvolutionDefinitionProbe::flushDefinition();
    RepositoryEvolutionDefinitionProbe::$castCalls = 0;

    DB::purge();
    DB::setSecurityDefaults([], false);
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]);

    DB::statement(
        'create table repository_evolution_users (
            user_key integer primary key autoincrement,
            name text not null,
            active integer not null
        )',
    );

    DB::statement(
        'create table repository_evolution_posts (
            post_key integer primary key autoincrement,
            title text not null,
            status text not null,
            created_on text not null,
            updated_on text not null
        )',
    );

    DB::statement(
        'create table repository_evolution_parents (
            id integer primary key autoincrement,
            name text not null
        )',
    );

    DB::statement(
        'create table repository_evolution_children (
            id integer primary key autoincrement,
            parent_id integer not null,
            name text not null,
            active integer not null
        )',
    );

    DB::table('repository_evolution_users')->insert([
        ['name' => 'Active One', 'active' => 1],
        ['name' => 'Inactive', 'active' => 0],
        ['name' => 'Active Two', 'active' => 1],
    ]);
});

afterEach(function (): void {
    DB::purge();
});

it('compiles declarative repository metadata once per class', function (): void {
    $first = RepositoryEvolutionDefinitionProbe::definition();
    $second = RepositoryEvolutionDefinitionProbe::definition();

    expect($second)->toBe($first)
        ->and($first->table)->toBe('repository_evolution_users')
        ->and($first->primaryKey)->toBe('user_key')
        ->and(RepositoryEvolutionDefinitionProbe::$castCalls)->toBe(1);

    RepositoryEvolutionDefinitionProbe::flushDefinition();
    $third = RepositoryEvolutionDefinitionProbe::definition();

    expect($third)->not->toBe($first)
        ->and(RepositoryEvolutionDefinitionProbe::$castCalls)->toBe(2);
});

it('uses repository page-size metadata across pagination entry points', function (): void {
    $direct = RepositoryEvolutionPagedUser::paginate();
    $fluent = RepositoryEvolutionPagedUser::query()
        ->orderBy('user_key')
        ->paginate();
    $simple = RepositoryEvolutionPagedUser::query()
        ->orderBy('user_key')
        ->simplePaginate();
    $cursor = RepositoryEvolutionPagedUser::query()->cursorPaginate();

    expect($direct->perPage())->toBe(2)
        ->and($direct->count())->toBe(2)
        ->and($fluent->perPage())->toBe(2)
        ->and($fluent->count())->toBe(2)
        ->and($simple->perPage())->toBe(2)
        ->and($simple->count())->toBe(2)
        ->and($cursor->perPage())->toBe(2)
        ->and($cursor->count())->toBe(2);
});

it('applies query defaults consistently to repository and fluent static reads', function (): void {
    $direct = RepositoryEvolutionUser::get()->toArray();
    $fluent = RepositoryEvolutionUser::where('user_key', '>', 0)->get()->toArray();

    expect(array_column($direct, 'name'))->toBe(['Active One', 'Active Two'])
        ->and(array_column($fluent, 'name'))->toBe(['Active One', 'Active Two'])
        ->and($direct[0]['active'])->toBeTrue()
        ->and($fluent[0]['active'])->toBeTrue();
});

it('uses declarative primary-key metadata for repository identity helpers', function (): void {
    $row = RepositoryEvolutionUser::find(3);

    expect($row)->not->toBeNull()
        ->and($row['user_key'])->toBe(3)
        ->and($row['name'])->toBe('Active Two');
});

it('keeps raw builder access explicit', function (): void {
    $rawRows = RepositoryEvolutionUser::builder()
        ->orderBy('user_key')
        ->get();

    expect(array_column($rawRows, 'name'))->toBe(['Active One', 'Active Two']);
});

it('removes named global scopes only for the current repository query', function (): void {
    $scoped = RepositoryEvolutionScopedUser::query()
        ->orderBy('user_key')
        ->get()
        ->toArray();

    $unscoped = RepositoryEvolutionScopedUser::query()
        ->orderBy('user_key')
        ->withoutGlobalScope('active')
        ->get()
        ->toArray();

    $freshScoped = RepositoryEvolutionScopedUser::query()
        ->orderBy('user_key')
        ->get()
        ->toArray();

    expect(array_column($scoped, 'name'))->toBe(['Active One', 'Active Two'])
        ->and(array_column($unscoped, 'name'))->toBe(['Active One', 'Inactive', 'Active Two'])
        ->and(array_column($freshScoped, 'name'))->toBe(['Active One', 'Active Two']);
});

it('routes static named scope removal to the repository query wrapper', function (): void {
    $rows = RepositoryEvolutionScopedUser::withoutGlobalScope('active')
        ->orderBy('user_key')
        ->get()
        ->toArray();

    expect(array_column($rows, 'name'))->toBe(['Active One', 'Inactive', 'Active Two']);
});

it('rebuilds the raw builder after named global scope removal', function (): void {
    $count = RepositoryEvolutionScopedUser::query()
        ->where('user_key', '>', 0)
        ->withoutGlobalScope('active')
        ->raw()
        ->count();

    expect($count)->toBe(3);
});

it('applies repository defaults and custom timestamps on create', function (): void {
    $post = RepositoryEvolutionPost::create([
        'title' => 'Repository First',
    ]);

    expect($post['status'])->toBe('draft')
        ->and($post['created_on'])->toBeString()->not->toBe('')
        ->and($post['updated_on'])->toBeString()->not->toBe('');
});

it('updates the configured timestamp column on repository updates', function (): void {
    DB::table('repository_evolution_posts')->insert([
        'title' => 'Before',
        'status' => 'draft',
        'created_on' => '2000-01-01 00:00:00',
        'updated_on' => '2000-01-01 00:00:00',
    ]);

    RepositoryEvolutionPost::updateById(1, ['title' => 'After']);
    $post = RepositoryEvolutionPost::find(1);

    expect($post['title'])->toBe('After')
        ->and($post['updated_on'])->not->toBe('2000-01-01 00:00:00');
});

it('rejects attributes outside create and update policies', function (): void {
    expect(fn() => RepositoryEvolutionPost::create([
        'title' => 'Invalid',
        'internal_notes' => 'nope',
    ]))->toThrow(UnwritableAttributeException::class);

    $post = RepositoryEvolutionPost::create(['title' => 'Valid']);

    expect(fn() => RepositoryEvolutionPost::updateById(
        $post['post_key'],
        ['internal_notes' => 'nope'],
    ))->toThrow(UnwritableAttributeException::class);
});

it('eager loads declared has-many relations in bounded batches', function (): void {
    DB::table('repository_evolution_parents')->insert([
        ['name' => 'One'],
        ['name' => 'Two'],
    ]);
    DB::table('repository_evolution_children')->insert([
        ['parent_id' => 1, 'name' => 'A', 'active' => 1],
        ['parent_id' => 1, 'name' => 'B', 'active' => 0],
        ['parent_id' => 2, 'name' => 'C', 'active' => 1],
    ]);

    $parents = RepositoryEvolutionParent::query()
        ->with('children')
        ->orderBy('id')
        ->get()
        ->toArray();

    expect(array_column($parents[0]['children'], 'name'))->toBe(['A', 'B'])
        ->and(array_column($parents[1]['children'], 'name'))->toBe(['C']);
});

it('supports constrained eager relation loading', function (): void {
    DB::table('repository_evolution_parents')->insert(['name' => 'One']);
    DB::table('repository_evolution_children')->insert([
        ['parent_id' => 1, 'name' => 'A', 'active' => 1],
        ['parent_id' => 1, 'name' => 'B', 'active' => 0],
    ]);

    $parent = RepositoryEvolutionParent::query()
        ->with([
            'children' => static fn(QueryBuilder $query) => $query->where('active', '=', 1),
        ])
        ->first();

    expect(array_column($parent['children'], 'name'))->toBe(['A']);
});

it('keeps relation projections correct when base columns are narrowed', function (): void {
    DB::table('repository_evolution_parents')->insert(['name' => 'One']);
    DB::table('repository_evolution_children')->insert([
        ['parent_id' => 1, 'name' => 'A', 'active' => 1],
    ]);

    $parent = RepositoryEvolutionParent::query()
        ->with('children')
        ->first(['name']);

    expect($parent['name'])->toBe('One')
        ->and(array_column($parent['children'], 'name'))->toBe(['A']);
});
