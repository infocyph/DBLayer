<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheInterface;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;

function dblayerInstanceCachedSqliteConnection(): Connection
{
    return new Connection(
        ConnectionConfig::fromArray([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]),
        'instance-cache',
    );
}

it('invalidates qualified and joined PostgreSQL dependencies across default schemas', function (): void {
    $config = dblayerRequireDriver('pgsql');
    $schemaA = dblayerTable('cache_schema_a');
    $schemaB = dblayerTable('cache_schema_b');
    $first = new Connection(ConnectionConfig::fromArray($config + ['schema' => $schemaA]), 'schema-cache');
    $second = new Connection(ConnectionConfig::fromArray($config + ['schema' => $schemaB]), 'schema-cache');
    $setup = new Connection(ConnectionConfig::fromArray($config), 'schema-cache-setup');
    $cache = Cache::memory('schema-cache-' . bin2hex(random_bytes(4)));
    $first->setQueryCache($cache);
    $second->setQueryCache($cache);

    try {
        $setup->statement("create schema {$schemaA}");
        $setup->statement("create schema {$schemaB}");
        $setup->statement("create table {$schemaA}.items (id integer primary key, value text)");
        $setup->statement("create table {$schemaB}.items (id integer primary key, value text)");
        $setup->statement("create table {$schemaB}.labels (item_id integer primary key, label text)");
        $first->table('items')->insert(['id' => 1, 'value' => 'original']);
        $second->table('items')->insert(['id' => 1, 'value' => 'isolated']);
        $second->table('labels')->insert(['item_id' => 1, 'label' => 'before']);

        $read = fn(): ?array => $second->table("{$schemaA}.items")->cacheFor(60)->first();
        $join = fn(): array => $first->table('items')
            ->join("{$schemaB}.labels", 'items.id', '=', "{$schemaB}.labels.item_id")
            ->select('items.value', "{$schemaB}.labels.label")->cacheFor(60)->get();

        expect($read()['value'])->toBe('original')
            ->and($join()[0]['label'])->toBe('before');
        $first->table('items')->where('id', '=', 1)->update(['value' => 'changed']);
        $second->table('labels')->where('item_id', '=', 1)->update(['label' => 'after']);

        expect($read()['value'])->toBe('changed')
            ->and($join()[0]['label'])->toBe('after')
            ->and($second->table('items')->cacheFor(60)->first()['value'])->toBe('isolated');
        $second->table("{$schemaA}.items")->where('id', '=', 1)->update(['value' => 'qualified-write']);
        expect($first->table('items')->cacheFor(60)->first()['value'])->toBe('qualified-write');
    } finally {
        $first->disconnect();
        $second->disconnect();
        $setup->statement("drop schema if exists {$schemaA} cascade");
        $setup->statement("drop schema if exists {$schemaB} cascade");
        $setup->disconnect();
    }
});

it('uses an explicitly connection-owned cache without static DB registration', function (): void {
    $connection = dblayerInstanceCachedSqliteConnection();
    $cache = Cache::memory('instance-query-cache-' . bin2hex(random_bytes(4)));
    $connection->setQueryCache($cache);

    expect($connection->queryCache())->toBe($cache);

    $connection->statement('create table cache_items (id integer primary key, value text not null)');
    $connection->table('cache_items')->insert(['id' => 1, 'value' => 'first']);

    $read = static fn(): array => $connection->table('cache_items')
        ->where('id', '=', 1)
        ->cacheFor(60)
        ->first() ?? [];

    expect($read()['value'] ?? null)->toBe('first');

    $connection->table('cache_items')
        ->where('id', '=', 1)
        ->update(['value' => 'second']);

    expect($read()['value'] ?? null)->toBe('second');
});

it('invalidates only after a successful top-level commit', function (): void {
    $connection = dblayerInstanceCachedSqliteConnection();
    $connection->setQueryCache(Cache::memory('instance-query-cache-tx-' . bin2hex(random_bytes(4))));
    $connection->statement('create table cache_items (id integer primary key, value text not null)');
    $connection->table('cache_items')->insert(['id' => 1, 'value' => 'stable']);

    $read = static fn(): array => $connection->table('cache_items')
        ->where('id', '=', 1)
        ->cacheFor(60)
        ->first() ?? [];

    expect($read()['value'] ?? null)->toBe('stable');

    $connection->beginTransaction();
    $connection->table('cache_items')->where('id', '=', 1)->update(['value' => 'rolled-back']);
    $connection->rollbackTransaction();

    expect($read()['value'] ?? null)->toBe('stable');

    $connection->beginTransaction();
    $connection->table('cache_items')->where('id', '=', 1)->update(['value' => 'committed']);
    $connection->commitTransaction();

    expect($read()['value'] ?? null)->toBe('committed');
});

it('keeps invalidation bound to the exact connection instance', function (): void {
    $database = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . 'dblayer-instance-cache-'
        . bin2hex(random_bytes(8))
        . '.sqlite';
    $first = null;
    $second = null;

    try {
        $config = ConnectionConfig::fromArray([
            'driver' => 'sqlite',
            'database' => $database,
        ]);
        $first = new Connection($config, 'same-name');
        $second = new Connection($config, 'same-name');
        $first->setQueryCache(Cache::memory('instance-cache-first-' . bin2hex(random_bytes(4))));
        $second->setQueryCache(Cache::memory('instance-cache-second-' . bin2hex(random_bytes(4))));

        $first->statement('create table cache_items (id integer primary key, value text not null)');
        $first->table('cache_items')->insert(['id' => 1, 'value' => 'before']);

        $firstRead = static fn(): array => $first->table('cache_items')->where('id', '=', 1)->cacheFor(60)->first() ?? [];
        $secondRead = static fn(): array => $second->table('cache_items')->where('id', '=', 1)->cacheFor(60)->first() ?? [];

        expect($firstRead()['value'] ?? null)->toBe('before')
            ->and($secondRead()['value'] ?? null)->toBe('before');

        $first->table('cache_items')->where('id', '=', 1)->update(['value' => 'after']);

        expect($firstRead()['value'] ?? null)->toBe('after')
            ->and($secondRead()['value'] ?? null)->toBe('before');
    } finally {
        $first?->disconnect();
        $second?->disconnect();

        if (is_file($database)) {
            unlink($database);
        }
    }
});

it('coordinates invalidation across instances that deliberately share one cache backend', function (): void {
    $database = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . 'dblayer-shared-instance-cache-'
        . bin2hex(random_bytes(8))
        . '.sqlite';
    $first = null;
    $second = null;

    try {
        $config = ConnectionConfig::fromArray([
            'driver' => 'sqlite',
            'database' => $database,
        ]);
        $sharedCache = Cache::memory('instance-cache-shared-' . bin2hex(random_bytes(4)));
        $first = new Connection($config, 'shared-name');
        $second = new Connection($config, 'shared-name');
        $first->setQueryCache($sharedCache);
        $second->setQueryCache($sharedCache);

        $first->statement('create table cache_items (id integer primary key, value text not null)');
        $first->table('cache_items')->insert(['id' => 1, 'value' => 'before']);

        $secondRead = static fn(): array => $second->table('cache_items')
            ->where('id', '=', 1)
            ->cacheFor(60)
            ->first() ?? [];

        expect($secondRead()['value'] ?? null)->toBe('before');

        $first->table('cache_items')->where('id', '=', 1)->update(['value' => 'after']);

        expect($secondRead()['value'] ?? null)->toBe('after');
    } finally {
        $first?->disconnect();
        $second?->disconnect();

        if (is_file($database)) {
            unlink($database);
        }
    }
});

it('does not resolve a query cache for connection setup ordinary reads or cleanup', function (): void {
    $calls = 0;
    $connection = new Connection(ConnectionConfig::fromArray(['driver' => 'sqlite', 'database' => ':memory:']));
    $connection->setQueryCacheResolver(static function () use (&$calls): CacheInterface {
        ++$calls;
        throw new RuntimeException('Unused query cache was initialized.');
    });

    expect($connection->isConnected())->toBeFalse()->and($calls)->toBe(0)
        ->and($connection->hasQueryCache())->toBeTrue();
    expect($connection->scalar('SELECT 1'))->toBe(1)->and($calls)->toBe(0);
    expect($connection->resetRuntimeStateForReuse())->toBeTrue()->and($calls)->toBe(0);
    $connection->disconnect();
    expect($calls)->toBe(0);
});

it('invalidates a shared cache on writes before its first cached read', function (): void {
    $connection = new Connection(ConnectionConfig::fromArray(['driver' => 'sqlite', 'database' => ':memory:']));
    $connection->statement('create table items (id integer primary key, value text not null)');
    $calls = 0;
    $cache = Cache::memory('deferred-write-' . bin2hex(random_bytes(4)));
    $connection->setQueryCacheResolver(static function () use (&$calls, $cache): CacheInterface {
        ++$calls;
        return $cache;
    });
    $connection->table('items')->insert(['id' => 1, 'value' => 'written']);
    expect($calls)->toBe(1);
    $cache->set('external-data', 'retained');
    $connection->disconnect();
    expect($connection->queryCache())->toBe($cache)
        ->and($cache->get('external-data'))->toBe('retained')
        ->and($calls)->toBe(1);
});

it('rejects invalid cache resolver results without retaining them', function (): void {
    $connection = new Connection(ConnectionConfig::fromArray(['driver' => 'sqlite', 'database' => ':memory:']));
    $connection->setQueryCacheResolver(static fn(): stdClass => new stdClass());
    expect(fn() => $connection->queryCache())->toThrow(TypeError::class);
    $connection->setQueryCache(null);
    expect($connection->hasQueryCache())->toBeFalse()
        ->and($connection->queryCache())->toBeInstanceOf(CacheInterface::class);
});

it('resolves and shares a configured cache for cached reads and write invalidation', function (): void {
    $connection = new Connection(ConnectionConfig::fromArray(['driver' => 'sqlite', 'database' => ':memory:']));
    $connection->statement('create table items (id integer primary key, value text not null)');
    $connection->table('items')->insert(['id' => 1, 'value' => 'before']);
    $cache = Cache::memory('deferred-query-' . bin2hex(random_bytes(4)));
    $calls = 0;
    $connection->setQueryCacheResolver(static function () use (&$calls, $cache): CacheInterface {
        ++$calls;
        return $cache;
    });
    expect($calls)->toBe(0);
    $read = static fn(): ?array => $connection->table('items')->where('id', '=', 1)->cacheFor(60)->first();
    expect($read()['value'] ?? null)->toBe('before')->and($calls)->toBe(1)
        ->and($connection->queryCache())->toBe($cache);
    $connection->table('items')->where('id', '=', 1)->update(['value' => 'after']);
    expect($read()['value'] ?? null)->toBe('after')->and($calls)->toBe(1);
});

it('preserves explicit query cache replacement and clearing semantics', function (): void {
    $connection = new Connection(ConnectionConfig::fromArray(['driver' => 'sqlite', 'database' => ':memory:']));
    $connection->setQueryCacheResolver(static function (): CacheInterface {
        throw new RuntimeException('Replaced cache resolver must not run.');
    });
    $cache = Cache::memory('deferred-explicit-' . bin2hex(random_bytes(4)));
    $connection->setQueryCache($cache);
    expect($connection->queryCache())->toBe($cache);
    $connection->setQueryCache(null);
    expect($connection->queryCache())->not->toBe($cache);
});

it('retries a failed cache resolver and rejects recursive resolution', function (): void {
    $connection = new Connection(ConnectionConfig::fromArray(['driver' => 'sqlite', 'database' => ':memory:']));
    $cache = Cache::memory('deferred-retry-' . bin2hex(random_bytes(4)));
    $calls = 0;
    $failure = new RuntimeException('cache unavailable');
    $connection->setQueryCacheResolver(static function () use (&$calls, $cache, $failure): CacheInterface {
        if (++$calls === 1) {
            throw $failure;
        }
        return $cache;
    });
    expect(fn() => $connection->queryCache())->toThrow($failure)
        ->and($connection->queryCache())->toBe($cache)
        ->and($calls)->toBe(2);
    $connection->setQueryCacheResolver(static fn(): CacheInterface => $connection->queryCache());
    expect(fn() => $connection->queryCache())->toThrow(LogicException::class, 'Recursive query cache');
});
