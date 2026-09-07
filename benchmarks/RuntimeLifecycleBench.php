<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Benchmarks;

require_once __DIR__ . '/RequiresSqlite.php';

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\DBLayer\Connection\Pool;
use Infocyph\DBLayer\Connection\PoolManager;
use PhpBench\Attributes as Bench;

#[Bench\BeforeMethods(['setUpBeforeSubject'])]
#[Bench\Iterations(8)]
#[Bench\Revs(100)]
#[Bench\Warmup(2)]
#[Bench\OutputTimeUnit('microseconds', 2)]
#[RequiresSqlite]
final class RuntimeLifecycleBench
{
    private static ?Connection $cachedConnection = null;

    private static int $cacheMissSequence = 0;

    private static ?ConnectionConfig $config = null;

    private static ?Connection $dedicatedConnection = null;

    private static bool $initialized = false;

    private static ?PoolManager $poolManager = null;

    public function benchConstructConnection(): void
    {
        new Connection(self::config(), 'construct');
    }

    public function benchInstanceQueryCacheHit(): void
    {
        self::cachedConnection()
            ->table('runtime_items')
            ->where('id', '=', 1)
            ->cacheFor(60)
            ->cacheKey('runtime.hit')
            ->get();
    }

    public function benchInstanceQueryCacheMiss(): void
    {
        self::cachedConnection()
            ->table('runtime_items')
            ->where('id', '=', 1)
            ->cacheFor(60)
            ->cacheKey('runtime.miss.' . ++self::$cacheMissSequence)
            ->get();
    }

    public function benchOpenUseDisconnect(): void
    {
        $connection = new Connection(self::config(), 'open-use-disconnect');
        $connection->scalar('select 1');
        $connection->disconnect();
    }

    public function benchPoolCheckoutRelease(): void
    {
        $lease = self::poolManager()->checkout();
        $lease->release();
    }

    public function benchPoolCheckoutSelectRelease(): void
    {
        $lease = self::poolManager()->checkout();
        $lease->connection()->scalar('select 1');
        $lease->release();
    }

    public function benchPoolPreparedStatementReuse(): void
    {
        $lease = self::poolManager()->checkout();
        $lease->connection()->scalar('select ? as value', [42]);
        $lease->release();
    }

    public function benchPoolUsingSelect(): void
    {
        self::poolManager()->using(
            'default',
            static fn(Connection $connection): mixed => $connection->scalar('select 1'),
        );
    }

    public function benchWarmDedicatedSelect(): void
    {
        self::dedicatedConnection()->scalar('select 1');
    }

    public function benchWarmPreparedStatementReuse(): void
    {
        self::dedicatedConnection()->scalar('select ? as value', [42]);
    }

    public function setUpBeforeSubject(): void
    {
        if (self::$initialized) {
            return;
        }

        self::$config = ConnectionConfig::fromArray([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'statement_cache_enabled' => true,
            'statement_cache_size' => 64,
        ]);
        self::$dedicatedConnection = new Connection(self::config(), 'runtime-dedicated');
        self::$dedicatedConnection->scalar('select ? as value', [42]);

        $pool = new Pool([
            'min_connections' => 1,
            'max_connections' => 4,
        ]);
        $pool->addConfig('default', self::config());
        self::$poolManager = new PoolManager($pool);
        $warmLease = self::$poolManager->checkout();
        $warmLease->connection()->scalar('select ? as value', [42]);
        $warmLease->release();

        self::$cachedConnection = new Connection(self::config(), 'runtime-cache');
        self::$cachedConnection->setQueryCache(Cache::memory('dblayer-runtime-benchmark'));
        self::$cachedConnection->statement(
            'create table runtime_items (id integer primary key, value text not null)',
        );
        self::$cachedConnection->table('runtime_items')->insert([
            'id' => 1,
            'value' => 'one',
        ]);
        self::$cachedConnection
            ->table('runtime_items')
            ->where('id', '=', 1)
            ->cacheFor(60)
            ->cacheKey('runtime.hit')
            ->get();

        self::$initialized = true;
    }

    private static function cachedConnection(): Connection
    {
        return self::$cachedConnection ?? throw new \LogicException('Benchmark cache connection is not initialized.');
    }

    private static function config(): ConnectionConfig
    {
        return self::$config ?? throw new \LogicException('Benchmark configuration is not initialized.');
    }

    private static function dedicatedConnection(): Connection
    {
        return self::$dedicatedConnection ?? throw new \LogicException('Benchmark connection is not initialized.');
    }

    private static function poolManager(): PoolManager
    {
        return self::$poolManager ?? throw new \LogicException('Benchmark pool manager is not initialized.');
    }
}
