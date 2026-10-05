<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration as CacheRunwireIntegration;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\DBLayer\Connection\Pool;
use Infocyph\DBLayer\Connection\PoolManager;
use Infocyph\DBLayer\Exceptions\ConnectionException;
use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\Runtime\RequestExecutionPolicy;
use Infocyph\Runwire\RuntimeCapabilities;
use Infocyph\Runwire\RuntimeContext;

afterEach(function (): void {
    CacheRunwireIntegration::release();
});

function dblayerRunwireConnection(): Connection
{
    $connection = new Connection(ConnectionConfig::fromArray([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]), 'runwire-test');
    $connection->statement('create table items (id integer primary key, value text not null)');
    $connection->insert('insert into items (id, value) values (?, ?)', [1, 'one']);
    $connection->insert('insert into items (id, value) values (?, ?)', [2, 'two']);
    $connection->insert('insert into items (id, value) values (?, ?)', [3, 'three']);
    $connection->resetRuntimeStateForReuse();

    return $connection;
}

it('borrows and restores exact host Runwire context without globally binding it', function (): void {
    $connection = dblayerRunwireConnection();
    $runtime = RuntimeContext::standalone();
    $request = RequestContext::create($runtime);

    expect(CacheRunwireIntegration::runtime())->toBeNull();

    $value = $connection->withRunwire(
        $runtime,
        function () use ($connection, $runtime, $request): mixed {
            $binding = $connection->runwireBinding();

            expect($binding['runtime'] ?? null)->toBe($runtime)
                ->and($binding['request'] ?? null)->toBe($request)
                ->and($binding['scope'] ?? null)->toBeNull();

            return $connection->scalar('select 42');
        },
        $request,
    );

    expect($value)->toBe(42)
        ->and($connection->runwireBinding())->toBeNull()
        ->and(CacheRunwireIntegration::runtime())->toBeNull();
});

it('borrows a matching CacheLayer Runwire binding without taking ownership', function (): void {
    $connection = dblayerRunwireConnection();
    $runtime = RuntimeContext::standalone();
    $request = RequestContext::create($runtime);
    CacheRunwireIntegration::bind($runtime);

    $connection->withRunwire(
        $runtime,
        static function () use ($runtime, $request): void {
            $context = CacheRunwireIntegration::current();

            expect($context?->runtime)->toBe($runtime)
                ->and($context?->request)->toBe($request);
        },
        $request,
    );

    expect(CacheRunwireIntegration::runtime())->toBe($runtime)
        ->and(CacheRunwireIntegration::current())->toBeNull();
});

it('rejects mismatched completed cancelled expired and post-fork Runwire contexts', function (): void {
    $connection = dblayerRunwireConnection();
    $runtime = RuntimeContext::standalone();

    $otherRuntime = RuntimeContext::standalone();
    $otherRequest = RequestContext::create($otherRuntime);
    expect(fn(): mixed => $connection->withRunwire(
        $runtime,
        fn(): mixed => $connection->scalar('select 1'),
        $otherRequest,
    ))->toThrow(ConnectionException::class, 'different runtime');

    $completed = RequestContext::create($runtime);
    $completed->complete();
    expect(fn(): mixed => $connection->withRunwire(
        $runtime,
        fn(): mixed => $connection->scalar('select 1'),
        $completed,
    ))->toThrow(ConnectionException::class, 'Completed Runwire request');

    $cancelled = RequestContext::create($runtime);
    $cancelled->cancel(CancellationReason::HOST_CANCELLED);
    expect(fn(): mixed => $connection->withRunwire(
        $runtime,
        fn(): mixed => $connection->scalar('select 1'),
        $cancelled,
    ))->toThrow(ConnectionException::class);

    $now = hrtime(true);
    $expired = RequestContext::create(
        $runtime,
        new RequestExecutionPolicy(maxExecutionSeconds: 0.001),
        startNanoseconds: (is_int($now) ? $now : (int) $now) - 10_000_000,
    );
    expect(fn(): mixed => $connection->withRunwire(
        $runtime,
        fn(): mixed => $connection->scalar('select 1'),
        $expired,
    ))->toThrow(ConnectionException::class);

    $foreignPid = RuntimeContext::fromCapabilities(
        $runtime->capabilities,
        'foreign-pid',
        pid: $runtime->pid + 1,
        concurrent: false,
    );
    expect(fn(): mixed => $connection->withRunwire(
        $foreignPid,
        fn(): mixed => $connection->scalar('select 1'),
    ))->toThrow(ConnectionException::class, 'PID');
});

it('retains exact Runwire binding across lazy ArrayKit and database batch lifetimes', function (): void {
    $connection = dblayerRunwireConnection();
    $runtime = RuntimeContext::standalone();
    $request = RequestContext::create($runtime);

    $lazy = $connection->withRunwire(
        $runtime,
        fn() => $connection->table('items')->orderBy('id')->lazyCollection(chunkSize: 1),
        $request,
    );

    expect($connection->runwireBinding())->toBeNull()
        ->and(array_column($lazy->all(), 'value'))->toBe(['one', 'two', 'three']);

    $unfinished = $connection->withRunwire(
        $runtime,
        fn() => $connection->table('items')->orderBy('id')->lazyCollection(chunkSize: 1),
        $request,
    );
    $request->complete();

    expect(fn(): array => $unfinished->all())
        ->toThrow(LogicException::class, 'Completed Runwire request');
});

it('uses a host coroutine scope for cooperative retry sleeps when capability is active', function (): void {
    $connection = dblayerRunwireConnection();
    $runtime = RuntimeContext::fromCapabilities(
        new RuntimeCapabilities(RuntimeDriver::NATIVE, supportsRunwireCoroutines: true),
        'db-coroutine',
    );

    $completed = false;
    new CoroutineRuntime()->run(function (CoroutineScope $scope) use ($connection, $runtime, &$completed): void {
        $connection->withRunwire(
            $runtime,
            function () use ($connection, &$completed): void {
                $connection->cooperativeSleep(0.001);
                $completed = true;
            },
            scope: $scope,
        );
    });

    expect($completed)->toBeTrue();
});

it('rejects cancelled Runwire work before opening a PDO handle', function (): void {
    $connection = new Connection(ConnectionConfig::fromArray([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]), 'runwire-preconnect');
    $runtime = RuntimeContext::standalone();
    $request = RequestContext::create($runtime);
    $request->cancel(CancellationReason::HOST_CANCELLED);

    expect($connection->isConnected())->toBeFalse()
        ->and(fn(): mixed => $connection->withRunwire(
            $runtime,
            fn(): mixed => $connection->scalar('select 1'),
            $request,
        ))->toThrow(ConnectionException::class)
        ->and($connection->isConnected())->toBeFalse();
});

it('keeps concurrent Runwire requests on distinct pooled connections', function (): void {
    $runtime = RuntimeContext::fromCapabilities(
        new RuntimeCapabilities(
            RuntimeDriver::NATIVE,
            persistentProcess: true,
            persistentApplication: true,
            supportsRunwireCoroutines: true,
        ),
        'worker',
        workerSlot: 0,
        generation: 1,
    );
    $firstRequest = RequestContext::create($runtime, requestId: 'tenant-a');
    $firstRequest->setAttribute('tenant', 'a');
    $secondRequest = RequestContext::create($runtime, requestId: 'tenant-b');
    $secondRequest->setAttribute('tenant', 'b');

    $pool = new Pool([
        'min_connections' => 0,
        'max_connections' => 2,
    ]);
    $pool->addConfig('main', ConnectionConfig::fromArray([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]));
    $manager = new PoolManager($pool);

    $results = new CoroutineRuntime()->run(
        function (CoroutineScope $scope) use (
            $manager,
            $runtime,
            $firstRequest,
            $secondRequest,
        ): array {
            $first = $scope->spawn(function () use ($manager, $runtime, $firstRequest, $scope): array {
                $lease = $manager->checkout('main');

                try {
                    return $lease->connection()->withRunwire(
                        $runtime,
                        function () use ($lease, $firstRequest, $scope): array {
                            $scope->yieldNow();

                            return [
                                spl_object_id($lease->connection()),
                                $firstRequest->attribute('tenant'),
                                $lease->connection()->scalar('select 1'),
                            ];
                        },
                        $firstRequest,
                        $scope,
                    );
                } finally {
                    $lease->release();
                }
            });
            $second = $scope->spawn(function () use ($manager, $runtime, $secondRequest, $scope): array {
                $lease = $manager->checkout('main');

                try {
                    return $lease->connection()->withRunwire(
                        $runtime,
                        function () use ($lease, $secondRequest, $scope): array {
                            $scope->yieldNow();

                            return [
                                spl_object_id($lease->connection()),
                                $secondRequest->attribute('tenant'),
                                $lease->connection()->scalar('select 2'),
                            ];
                        },
                        $secondRequest,
                        $scope,
                    );
                } finally {
                    $lease->release();
                }
            });

            return [$first->await(), $second->await()];
        },
    );

    expect($results[0][0])->not->toBe($results[1][0])
        ->and($results[0][1])->toBe('a')
        ->and($results[1][1])->toBe('b')
        ->and($results[0][2])->toBe(1)
        ->and($results[1][2])->toBe(2)
        ->and($pool->getStats()['active_connections'])->toBe(0);

    $firstRequest->complete();
    $secondRequest->complete();
});

it('replaces worker-local pooled PDO handles across host generations', function (): void {
    $config = ConnectionConfig::fromArray([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]);

    $firstPool = new Pool([
        'min_connections' => 1,
        'max_connections' => 1,
    ]);
    $firstPool->addConfig('main', $config);
    $firstManager = new PoolManager($firstPool);
    $firstManager->warmUp('main');
    $firstLease = $firstManager->checkout('main');
    $firstPdo = $firstLease->connection()->getPdo();
    $firstLease->release();
    $firstPool->closeAll();

    $secondPool = new Pool([
        'min_connections' => 1,
        'max_connections' => 1,
    ]);
    $secondPool->addConfig('main', $config);
    $secondManager = new PoolManager($secondPool);
    $secondManager->warmUp('main');
    $secondLease = $secondManager->checkout('main');
    $secondPdo = $secondLease->connection()->getPdo();

    expect($secondPdo)->not->toBe($firstPdo);

    $secondLease->release();
});
