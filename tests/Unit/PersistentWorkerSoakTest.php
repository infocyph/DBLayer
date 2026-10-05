<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\DBLayer\Connection\Pool;
use Infocyph\DBLayer\Connection\PoolManager;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\RuntimeCapabilities;
use Infocyph\Runwire\RuntimeContext;

it('keeps persistent worker reuse bounded across repeated request lifecycles', function (): void {
    $database = tempnam(sys_get_temp_dir(), 'dblayer-worker-soak-');

    expect($database)->not->toBeFalse();

    $pool = new Pool([
        'min_connections' => 2,
        'max_connections' => 4,
        'idle_timeout' => 0,
        'max_lifetime' => 0,
    ]);

    try {
        $pool->addConfig('main', ConnectionConfig::fromArray([
            'driver' => 'sqlite',
            'database' => $database,
            'statement_cache_enabled' => true,
            'statement_cache_size' => 16,
        ]));
        $manager = new PoolManager($pool);

        expect($manager->warmUp('main', 2))->toBe(2);

        $seed = $manager->checkout('main');

        try {
            $connection = $seed->connection();
            $connection->statement('create table items (id integer primary key, value text not null)');
            $connection->insert('insert into items (id, value) values (?, ?)', [1, 'one']);
            $connection->insert('insert into items (id, value) values (?, ?)', [2, 'two']);
            $connection->insert('insert into items (id, value) values (?, ?)', [3, 'three']);
        } finally {
            $seed->release();
        }

        $runtime = RuntimeContext::fromCapabilities(
            new RuntimeCapabilities(
                RuntimeDriver::NATIVE,
                persistentProcess: true,
                persistentApplication: true,
            ),
            'worker-soak',
            workerSlot: 0,
            generation: 1,
        );
        gc_collect_cycles();
        $memoryBefore = memory_get_usage();

        for ($requestNumber = 1; $requestNumber <= 300; $requestNumber++) {
            $request = RequestContext::create(
                $runtime,
                requestId: 'worker-soak-' . $requestNumber,
            );
            $lease = $manager->checkout('main');

            try {
                $connection = $lease->connection();
                $connection->queryCache();

                $rows = $connection->withRunwire(
                    $runtime,
                    fn(): array => $connection->table('items')
                        ->orderBy('id')
                        ->cacheKey('worker-soak.' . $requestNumber)
                        ->cacheFor(60)
                        ->get(),
                    $request,
                );

                if (array_column($rows, 'value') !== ['one', 'two', 'three']) {
                    throw new RuntimeException('Persistent worker soak returned unexpected rows.');
                }
            } finally {
                $request->complete();
                $lease->release();
            }
        }

        gc_collect_cycles();
        $stats = $pool->getStats();
        $memoryGrowth = memory_get_usage() - $memoryBefore;

        expect($stats['active_connections'])->toBe(0)
            ->and($stats['total_connections'])->toBe(2)
            ->and($stats['idle_connections'])->toBe(2)
            ->and($memoryGrowth)->toBeLessThan(8 * 1024 * 1024);
    } finally {
        $pool->closeAll();

        if (is_string($database) && is_file($database)) {
            unlink($database);
        }
    }
});
