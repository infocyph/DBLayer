<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration as CacheRunwireIntegration;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\DBLayer\Exceptions\ConnectionException;
use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\Runtime\RequestExecutionPolicy;
use Infocyph\Runwire\RuntimeCapabilities;
use Infocyph\Runwire\RuntimeContext;

afterEach(static function (): void {
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
