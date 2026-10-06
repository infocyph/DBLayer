<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\DBLayer\Connection\Pool;
use Infocyph\DBLayer\Connection\PoolManager;
use Infocyph\DBLayer\Exceptions\ConnectionException;
use Infocyph\DBLayer\Security\SecurityValidator;

function dblayerBatchBConnection(array $overrides = []): Connection
{
    return new Connection(ConnectionConfig::fromArray(array_replace_recursive([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ], $overrides)), 'batch-b');
}

function dblayerBatchBPopulatedConnection(array $overrides = []): Connection
{
    $connection = dblayerBatchBConnection($overrides);
    $connection->statement('create table items (id integer primary key, value text)');
    $connection->insert('insert into items (id, value) values (?, ?)', [1, 'one']);
    $connection->insert('insert into items (id, value) values (?, ?)', [2, 'two']);
    $connection->resetRuntimeStateForReuse();

    return $connection;
}

it('enforces cancellation and deadlines on warm cache hits and composes nested cancellation', function (): void {
    $connection = dblayerBatchBPopulatedConnection();
    $connection->setQueryCache(Cache::memory('dblayer-batch-b-cancellation'));
    $query = $connection->table('items')->orderBy('id')->cacheFor(60);

    expect($query->get())->toHaveCount(2);

    expect(fn() => $connection->withQueryCancellation(
        static fn(): bool => true,
        static fn(): array => $query->get(),
    ))->toThrow(ConnectionException::class);

    expect(fn() => $connection->withQueryDeadline(
        0.0,
        static fn(): array => $query->get(),
    ))->toThrow(ConnectionException::class);

    expect(fn() => $connection->withQueryCancellation(
        static fn(): bool => true,
        fn(): mixed => $connection->withQueryCancellation(
            static fn(): bool => false,
            fn(): mixed => $connection->scalar('select 1'),
        ),
    ))->toThrow(ConnectionException::class);
});

it('does not reuse a cached prepared statement while its streaming cursor is active', function (): void {
    $connection = dblayerBatchBPopulatedConnection([
        'statement_cache_enabled' => true,
        'statement_cache_size' => 8,
    ]);
    $sql = 'select id, value from items order by id';

    $stream = $connection->stream($sql);
    $stream->rewind();

    expect($stream->current()['id'] ?? null)->toBe(1)
        ->and($connection->select($sql))->toHaveCount(2);

    $stream->next();

    expect($stream->valid())->toBeTrue()
        ->and($stream->current()['id'] ?? null)->toBe(2);
});

it('rejects lazy results escaping PoolManager using scope and releases the lease', function (): void {
    $pool = new Pool([
        'min_connections' => 0,
        'max_connections' => 1,
    ]);
    $pool->addConfig('main', ConnectionConfig::fromArray([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]));
    $manager = new PoolManager($pool);

    expect(fn() => $manager->using(
        'main',
        static fn(Connection $connection): Generator => $connection->stream('select 1 as value'),
    ))->toThrow(ConnectionException::class, 'Lazy results cannot escape');

    $lease = $manager->checkout('main');

    expect($lease->connection()->scalar('select 1'))->toBe(1);

    $lease->release();
});

it('discards a pooled wrapper released with a live stream and fences the stale iterator', function (): void {
    $pool = new Pool([
        'min_connections' => 0,
        'max_connections' => 1,
    ]);
    $pool->addConfig('main', ConnectionConfig::fromArray([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]));
    $manager = new PoolManager($pool);
    $lease = $manager->checkout('main');
    $connection = $lease->connection();
    $connection->statement('create table items (id integer primary key, value text)');
    $connection->insert('insert into items (id, value) values (?, ?)', [1, 'one']);
    $connection->insert('insert into items (id, value) values (?, ?)', [2, 'two']);
    $stream = $connection->stream('select id, value from items order by id');
    $stream->rewind();

    expect($stream->current()['id'] ?? null)->toBe(1);

    $originalId = spl_object_id($connection);
    $lease->release();

    expect($pool->getStats()['idle_connections'])->toBe(0)
        ->and($pool->getStats()['total_connections'])->toBe(0);

    $next = $manager->checkout('main');

    expect(spl_object_id($next->connection()))->not->toBe($originalId)
        ->and($next->connection()->scalar('select 42'))->toBe(42);

    $stream->next();

    expect($stream->valid())->toBeFalse();

    $next->release();
});

it('restores native SQLite timeout state before pooled reuse', function (): void {
    $connection = dblayerBatchBConnection();
    $connection->setQueryTimeoutMs(123);

    $before = $connection->getPdo()->query('pragma busy_timeout');
    expect($before)->not->toBeFalse();
    $beforeValue = $before === false ? -1 : (int) $before->fetchColumn();

    expect($beforeValue)->toBe(123)
        ->and($connection->resetRuntimeStateForReuse())->toBeTrue()
        ->and($connection->getQueryTimeoutMs())->toBeNull();

    $after = $connection->getPdo()->query('pragma busy_timeout');
    expect($after)->not->toBeFalse()
        ->and($after === false ? -1 : (int) $after->fetchColumn())->toBe(0);
});

it('escapes LIKE wildcard and escape characters exactly once', function (): void {
    $connection = dblayerBatchBConnection();
    $connection->statement('create table patterns (value text not null)');

    foreach (['a%b', 'a_b', 'a\\b'] as $value) {
        $connection->insert('insert into patterns (value) values (?)', [$value]);
    }

    foreach (['a%b', 'a_b', 'a\\b'] as $value) {
        $escaped = SecurityValidator::sanitizeLikePattern($value);
        $count = $connection->scalar(
            "select count(*) from patterns where value like ? escape '\\'",
            [$escaped],
        );

        expect((int) $count)->toBe(1);
    }

    expect(SecurityValidator::sanitizeLikePattern('a%b'))->toBe('a\\%b')
        ->and(SecurityValidator::sanitizeLikePattern('a_b'))->toBe('a\\_b')
        ->and(SecurityValidator::sanitizeLikePattern('a\\b'))->toBe('a\\\\b');
});

it('drops only DBLayer-owned private result caches during runtime reset', function (): void {
    $connection = dblayerBatchBPopulatedConnection();
    $private = $connection->queryCache();

    expect($connection->resetRuntimeStateForReuse())->toBeTrue()
        ->and($connection->queryCache())->not->toBe($private);

    $shared = Cache::memory('dblayer-batch-b-shared');
    $connection->setQueryCache($shared);

    expect($connection->resetRuntimeStateForReuse())->toBeTrue()
        ->and($connection->queryCache())->toBe($shared);
});
