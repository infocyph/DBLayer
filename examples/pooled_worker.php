<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Examples;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\Pool;
use Infocyph\DBLayer\Connection\PoolManager;
use Infocyph\DBLayer\DB;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\RuntimeContext;

/**
 * A framework or intermediate library can pass its already active Runwire
 * objects. A null runtime uses ordinary synchronous PDO. The host owns the
 * worker, event loop, request/task completion, and pool drain.
 */
function runPooledTask(
    PoolManager $manager,
    ?RuntimeContext $runtime = null,
    ?RequestContext $request = null,
    ?CoroutineScope $scope = null,
): int {
    return $manager->using('worker', static function (Connection $connection) use ($runtime, $request, $scope): int {
        $query = static fn(): int => (int) $connection->scalar('select 1');

        return $runtime === null
            ? $query()
            : $connection->withRunwire($runtime, $query, $request, $scope);
    });
}

/** @var array{min_connections:int,max_connections:int,idle_timeout:int,max_lifetime:int,health_check_interval:int} $poolConfig */
$poolConfig = require __DIR__ . '/bootstrap.php';
$connectionName = $argv[1] ?? 'sqlite_local';
$config = DB::connection($connectionName)->getConfig();

// Build this once per worker generation, after fork. Choose any bootstrap profile.
$pool = new Pool($poolConfig);
$pool->addConfig('worker', $config);
$manager = new PoolManager($pool);

try {
    $ready = $manager->warmUp('worker', target: $poolConfig['min_connections']);
    $results = [];

    // CLI simulation of three separate requests/tasks; this starts no runtime.
    for ($task = 0; $task < 3; $task++) {
        $results[] = runPooledTask($manager);
    }

    $stats = $pool->getStats();
} finally {
    // A real host closes the pool during drain, after active leases finish.
    $pool->closeAll();
}

fwrite(STDOUT, json_encode([
    'connection' => $connectionName,
    'ready' => $ready,
    'results' => $results,
    'active_after_tasks' => $stats['active_connections'],
    'idle_after_tasks' => $stats['idle_connections'],
    'connections_after_drain' => $pool->getStats()['total_connections'],
], JSON_THROW_ON_ERROR) . PHP_EOL);
