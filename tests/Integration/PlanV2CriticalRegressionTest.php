<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Events\Events;
use Infocyph\DBLayer\Exceptions\ConnectionException;
use Infocyph\DBLayer\Exceptions\QueryException;
use Infocyph\DBLayer\Query\Core\QueryType;
use Infocyph\DBLayer\Schema\SchemaManager;

it('executes insertGetId once and invalidates cached reads for returning mutations', function (): void {
    dblayerAddConnectionForDriver('sqlite');
    $table = dblayerTable('mutation_outcomes');
    $cache = Cache::memory('plan-v2-mutation-outcomes');
    DB::setCache($cache);
    DB::statement("create table {$table} (id integer primary key autoincrement, code text unique, value text)");

    $cached = DB::table($table)->orderBy('id')->cacheFor(60);
    expect($cached->get())->toBe([]);

    $id = DB::table($table)->insertGetId(['code' => 'one', 'value' => 'first']);
    expect((int) $id)->toBe(1)
        ->and((int) DB::table($table)->where('code', 'one')->count())->toBe(1)
        ->and($cached->get())->toHaveCount(1);

    $returned = DB::table($table)->insertReturning(['code' => 'two', 'value' => 'second']);
    expect($returned)->toBe(['id' => '2'])
        ->and($cached->get())->toHaveCount(2);

    $upserted = DB::table($table)->upsertReturning(
        ['code' => 'two', 'value' => 'updated'],
        ['code'],
        ['value'],
        ['id', 'code', 'value'],
    );
    expect($upserted)->toHaveCount(1)
        ->and($cached->get()[1]['value'])->toBe('updated');
});

it('invalidates truncate only after durable outer commit', function (): void {
    dblayerAddConnectionForDriver('sqlite');
    $table = dblayerTable('truncate_cache');
    DB::setCache(Cache::memory('plan-v2-truncate-cache'));
    DB::statement("create table {$table} (id integer primary key, value text)");
    DB::table($table)->insert([['id' => 1, 'value' => 'one'], ['id' => 2, 'value' => 'two']]);
    $cached = DB::table($table)->orderBy('id')->cacheFor(60);
    expect($cached->get())->toHaveCount(2);

    DB::beginTransaction();
    DB::table($table)->truncate();
    DB::rollBack();
    expect($cached->get())->toHaveCount(2);

    DB::beginTransaction();
    DB::beginTransaction();
    DB::table($table)->truncate();
    DB::rollBack();
    DB::commit();
    expect($cached->get())->toHaveCount(2);

    DB::beginTransaction();
    DB::beginTransaction();
    DB::table($table)->truncate();
    DB::commit();
    DB::commit();
    expect($cached->get())->toBe([]);
});

it('compiles empty membership predicates without broadening reads or writes', function (): void {
    dblayerAddConnectionForDriver('sqlite');
    $table = dblayerTable('empty_membership');
    DB::statement("create table {$table} (id integer primary key, value text)");
    DB::table($table)->insert([['id' => 1, 'value' => 'one'], ['id' => 2, 'value' => 'two']]);

    expect(DB::table($table)->whereIn('id', [])->get())->toBe([])
        ->and(DB::table($table)->whereIn('id', [])->update(['value' => 'changed']))->toBe(0)
        ->and(DB::table($table)->whereIn('id', [])->delete())->toBe(0)
        ->and((int) DB::table($table)->whereNotIn('id', [])->count())->toBe(2)
        ->and(DB::table($table)->orderBy('id')->pluck('value'))->toBe(['one', 'two']);
});

it('serves an exact cache hit without opening an unavailable database', function (): void {
    $directory = sys_get_temp_dir() . '/dblayer-plan-v2-cache-' . bin2hex(random_bytes(5));
    $offlineDirectory = $directory . '-offline';
    mkdir($directory, 0700, true);
    $database = $directory . '/database.sqlite';

    try {
        dblayerAddConnectionForDriver('sqlite', 'offline-cache', ['database' => $database]);
        DB::setCache(Cache::memory('plan-v2-offline-cache'));
        DB::statement('create table cache_rows (id integer primary key, value text)', [], 'offline-cache');
        DB::table('cache_rows', 'offline-cache')->insert(['id' => 1, 'value' => 'cached']);
        $query = DB::table('cache_rows', 'offline-cache')->where('id', 1)->cacheFor(60);
        expect($query->get())->toBe([['id' => 1, 'value' => 'cached']]);

        $connection = DB::connection('offline-cache');
        $connection->disconnect();
        rename($directory, $offlineDirectory);

        expect($connection->isConnected())->toBeFalse()
            ->and($query->get())->toBe([['id' => 1, 'value' => 'cached']])
            ->and($connection->isConnected())->toBeFalse();
    } finally {
        DB::purge();
        $storedDatabase = $offlineDirectory . '/database.sqlite';
        if (is_file($storedDatabase)) {
            unlink($storedDatabase);
        }
        if (is_dir($offlineDirectory)) {
            rmdir($offlineDirectory);
        }
        if (is_file($database)) {
            unlink($database);
        }
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
});

it('keeps explicit cache labels subordinate to SQL and binding identity', function (): void {
    dblayerAddConnectionForDriver('sqlite');
    $table = dblayerTable('cache_identity');
    DB::setCache(Cache::memory('plan-v2-cache-identity'));
    DB::statement("create table {$table} (id integer primary key, value text)");
    DB::table($table)->insert([['id' => 1, 'value' => 'one'], ['id' => 2, 'value' => 'two']]);

    $first = DB::table($table)->where('id', 1)->cacheFor(60)->cacheKey('dashboard');
    $second = DB::table($table)->where('id', 2)->cacheFor(60)->cacheKey('dashboard');

    expect($first->get()[0]['value'])->toBe('one')
        ->and($second->get()[0]['value'])->toBe('two')
        ->and($first->get()[0]['value'])->toBe('one');
});

it('bypasses automatic caching for every complex dependency graph unless explicitly tagged', function (): void {
    dblayerAddConnectionForDriver('sqlite');
    $table = dblayerTable('complex_dependencies');
    $cache = Cache::memory('plan-v2-complex-dependencies');
    DB::setCache($cache);
    DB::statement("create table {$table} (id integer primary key, value text)");
    DB::table($table)->insert([['id' => 1, 'value' => 'one'], ['id' => 2, 'value' => 'two']]);

    $child = DB::table($table)->select('id', 'value')->where('id', 1);
    $queries = [
        DB::connection()->query()->from('filtered')->with('filtered', $child)->select('id', 'value'),
        DB::connection()->query()->fromSub($child, 'derived')->select('derived.id', 'derived.value'),
        DB::table($table)->joinSub($child, 'derived', "{$table}.id", '=', 'derived.id')->select("{$table}.id"),
        DB::table($table)->select('id', 'value')->where('id', 1)
            ->union(DB::table($table)->select('id', 'value')->where('id', 2)),
        DB::table($table)->whereExists(
            static fn($query) => $query->from($table)->where('id', '>', 0),
        ),
    ];

    foreach ($queries as $query) {
        $query->cacheFor(60)->get();
        $query->get();
    }

    expect($cache->exportMetrics()['array']['remember_hit'] ?? 0)->toBe(0)
        ->and($cache->exportMetrics()['array']['remember_miss'] ?? 0)->toBe(0);

    $explicit = DB::connection()->query()
        ->fromSub($child, 'derived')
        ->select('derived.id', 'derived.value')
        ->cacheFor(60)
        ->cacheTags('complex-dependency');
    $explicit->get();
    $explicit->get();

    expect($cache->exportMetrics()['array']['remember_miss'] ?? 0)->toBe(1)
        ->and($cache->exportMetrics()['array']['remember_hit'] ?? 0)->toBe(1);
});

it('propagates raw provenance from every structured child query shape', function (): void {
    dblayerAddConnectionForDriver('sqlite');
    $child = DB::table('child_rows')->selectRaw('id');
    $parents = [
        DB::connection()->query()->from('child_cte')->with('child_cte', $child),
        DB::connection()->query()->fromSub($child, 'derived'),
        DB::table('parent_rows')->joinSub($child, 'derived', 'parent_rows.id', '=', 'derived.id'),
        DB::table('parent_rows')->union($child),
        DB::table('parent_rows')->whereExists(static fn($query) => $query->from('child_rows')->selectRaw('1')),
    ];

    foreach ($parents as $parent) {
        expect($parent->toPayload()->containsRawFragments)->toBeTrue();
    }
});

it('isolates success and failure observers from database semantics', function (): void {
    dblayerAddConnectionForDriver('sqlite');
    $table = dblayerTable('observer_isolation');
    DB::statement("create table {$table} (id integer primary key, value text)");
    Events::resetStats();

    Events::listen('db.query.executed', static fn() => throw new RuntimeException('success observer failed'));
    expect(DB::select('select 1 as value'))->toBe([['value' => 1]])
        ->and(DB::table($table)->insert(['id' => 1, 'value' => 'stored']))->toBeTrue()
        ->and((int) DB::table($table)->count())->toBe(1);

    Events::listen('db.query.failed', static fn() => throw new RuntimeException('failure observer failed'));
    expect(fn() => DB::select('select * from table_that_does_not_exist'))
        ->toThrow(ConnectionException::class, 'table_that_does_not_exist')
        ->and(Events::getDiagnostics())->not->toBe([]);

    Events::forgetAll();
});

it('dispatches the commit event before reporting an after-commit failure', function (): void {
    dblayerAddConnectionForDriver('sqlite');
    $committed = 0;
    Events::listen('db.transaction.committed', static function () use (&$committed): void {
        $committed++;
    });

    expect(fn() => DB::transaction(static function (): void {
        DB::afterCommit(static fn() => throw new RuntimeException('after-commit failed'));
    }))->toThrow(RuntimeException::class, 'after-commit failed')
        ->and($committed)->toBe(1)
        ->and(DB::transactionLevel())->toBe(0);

    Events::forgetAll();
});

it('enforces typed raw helper semantics before execution', function (): void {
    dblayerAddConnectionForDriver('sqlite');
    $connection = DB::connection();

    expect(fn() => $connection->select('insert into missing_table values (1)'))
        ->toThrow(QueryException::class)
        ->and(fn() => $connection->insert('select 1'))
        ->toThrow(QueryException::class)
        ->and(fn() => $connection->update('delete from missing_table'))
        ->toThrow(QueryException::class)
        ->and(fn() => $connection->delete('update missing_table set id = 1'))
        ->toThrow(QueryException::class)
        ->and(QueryType::SELECT->value)->toBe('select');
});

it('supports schema-qualified SQLite introspection', function (): void {
    dblayerAddConnectionForDriver('sqlite');
    DB::statement('create table qualified_items (id integer primary key, value text)');
    $schema = new SchemaManager(DB::connection());

    expect($schema->hasTable('main.qualified_items'))->toBeTrue()
        ->and($schema->hasColumn('main.qualified_items', 'value'))->toBeTrue()
        ->and($schema->hasColumn('main.qualified_items', 'missing'))->toBeFalse();
});
