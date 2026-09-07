<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
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
        @unlink($database);
    }
});
