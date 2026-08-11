<?php

declare(strict_types=1);

use Infocyph\ArrayKit\Collection\Collection;
use Infocyph\ArrayKit\Collection\LazyCollection;
use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheInterface;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\DBLayer\DB;

it('lazily creates and explicitly replaces the CacheLayer cache', function (): void {
    expect(DB::cache())->toBeInstanceOf(CacheInterface::class);

    $custom = Cache::memory('dblayer-custom');
    DB::setCache($custom);

    expect(DB::cache())->toBe($custom);

    $tiered = Cache::tiered([
        ['driver' => 'memory', 'namespace' => 'dblayer-l1'],
        ['driver' => 'memory', 'namespace' => 'dblayer-l2'],
    ]);
    DB::setCache($tiered);

    expect(DB::cache())->toBe($tiered);
});

it('caches query misses and hits then invalidates table tags after writes', function (string $driver): void {
    dblayerAddConnectionForDriver($driver);
    $table = dblayerTable('query_cache');
    $cache = Cache::memory('dblayer-query-cache');
    DB::setCache($cache);

    DB::statement(sprintf('create table %s (id integer primary key, name %s not null)', $table, dblayerStringType($driver)));
    DB::table($table)->insert(['id' => 1, 'name' => 'before']);

    $query = DB::table($table)->where('id', '=', 1)->cacheFor(60)->cacheTags('query-cache');

    expect($query->get())->toBe([['id' => 1, 'name' => 'before']]);
    expect($query->get())->toBe([['id' => 1, 'name' => 'before']]);
    expect($cache->exportMetrics()['array']['remember_miss'] ?? 0)->toBe(1);
    expect($cache->exportMetrics()['array']['remember_hit'] ?? 0)->toBe(1);

    DB::table($table)->where('id', '=', 1)->update(['name' => 'after']);

    expect($query->get())->toBe([['id' => 1, 'name' => 'after']]);
    expect($cache->exportMetrics()['array']['remember_miss'] ?? 0)->toBe(2);
})->with('dblayer_drivers');

it('defers cache invalidation until commit and discards rolled-back and retried callbacks', function (string $driver): void {
    dblayerAddConnectionForDriver($driver);
    $table = dblayerTable('transaction_cache');
    $cache = Cache::memory('dblayer-transaction-cache');
    DB::setCache($cache);

    DB::statement(sprintf('create table %s (id integer primary key, name %s not null)', $table, dblayerStringType($driver)));
    DB::table($table)->insert(['id' => 1, 'name' => 'original']);
    $query = DB::table($table)->where('id', '=', 1)->cacheFor(60);
    $query->get();

    DB::beginTransaction();
    DB::table($table)->where('id', '=', 1)->update(['name' => 'rolled-back']);
    DB::rollBack();

    expect($query->get()[0]['name'])->toBe('original');
    expect($cache->exportMetrics()['array']['remember_miss'] ?? 0)->toBe(1);

    $attempts = 0;
    DB::transaction(static function () use (&$attempts, $driver, $table): void {
        $attempts++;
        DB::table($table)->where('id', '=', 1)->update(['name' => 'committed']);

        if ($attempts === 1) {
            throw new RuntimeException(dblayerTransientDeadlockMessage($driver));
        }
    }, 2);

    expect($attempts)->toBe(2);
    expect($query->get()[0]['name'])->toBe('committed');
    expect($cache->exportMetrics()['array']['remember_miss'] ?? 0)->toBe(2);
})->with('dblayer_drivers');

it('bypasses shared caching for sticky locked streaming and explicitly uncached reads', function (): void {
    $database = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dblayer-sticky-' . bin2hex(random_bytes(6)) . '.sqlite';
    dblayerAddConnectionForDriver('sqlite', 'sticky-cache', [
        'database' => $database,
        'sticky' => true,
    ]);
    $table = dblayerTable('cache_bypass');
    $cache = Cache::memory('dblayer-cache-bypass');
    DB::setCache($cache);

    DB::statement(
        sprintf('create table %s (id integer primary key, name text not null)', $table),
        [],
        'sticky-cache',
    );
    DB::table($table, 'sticky-cache')->insert(['id' => 1, 'name' => 'one']);
    DB::reconnect('sticky-cache');

    $query = DB::table($table, 'sticky-cache')->cacheFor(60);
    $query->get();
    DB::table($table, 'sticky-cache')->where('id', '=', 1)->update(['name' => 'fresh']);

    expect($query->get()[0]['name'])->toBe('fresh');
    $query->cloneBuilder()->lockForUpdate()->get();
    iterator_to_array($query->cloneBuilder()->cursor());
    $query->cloneBuilder()->withoutCache()->get();

    expect($cache->exportMetrics()['array']['remember_miss'] ?? 0)->toBe(1);
    expect($cache->exportMetrics()['array']['remember_hit'] ?? 0)->toBe(0);

    DB::purge();
    unlink($database);
});

it('exposes ArrayKit collection contracts and bounded ordered repository batches', function (string $driver): void {
    dblayerAddConnectionForDriver($driver, 'arraykit', ['security' => ['max_params' => 2]]);
    $table = dblayerTable('arraykit_results');

    DB::statement(
        sprintf('create table %s (id integer primary key, name %s not null)', $table, dblayerStringType($driver)),
        [],
        'arraykit',
    );
    DB::table($table, 'arraykit')->insert([
        ['id' => 1, 'name' => 'one'],
        ['id' => 2, 'name' => 'two'],
        ['id' => 3, 'name' => 'three'],
        ['id' => 4, 'name' => 'four'],
    ]);

    $collection = DB::table($table, 'arraykit')->orderBy('id')->collect();
    $lazy = DB::table($table, 'arraykit')->lazyCollection(2);
    $found = DB::repository($table, 'arraykit')->findMany([4, 1, '1', 4, 3]);

    expect($collection)->toBeInstanceOf(Collection::class);
    expect($collection->pluck('name')->all())->toBe(['one', 'two', 'three', 'four']);
    expect($lazy)->toBeInstanceOf(LazyCollection::class);
    expect(array_column($lazy->take(2)->all(), 'id'))->toBe([1, 2]);
    expect($found)->toBeInstanceOf(Collection::class);
    expect(array_map('intval', array_column($found->all(), 'id')))->toBe([4, 1, 1, 4, 3]);
    expect(function_exists('collect'))->toBeFalse();
    expect(class_exists('Infocyph\\DBLayer\\Support\\Collection'))->toBeFalse();
})->with('dblayer_drivers');

it('rejects malformed cold connection structures', function (): void {
    expect(static fn(): ConnectionConfig => new ConnectionConfig([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'options' => 'invalid',
    ]))->toThrow(InvalidArgumentException::class);
});
