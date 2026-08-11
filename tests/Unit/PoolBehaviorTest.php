<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\DBLayer\Connection\Pool;
use Infocyph\DBLayer\Exceptions\ConnectionException;

function dblayerPoolWithSqliteConfig(array $poolConfig = []): Pool
{
    $pool = new Pool($poolConfig);
    $pool->addConfig('default', ConnectionConfig::fromArray([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]));

    return $pool;
}

it('throws when the pool is exhausted', function (): void {
    $pool = dblayerPoolWithSqliteConfig(['max_connections' => 1]);
    $connection = $pool->getConnection('default');

    expect($connection)->not->toBeNull();
    expect(static fn() => $pool->getConnection('default'))
        ->toThrow(ConnectionException::class);
});

it('reuses released healthy connections', function (): void {
    $pool = dblayerPoolWithSqliteConfig(['max_connections' => 2]);
    $first = $pool->getConnection('default');
    $firstId = spl_object_id($first);
    $pool->releaseConnection('default', $first);

    $reused = $pool->getConnection('default');

    expect(spl_object_id($reused))->toBe($firstId);
    expect((int) ($pool->getStats()['reused'] ?? 0))->toBeGreaterThan(0);
});

it('rejects invalid health-check thresholds at configuration time', function (): void {
    $pool = dblayerPoolWithSqliteConfig(['max_connections' => 2]);
    $first = $pool->getConnection('default');

    expect(fn() => new \Infocyph\DBLayer\Connection\HealthCheck($first, [
        'max_latency_ms' => -1,
    ]))->toThrow(InvalidArgumentException::class);
});

it('treats a zero idle timeout as disabled', function (): void {
    $pool = dblayerPoolWithSqliteConfig([
        'max_connections' => 2,
        'idle_timeout' => 0,
    ]);
    $first = $pool->getConnection('default');
    $firstId = spl_object_id($first);
    $pool->releaseConnection('default', $first);

    $next = $pool->getConnection('default');

    expect(spl_object_id($next))->toBe($firstId);
    expect((int) ($pool->getStats()['closed'] ?? 0))->toBe(0);
});

it('treats a zero max lifetime as disabled', function (): void {
    $pool = dblayerPoolWithSqliteConfig([
        'max_connections' => 2,
        'max_lifetime' => 0,
    ]);
    $first = $pool->getConnection('default');
    $firstId = spl_object_id($first);

    $reflection = new ReflectionClass($pool);
    $connections = $reflection->getProperty('connections');
    /** @var array<string,array<int,array{connection:\Infocyph\DBLayer\Connection\Connection,created_at:float}>> $known */
    $known = $connections->getValue($pool);
    $known['default'][$firstId]['created_at'] = microtime(true) - 10;
    $connections->setValue($pool, $known);

    $pool->releaseConnection('default', $first);
    $replacement = $pool->getConnection('default');

    expect(spl_object_id($replacement))->toBe($firstId);
    expect((int) ($pool->getStats()['closed'] ?? 0))->toBe(0);
});

it('checks only idle connections and batches them instead of scanning borrowed connections', function (): void {
    $pool = dblayerPoolWithSqliteConfig([
        'max_connections' => 7,
        'health_check_interval' => 1,
    ]);

    $borrowed = [];
    for ($index = 0; $index < 7; $index++) {
        $connection = $pool->getConnection('default');
        $connection->select('select 1');
        $borrowed[] = $connection;
    }

    $pool->healthCheck();
    $reflection = new ReflectionClass($pool);
    $healthCursor = $reflection->getProperty('healthCursor');
    expect((int) $healthCursor->getValue($pool))->toBe(0);

    foreach ($borrowed as $connection) {
        $pool->releaseConnection('default', $connection);
    }

    $lastHealthCheck = $reflection->getProperty('lastHealthCheck');
    $lastHealthCheck->setValue($pool, null);
    $pool->healthCheck();
    expect((int) $healthCursor->getValue($pool))->toBe(5);

    $lastHealthCheck->setValue($pool, null);
    $pool->healthCheck();

    expect((int) $healthCursor->getValue($pool))->toBe(3);
    expect((int) ($pool->getStats()['health_checks'] ?? 0))->toBe(3);
});

it('discards a pooled connection released with an active transaction', function (): void {
    $pool = dblayerPoolWithSqliteConfig(['max_connections' => 1]);
    $connection = $pool->getConnection('default');
    $connectionId = spl_object_id($connection);
    $connection->beginTransaction();

    $pool->releaseConnection('default', $connection);
    $replacement = $pool->getConnection('default');

    expect(spl_object_id($replacement))->not->toBe($connectionId)
        ->and($connection->isConnected())->toBeFalse()
        ->and($pool->getStats()['active_connections'])->toBe(1)
        ->and($pool->getStats()['idle_connections'])->toBe(0);
});

it('resets sticky deadline cancellation and query context before pool reuse', function (): void {
    $pool = new Pool(['max_connections' => 1]);
    $pool->addConfig('default', ConnectionConfig::fromArray([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'sticky' => true,
    ]));
    $connection = $pool->getConnection('default');
    $connection->statement('create table runtime_state (id integer primary key)');
    $connection->table('runtime_state')->insert(['id' => 1]);
    $connection->setQueryDeadlineAt(microtime(true) + 60);
    $connection->setQueryTimeoutMs(500);
    $connection->setQueryCommentContext(['request' => 'first']);

    $reflection = new ReflectionClass($connection);
    $cancellation = $reflection->getProperty('queryCancellationChecker');
    $cancellation->setValue($connection, static fn(): bool => true);

    expect($connection->hasStickyWrite())->toBeTrue();
    $pool->releaseConnection('default', $connection);
    $reused = $pool->getConnection('default');

    expect($reused)->toBe($connection)
        ->and($reused->hasStickyWrite())->toBeFalse()
        ->and($reflection->getProperty('queryDeadlineAt')->getValue($reused))->toBeNull()
        ->and($reflection->getProperty('queryTimeoutMs')->getValue($reused))->toBeNull()
        ->and($cancellation->getValue($reused))->toBeNull()
        ->and($reused->select('select 1 as ok'))->toBe([['ok' => 1]]);
});

it('rejects connections that were not created by the pool', function (): void {
    $pool = dblayerPoolWithSqliteConfig(['max_connections' => 1]);
    $foreign = new \Infocyph\DBLayer\Connection\Connection(
        ConnectionConfig::fromArray(['driver' => 'sqlite', 'database' => ':memory:']),
        'foreign',
    );

    expect(fn() => $pool->releaseConnection('default', $foreign))
        ->toThrow(ConnectionException::class, 'foreign');
});
