<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\DBLayer\Connection\Pool;
use Infocyph\DBLayer\Connection\PoolManager;

function dblayerRuntimeIsolationPoolManager(int $maxConnections = 2, array $overrides = []): PoolManager
{
    $pool = new Pool([
        'min_connections' => 0,
        'max_connections' => $maxConnections,
    ]);
    $pool->addConfig('default', ConnectionConfig::fromArray(array_replace_recursive([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ], $overrides)));

    return new PoolManager($pool);
}

it('keeps interleaved fiber checkouts on distinct active connections', function (): void {
    $manager = dblayerRuntimeIsolationPoolManager(2);
    $ids = [];

    $first = new Fiber(static function () use ($manager, &$ids): void {
        $lease = $manager->checkout();
        $ids['first'] = spl_object_id($lease->connection());
        Fiber::suspend();
        $lease->release();
    });
    $second = new Fiber(static function () use ($manager, &$ids): void {
        $lease = $manager->checkout();
        $ids['second'] = spl_object_id($lease->connection());
        Fiber::suspend();
        $lease->release();
    });

    $first->start();
    $second->start();

    expect($ids['first'])->not->toBe($ids['second'])
        ->and($manager->getPool()->getStats()['active_connections'])->toBe(2);

    $first->resume();

    expect($manager->getPool()->getStats()['active_connections'])->toBe(1);

    $second->resume();

    expect($manager->getPool()->getStats()['active_connections'])->toBe(0)
        ->and($manager->getPool()->getStats()['idle_connections'])->toBe(2);
});

it('clears sticky routing and query deadlines before reusing a pooled wrapper', function (): void {
    $manager = dblayerRuntimeIsolationPoolManager(1, [
        'sticky' => true,
    ]);
    $first = $manager->checkout();
    $connection = $first->connection();

    $connection->statement('create table runtime_items (id integer primary key, value text not null)');
    $connection->table('runtime_items')->insert(['id' => 1, 'value' => 'one']);
    $connection->setQueryDeadlineAt(microtime(true) - 1.0);

    expect($connection->hasStickyWrite())->toBeTrue();

    $firstId = spl_object_id($connection);
    $first->release();

    $second = $manager->checkout();
    $reused = $second->connection();

    expect(spl_object_id($reused))->toBe($firstId)
        ->and($reused->hasStickyWrite())->toBeFalse()
        ->and((int) $reused->scalar('select 1'))->toBe(1);

    $second->release();
});

it('discards a pooled wrapper released with an active transaction', function (): void {
    $manager = dblayerRuntimeIsolationPoolManager(1);
    $first = $manager->checkout();
    $connection = $first->connection();
    $connection->statement('create table transaction_items (id integer primary key, value text not null)');
    $connection->beginTransaction();
    $connection->table('transaction_items')->insert(['id' => 1, 'value' => 'uncommitted']);
    $firstId = spl_object_id($connection);

    $first->release();

    expect($manager->getPool()->getStats()['active_connections'])->toBe(0)
        ->and($manager->getPool()->getStats()['idle_connections'])->toBe(0);

    $second = $manager->checkout();

    expect(spl_object_id($second->connection()))->not->toBe($firstId);

    $second->release();
});

it('warms distinct PDO handles and retains them for worker reuse', function (): void {
    $pool = new Pool([
        'min_connections' => 2,
        'max_connections' => 3,
    ]);
    $pool->addConfig('default', ConnectionConfig::fromArray([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]));
    $manager = new PoolManager($pool);

    expect($pool->getStats()['total_connections'])->toBe(2)
        ->and($manager->warmUp())->toBe(2);

    $first = $manager->checkout();
    $second = $manager->checkout();
    $firstPdo = spl_object_id($first->connection()->getPdo());
    $secondPdo = spl_object_id($second->connection()->getPdo());

    expect($firstPdo)->not->toBe($secondPdo);

    $first->release();
    $second->release();

    $reused = $manager->checkout();
    $reusedPdo = spl_object_id($reused->connection()->getPdo());

    expect([$firstPdo, $secondPdo])->toContain($reusedPdo)
        ->and($pool->getStats()['total_connections'])->toBe(2);

    $reused->release();
});

it('bounds explicit pool warmup by configured capacity', function (): void {
    $manager = dblayerRuntimeIsolationPoolManager(2);

    expect(fn(): int => $manager->warmUp(target: 3))
        ->toThrow(\Infocyph\DBLayer\Exceptions\ConnectionException::class, 'warmup target');
});
