<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Migration\Migration;
use Infocyph\DBLayer\Migration\MigrationContext;
use Infocyph\DBLayer\Migration\MigrationRunner;
use Infocyph\DBLayer\Query\JoinClause;
use Infocyph\DBLayer\Schema\Blueprint;
use Infocyph\DBLayer\Schema\SchemaManager;

function prefixEdgeConnection(): \Infocyph\DBLayer\Connection\Connection
{
    DB::purge();
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => 'edge_',
    ]);
    $connection = DB::connection();
    $schema = new SchemaManager($connection);
    $schema->create('groups', static fn(Blueprint $table) => $table->id());
    $schema->create('items', static function (Blueprint $table): void {
        $table->id();
        $table->bigInteger('group_id');
        $table->string('name');
    });
    $connection->table('groups')->insert(['id' => 10]);
    $connection->table('items')->insert(['id' => 1, 'group_id' => 10, 'name' => 'item']);

    return $connection;
}

it('applies prefixes through complex compiler joins', function (): void {
    $connection = prefixEdgeConnection();

    $count = $connection
        ->table('items')
        ->joinComplex('groups', static function (JoinClause $join): void {
            $join->on('items.group_id', '=', 'groups.id')
                ->whereNotNull('groups.id');
        })
        ->where('items.id', 1)
        ->count();

    expect($count)->toBe(1);
});

it('applies prefixes to qualified compiler mutations', function (): void {
    $connection = prefixEdgeConnection();

    $updated = $connection
        ->table('items')
        ->where(static fn($query) => $query->where('items.id', 1))
        ->update(['name' => 'updated']);
    $deleted = $connection
        ->table('items')
        ->where(static fn($query) => $query->where('items.id', 1))
        ->delete();

    expect($updated)->toBe(1)
        ->and($deleted)->toBe(1)
        ->and($connection->table('items')->count())->toBe(0);
});

it('keeps derived-table aliases logical while prefixing their source tables', function (): void {
    $connection = prefixEdgeConnection();
    $subquery = $connection->table('items')->select(['id', 'name']);

    $row = $connection
        ->query()
        ->fromSub($subquery, 'selected_items')
        ->select(['selected_items.id', 'selected_items.name'])
        ->where('selected_items.id', 1)
        ->first();

    expect($row)->toMatchArray(['id' => 1, 'name' => 'item']);
});

it('keeps derived-table aliases logical in structured compiler payloads', function (): void {
    $connection = prefixEdgeConnection();
    $subquery = $connection->table('items')->select(['id', 'name']);

    $row = $connection
        ->query()
        ->fromSub($subquery, 'selected_items')
        ->distinct()
        ->select(['selected_items.id', 'selected_items.name'])
        ->where('selected_items.id', 1)
        ->first();

    expect($row)->toMatchArray(['id' => 1, 'name' => 'item']);
});

it('keeps common-table-expression names unprefixed', function (): void {
    $connection = prefixEdgeConnection();

    $rows = $connection
        ->table('items')
        ->with('selected_items', static function ($query): void {
            $query->from('items')->select(['id', 'name'])->where('id', 1);
        })
        ->from('selected_items')
        ->select(['selected_items.id', 'selected_items.name'])
        ->get();

    expect($rows)->toBe([['id' => 1, 'name' => 'item']]);
});

it('applies prefixes to subquery joins without rewriting the alias', function (): void {
    $connection = prefixEdgeConnection();
    $groups = $connection->table('groups')->select('id');

    $count = $connection
        ->table('items')
        ->joinSub($groups, 'selected_groups', 'items.group_id', '=', 'selected_groups.id')
        ->where('items.id', 1)
        ->count();

    expect($count)->toBe(1);
});

it('truncates the prefixed physical table', function (): void {
    $connection = prefixEdgeConnection();

    expect($connection->table('items')->truncate())->toBeTrue()
        ->and($connection->table('items')->count())->toBe(0)
        ->and((new SchemaManager($connection))->tables())->toContain('edge_items');
});

it('isolates schema relation and migration state by named connection', function (): void {
    DB::purge();
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => 'alpha_',
    ], 'alpha');
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => 'beta_',
    ], 'beta');
    $alpha = DB::connection('alpha');
    $beta = DB::connection('beta');
    $alphaSchema = DB::schema('alpha');
    $betaSchema = DB::schema('beta');

    $alphaSchema->create('users', static fn(Blueprint $table) => $table->id());
    $alphaSchema->create('posts', static function (Blueprint $table): void {
        $table->id();
        $table->bigInteger('user_id');
    });
    $betaSchema->create('users', static fn(Blueprint $table) => $table->id());
    $alpha->table('users')->insert(['id' => 1]);
    $alpha->table('posts')->insert(['id' => 10, 'user_id' => 1]);
    $beta->table('users')->insert(['id' => 2]);

    $relations = DB::relations('alpha', 1)->many(
        [['id' => 1]],
        'id',
        'posts',
        'user_id',
        'posts',
    );
    $migration = new class implements Migration {
        public function down(SchemaManager $schema, MigrationContext $context): void
        {
            $context->checkpoint();
            $schema->dropIfExists('migration_items');
        }

        public function id(): string
        {
            return '20260729000000_named_connection';
        }

        public function up(SchemaManager $schema, MigrationContext $context): void
        {
            $context->checkpoint();
            $schema->create('migration_items', static fn(Blueprint $table) => $table->id());
        }
    };
    $runner = new MigrationRunner($alpha, [$migration]);

    expect($relations[0]['posts'])->toHaveCount(1)
        ->and($runner->run())->toBe([$migration->id()])
        ->and($alphaSchema->hasTable('migration_items'))->toBeTrue()
        ->and($betaSchema->hasTable('migration_items'))->toBeFalse()
        ->and($alphaSchema->hasTable('migrations'))->toBeTrue()
        ->and($betaSchema->hasTable('migrations'))->toBeFalse()
        ->and($alpha->table('users')->count())->toBe(1)
        ->and($beta->table('users')->count())->toBe(1);
});
