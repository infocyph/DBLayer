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

it('removes unhealthy released connections and creates replacements', function (): void {
    $pool = dblayerPoolWithSqliteConfig(['max_connections' => 2]);
    $first = $pool->getConnection('default');
    $first->attachHealthCheck(new \Infocyph\DBLayer\Connection\HealthCheck($first, [
        'max_latency_ms' => -1,
    ]));

    $pool->releaseConnection('default', $first);
    $replacement = $pool->getConnection('default');

    expect($replacement)->toBeInstanceOf(\Infocyph\DBLayer\Connection\Connection::class);
    expect((int) ($pool->getStats()['closed'] ?? 0))->toBeGreaterThan(0);
});

it('evicts stale idle connections after timeout', function (): void {
    $pool = dblayerPoolWithSqliteConfig([
        'max_connections' => 2,
        'idle_timeout' => 0,
    ]);
    $first = $pool->getConnection('default');
    $firstId = spl_object_id($first);
    $pool->releaseConnection('default', $first);

    $next = $pool->getConnection('default');

    expect(spl_object_id($next))->not->toBe($firstId);
    expect((int) ($pool->getStats()['closed'] ?? 0))->toBeGreaterThan(0);
});

it('rotates connections that exceed max lifetime on release', function (): void {
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

    expect(spl_object_id($replacement))->not->toBe($firstId);
    expect((int) ($pool->getStats()['closed'] ?? 0))->toBeGreaterThan(0);
});

it('runs health checks in batches instead of full scans each run', function (): void {
    $pool = dblayerPoolWithSqliteConfig([
        'max_connections' => 7,
        'health_check_interval' => 0,
    ]);

    for ($index = 0; $index < 7; $index++) {
        $pool->getConnection('default');
    }

    $pool->healthCheck();
    $reflection = new ReflectionClass($pool);
    $healthCursor = $reflection->getProperty('healthCursor');
    expect((int) $healthCursor->getValue($pool))->toBe(5);

    $pool->healthCheck();

    expect((int) $healthCursor->getValue($pool))->toBe(3);
    expect((int) ($pool->getStats()['health_checks'] ?? 0))->toBe(2);
});
