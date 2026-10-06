<?php

declare(strict_types=1);

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
use Infocyph\Runwire\RuntimeCapabilities;
use Infocyph\Runwire\RuntimeContext;

final class ReleaseRunwireSoak
{
    private int $hostSamples = 0;

    private int $initialOpenSockets = 0;

    private int $maxOpenSockets = 0;

    private int $maxProcessTreeRssBytes = 0;

    private int $maxTaskQueueDepth = 0;

    public function __construct(
        private readonly string $project,
        private readonly string $output,
        private readonly int $requests,
        private readonly int $concurrency,
    ) {}

    public function run(): void
    {
        $autoload = $this->project . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            throw new RuntimeException("Missing target autoloader: {$autoload}");
        }

        require_once $autoload;

        $this->initialOpenSockets = $this->openSocketCount();
        $this->sampleHostState(0);
        $this->resetDataset();
        gc_collect_cycles();
        $memoryBefore = memory_get_usage(true);
        $perPhase = intdiv($this->requests, 2);
        $first = $this->runPhase(1, $perPhase, 0);
        $second = $this->runPhase(2, $this->requests - $perPhase, $perPhase);
        $deploymentOverlap = $this->verifyDeploymentOverlap();
        $this->sampleHostState(0);
        gc_collect_cycles();
        $memoryAfter = memory_get_usage(true);
        $memoryGrowth = max(0, $memoryAfter - $memoryBefore);
        $unexpectedErrors = $first['unexpected_errors'] + $second['unexpected_errors'];
        $activeLeaks = $first['active_connections_after'] + $second['active_connections_after'];
        $maxTotal = max($first['total_connections_after'], $second['total_connections_after']);
        $finalOpenSockets = $this->openSocketCount();
        $socketGrowth = max(0, $finalOpenSockets - $this->initialOpenSockets);
        $passed = $unexpectedErrors === 0
            && $activeLeaks === 0
            && $maxTotal <= $this->concurrency
            && $memoryGrowth <= 32 * 1024 * 1024
            && $socketGrowth <= 1
            && $this->maxTaskQueueDepth <= $this->concurrency
            && $deploymentOverlap;

        $payload = [
            'requests' => $this->requests,
            'concurrency' => $this->concurrency,
            'successful_requests' => $first['successful_requests'] + $second['successful_requests'],
            'expected_cancellations' => $first['expected_cancellations'] + $second['expected_cancellations'],
            'iterator_fences' => $first['iterator_fences'] + $second['iterator_fences'],
            'unexpected_errors' => $unexpectedErrors,
            'generation_replacements' => 1,
            'deployment_overlap_verified' => $deploymentOverlap,
            'tenant_switching_verified' => true,
            'host_samples' => $this->hostSamples,
            'max_process_tree_rss_bytes' => $this->maxProcessTreeRssBytes,
            'initial_open_sockets' => $this->initialOpenSockets,
            'max_open_sockets' => $this->maxOpenSockets,
            'final_open_sockets' => $finalOpenSockets,
            'socket_growth' => $socketGrowth,
            'max_task_queue_depth' => $this->maxTaskQueueDepth,
            'memory_before_bytes' => $memoryBefore,
            'memory_after_bytes' => $memoryAfter,
            'memory_growth_bytes' => $memoryGrowth,
            'peak_rss_bytes' => memory_get_peak_usage(true),
            'max_total_connections_after_phase' => $maxTotal,
            'active_connections_after_phases' => $activeLeaks,
            'passed' => $passed,
            'phases' => [$first, $second],
        ];

        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($this->output, $encoded . PHP_EOL) === false) {
            throw new RuntimeException("Unable to write Runwire soak result: {$this->output}");
        }

        fwrite(STDOUT, $encoded . PHP_EOL);

        if (!$passed) {
            throw new RuntimeException('Sustained Runwire worker soak failed its acceptance checks.');
        }
    }

    private function config(): ConnectionConfig
    {
        return ConnectionConfig::fromArray([
            'driver' => 'pgsql',
            'host' => getenv('DBLAYER_PERF_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('DBLAYER_PERF_PORT') ?: 5432),
            'database' => getenv('DBLAYER_PERF_DATABASE') ?: 'dblayer_perf',
            'username' => getenv('DBLAYER_PERF_USER') ?: 'dblayer',
            'password' => getenv('DBLAYER_PERF_PASSWORD') ?: 'dblayer',
            'statement_cache_enabled' => true,
            'statement_cache_size' => 32,
        ]);
    }

    /**
     * @return array{
     *   generation:int,
     *   successful_requests:int,
     *   expected_cancellations:int,
     *   iterator_fences:int,
     *   unexpected_errors:int,
     *   active_connections_after:int,
     *   idle_connections_after:int,
     *   total_connections_after:int
     * }
     */
    private function runPhase(int $generation, int $requestCount, int $offset): array
    {
        $pool = new Pool([
            'min_connections' => $this->concurrency,
            'max_connections' => $this->concurrency,
            'idle_timeout' => 0,
            'max_lifetime' => 0,
        ]);
        $pool->addConfig('main', $this->config());
        $manager = new PoolManager($pool);
        $manager->warmUp('main', $this->concurrency);
        $runtime = RuntimeContext::fromCapabilities(
            new RuntimeCapabilities(
                RuntimeDriver::NATIVE,
                persistentProcess: true,
                persistentApplication: true,
                supportsRunwireCoroutines: true,
            ),
            'release-runwire-soak',
            workerSlot: 0,
            generation: $generation,
        );

        $successful = 0;
        $expectedCancellations = 0;
        $iteratorFences = 0;
        $unexpectedErrors = 0;

        try {
            new CoroutineRuntime()->run(
                function (CoroutineScope $scope) use (
                    $manager,
                    $runtime,
                    $requestCount,
                    $offset,
                    &$successful,
                    &$expectedCancellations,
                    &$unexpectedErrors,
                ): void {
                    for ($base = 0; $base < $requestCount; $base += $this->concurrency) {
                        $tasks = [];
                        $batchSize = min($this->concurrency, $requestCount - $base);

                        for ($slot = 0; $slot < $batchSize; $slot++) {
                            $sequence = $offset + $base + $slot + 1;
                            $tasks[] = $scope->spawn(
                                function () use ($manager, $runtime, $scope, $sequence): string {
                                    return $this->runRequest($manager, $runtime, $scope, $sequence);
                                },
                            );
                        }

                        $queued = count($tasks);
                        $this->sampleHostState($queued);

                        foreach ($tasks as $task) {
                            try {
                                $result = $task->await();
                                if ($result === 'cancelled') {
                                    $expectedCancellations++;

                                    continue;
                                }

                                if ($result === 'ok') {
                                    $successful++;

                                    continue;
                                }

                                $unexpectedErrors++;
                            } catch (Throwable) {
                                $unexpectedErrors++;
                            } finally {
                                $queued = max(0, $queued - 1);
                                $this->sampleHostState($queued);
                            }
                        }
                    }
                },
            );

            for ($sequence = $offset + 200; $sequence <= $offset + $requestCount; $sequence += 200) {
                if ($this->verifyIteratorFence($manager, $runtime, $sequence)) {
                    $iteratorFences++;
                } else {
                    $unexpectedErrors++;
                }
            }

            $manager->warmUp('main', $this->concurrency);
            $stats = $pool->getStats();

            return [
                'generation' => $generation,
                'successful_requests' => $successful,
                'expected_cancellations' => $expectedCancellations,
                'iterator_fences' => $iteratorFences,
                'unexpected_errors' => $unexpectedErrors,
                'active_connections_after' => (int) $stats['active_connections'],
                'idle_connections_after' => (int) $stats['idle_connections'],
                'total_connections_after' => (int) $stats['total_connections'],
            ];
        } finally {
            $pool->closeAll();
        }
    }

    private function runRequest(
        PoolManager $manager,
        RuntimeContext $runtime,
        CoroutineScope $scope,
        int $sequence,
    ): string {
        $request = RequestContext::create(
            $runtime,
            requestId: 'release-soak-' . $runtime->generation . '-' . $sequence,
        );
        $cancelled = $sequence % 97 === 0;
        if ($cancelled) {
            $request->cancel(CancellationReason::HOST_CANCELLED);
        }

        $lease = $manager->checkout('main');

        try {
            if ($cancelled) {
                try {
                    $lease->connection()->withRunwire(
                        $runtime,
                        fn(): int => (int) $lease->connection()->scalar('select 1'),
                        $request,
                        $scope,
                    );
                } catch (ConnectionException) {
                    return 'cancelled';
                }

                return 'unexpected-cancellation-success';
            }

            $result = $lease->connection()->withRunwire(
                $runtime,
                function () use ($lease, $scope, $sequence): int {
                    $scope->yieldNow();
                    $id = ($sequence % 256) + 1;
                    $tenantId = ($id % 8) + 1;
                    $row = $lease->connection()
                        ->table('release_soak_items')
                        ->where('id', '=', $id)
                        ->where('tenant_id', '=', $tenantId)
                        ->cacheFor(10)
                        ->cacheKey('release.soak.' . $tenantId . '.' . $sequence)
                        ->first();

                    if (($row['id'] ?? null) !== $id || ($row['tenant_id'] ?? null) !== $tenantId) {
                        throw new RuntimeException('Runwire soak tenant row mismatch.');
                    }

                    if ($sequence % 113 === 0) {
                        $affected = $lease->connection()
                            ->table('release_soak_items')
                            ->where('id', '=', $id)
                            ->where('tenant_id', '=', $tenantId)
                            ->update(['value' => 'row-' . $id . '-request-' . $sequence]);

                        if ($affected !== 1) {
                            throw new RuntimeException('Runwire soak tenant write mismatch.');
                        }
                    }

                    return (int) $lease->connection()->scalar(
                        'select count(*) from release_soak_items where id between ? and ?',
                        [1, 256],
                    );
                },
                $request,
                $scope,
            );

            return $result === 256 ? 'ok' : 'unexpected-count';
        } finally {
            if (!$request->completed() && !$request->cancelled()) {
                $request->complete();
            }

            $lease->release();
        }
    }

    private function verifyIteratorFence(
        PoolManager $manager,
        RuntimeContext $runtime,
        int $sequence,
    ): bool {
        $request = RequestContext::create(
            $runtime,
            requestId: 'release-fence-' . $runtime->generation . '-' . $sequence,
        );
        $lease = $manager->checkout('main');
        $stream = $lease->connection()->withRunwire(
            $runtime,
            fn() => $lease->connection()->stream(
                'select id from release_soak_items order by id limit 4',
            ),
            $request,
        );
        $stream->rewind();
        $lease->release();

        try {
            $stream->next();

            return false;
        } catch (ConnectionException) {
            return true;
        } finally {
            if (!$request->completed()) {
                $request->complete();
            }
        }
    }

    private function openSocketCount(): int
    {
        $directory = '/proc/self/fd';
        if (!is_dir($directory)) {
            return 0;
        }

        $entries = scandir($directory);
        if (!is_array($entries)) {
            return 0;
        }

        $count = 0;
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;
            if (!is_link($path)) {
                continue;
            }

            $target = readlink($path);
            if (is_string($target) && str_starts_with($target, 'socket:[')) {
                $count++;
            }
        }

        return $count;
    }

    private function processTreeRssBytes(): int
    {
        $statusFile = '/proc/self/status';
        if (!is_readable($statusFile)) {
            return memory_get_usage(true);
        }

        $lines = file($statusFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return memory_get_usage(true);
        }

        foreach ($lines as $line) {
            if (preg_match('/^VmRSS:\\s+(\\d+)\\s+kB$/', $line, $matches) === 1) {
                return (int) $matches[1] * 1024;
            }
        }

        return memory_get_usage(true);
    }

    private function sampleHostState(int $queueDepth): void
    {
        $this->hostSamples++;
        $this->maxTaskQueueDepth = max($this->maxTaskQueueDepth, $queueDepth);
        $this->maxOpenSockets = max($this->maxOpenSockets, $this->openSocketCount());
        $this->maxProcessTreeRssBytes = max(
            $this->maxProcessTreeRssBytes,
            $this->processTreeRssBytes(),
        );
    }

    private function verifyDeploymentOverlap(): bool
    {
        $oldPool = new Pool(['min_connections' => 1, 'max_connections' => 1]);
        $newPool = new Pool(['min_connections' => 1, 'max_connections' => 1]);
        $oldPool->addConfig('main', $this->config());
        $newPool->addConfig('main', $this->config());
        $oldManager = new PoolManager($oldPool);
        $newManager = new PoolManager($newPool);
        $oldManager->warmUp('main', 1);
        $newManager->warmUp('main', 1);

        $oldRuntime = RuntimeContext::fromCapabilities(
            new RuntimeCapabilities(RuntimeDriver::NATIVE, persistentProcess: true, persistentApplication: true),
            'release-runwire-soak',
            workerSlot: 0,
            generation: 100,
        );
        $newRuntime = RuntimeContext::fromCapabilities(
            new RuntimeCapabilities(RuntimeDriver::NATIVE, persistentProcess: true, persistentApplication: true),
            'release-runwire-soak',
            workerSlot: 0,
            generation: 101,
        );
        $oldRequest = RequestContext::create($oldRuntime, requestId: 'release-overlap-old');
        $newRequest = RequestContext::create($newRuntime, requestId: 'release-overlap-new');
        $oldLease = $oldManager->checkout('main');
        $newLease = $newManager->checkout('main');

        try {
            $oldPdo = $oldLease->connection()->getPdo();
            $newPdo = $newLease->connection()->getPdo();
            $this->sampleHostState(2);

            $oldCount = $oldLease->connection()->withRunwire(
                $oldRuntime,
                fn(): int => (int) $oldLease->connection()->scalar('select count(*) from release_soak_items'),
                $oldRequest,
            );
            $newCount = $newLease->connection()->withRunwire(
                $newRuntime,
                fn(): int => (int) $newLease->connection()->scalar('select count(*) from release_soak_items'),
                $newRequest,
            );

            return $oldPdo !== $newPdo && $oldCount === 256 && $newCount === 256;
        } finally {
            if (!$oldRequest->completed()) {
                $oldRequest->complete();
            }
            if (!$newRequest->completed()) {
                $newRequest->complete();
            }

            $oldLease->release();
            $newLease->release();
            $oldPool->closeAll();
            $newPool->closeAll();
            $this->sampleHostState(0);
        }
    }

    private function resetDataset(): void
    {
        $connection = new Connection($this->config(), 'release-soak-setup');

        try {
            $connection->statement('drop table if exists release_soak_items');
            $connection->statement(
                'create table release_soak_items ('
                . 'id integer primary key, tenant_id integer not null, value varchar(96) not null'
                . ')',
            );

            $rows = [];
            for ($id = 1; $id <= 256; $id++) {
                $rows[] = [
                    'id' => $id,
                    'tenant_id' => ($id % 8) + 1,
                    'value' => 'row-' . $id,
                ];
            }

            if (!$connection->table('release_soak_items')->insert($rows)) {
                throw new RuntimeException('Unable to seed Runwire soak dataset.');
            }
        } finally {
            $connection->disconnect();
        }
    }
}

/** @return non-empty-string */
function releaseSoakRequiredOption(array $options, string $key): string
{
    $value = $options[$key] ?? null;

    if (!is_string($value) || trim($value) === '') {
        throw new InvalidArgumentException("Missing required --{$key} option.");
    }

    return trim($value);
}

$options = getopt('', ['project:', 'output:', 'requests::', 'concurrency::']);
$projectOption = releaseSoakRequiredOption($options, 'project');
$project = realpath($projectOption);
if (!is_string($project)) {
    throw new InvalidArgumentException("Project path does not exist: {$projectOption}");
}

(new ReleaseRunwireSoak(
    $project,
    releaseSoakRequiredOption($options, 'output'),
    max(1_000, (int) ($options['requests'] ?? 3_000)),
    max(2, (int) ($options['concurrency'] ?? 4)),
))->run();
