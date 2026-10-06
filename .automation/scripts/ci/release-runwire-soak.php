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

require_once __DIR__ . '/ReleaseHostResources.php';

final class ReleaseRunwireSoak
{
    private int $hostSamples = 0;

    private int $initialOpenSockets = 0;

    private int $maxOpenSockets = 0;

    private int $maxProcessTreeRssBytes = 0;

    private int $maxTaskQueueDepth = 0;

    /** @var list<array<string,int|float>> */
    private array $resourceTimeline = [];

    private int $lastSampleSecond = -1;

    private int $startedNanoseconds = 0;

    public function __construct(
        private readonly string $project,
        private readonly string $output,
        private readonly int $requests,
        private readonly int $concurrency,
        private readonly float $durationSeconds,
        private readonly string $revision,
    ) {
        if (!is_finite($durationSeconds) || $durationSeconds < 1.0) {
            throw new InvalidArgumentException('Soak duration must be finite and at least one second.');
        }
        if (preg_match('/^[a-f0-9]{40}$/D', $revision) !== 1) {
            throw new InvalidArgumentException('Soak evidence requires an exact source revision.');
        }
    }

    public function run(): void
    {
        $autoload = $this->project . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            throw new RuntimeException("Missing target autoloader: {$autoload}");
        }

        require_once $autoload;

        $this->startedNanoseconds = hrtime(true);
        $this->initialOpenSockets = ReleaseHostResources::snapshot(ReleaseHostResources::pid())['sockets'];
        $this->sampleHostState(0);
        $this->resetDataset();
        gc_collect_cycles();
        $memoryBefore = memory_get_usage(true);
        $rssBefore = ReleaseHostResources::snapshot(ReleaseHostResources::pid())['rss_bytes'];
        $perPhase = intdiv($this->requests, 2);
        $first = $this->runPhase(1, $perPhase, 0);
        $second = $this->runPhase(2, $this->requests - $perPhase, $first['completed_requests']);
        $deploymentOverlap = $this->verifyDeploymentOverlap();
        $this->sampleHostState(0);
        gc_collect_cycles();
        $memoryAfter = memory_get_usage(true);
        $memoryGrowth = max(0, $memoryAfter - $memoryBefore);
        $unexpectedErrors = $first['unexpected_errors'] + $second['unexpected_errors'];
        $activeLeaks = $first['active_connections_after'] + $second['active_connections_after'];
        $maxTotal = max($first['total_connections_after'], $second['total_connections_after']);
        $finalResources = ReleaseHostResources::snapshot(ReleaseHostResources::pid());
        $finalOpenSockets = $finalResources['sockets'];
        $rssGrowth = max(0, $finalResources['rss_bytes'] - $rssBefore);
        $loadDuration = $first['duration_seconds'] + $second['duration_seconds'];
        $socketGrowth = max(0, $finalOpenSockets - $this->initialOpenSockets);
        $checks = [
            'no_unexpected_errors' => $unexpectedErrors === 0,
            'no_active_leases' => $activeLeaks === 0,
            'bounded_connections' => $maxTotal <= $this->concurrency,
            'bounded_php_heap_growth' => $memoryGrowth <= 32 * 1024 * 1024,
            'no_socket_growth' => $socketGrowth <= 1,
            'bounded_sockets' => $this->maxOpenSockets <= $this->initialOpenSockets + $this->concurrency + 2,
            'bounded_queue' => $this->maxTaskQueueDepth <= $this->concurrency,
            'generation_overlap' => $deploymentOverlap,
            'minimum_load_duration' => $loadDuration >= $this->durationSeconds,
            'bounded_rss_growth' => $rssGrowth <= 32 * 1024 * 1024,
            'bounded_peak_rss' => $this->maxProcessTreeRssBytes <= 256 * 1024 * 1024,
            'first_idle_expiry' => $first['idle_expiry_verified'],
            'second_idle_expiry' => $second['idle_expiry_verified'],
            'first_maximum_lifetime' => $first['maximum_lifetime_verified'],
            'second_maximum_lifetime' => $second['maximum_lifetime_verified'],
            'first_retry_recovery' => $first['retry_recovery_verified'],
            'second_retry_recovery' => $second['retry_recovery_verified'],
        ];
        $passed = !in_array(false, $checks, true);

        $payload = [
            'revision' => $this->revision,
            'minimum_requests' => $this->requests,
            'requests' => $first['completed_requests'] + $second['completed_requests'],
            'minimum_load_duration_seconds' => $this->durationSeconds,
            'load_duration_seconds' => $loadDuration,
            'sustained_duration_met' => $loadDuration >= $this->durationSeconds,
            'release_duration_met' => $this->durationSeconds >= 300.0 && $loadDuration >= 300.0,
            'rss_growth_budget_bytes' => 32 * 1024 * 1024,
            'peak_rss_budget_bytes' => 256 * 1024 * 1024,
            'rss_growth_bytes' => $rssGrowth,
            'resource_timeline' => $this->resourceTimeline,
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
            'peak_php_heap_bytes' => memory_get_peak_usage(true),
            'peak_rss_bytes' => ReleaseHostResources::peakRssBytes(),
            'max_total_connections_after_phase' => $maxTotal,
            'active_connections_after_phases' => $activeLeaks,
            'checks' => $checks,
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
     *   duration_seconds:float,
     *   completed_requests:int,
     *   idle_expiry_verified:bool,
     *   retry_recovery_verified:bool,
     *   maximum_lifetime_verified:bool,
     *   connections_created:int,
     *   connections_closed:int,
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
            'idle_timeout' => 1,
            'max_lifetime' => 30,
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

        $lease = $manager->checkout('main');
        $originalPdo = $lease->connection()->getPdo();
        $lease->release();
        usleep(1_100_000);
        $manager->warmUp('main', $this->concurrency);
        $lease = $manager->checkout('main');
        $idleExpiryVerified = $lease->connection()->getPdo() !== $originalPdo;
        $lease->release();
        unset($originalPdo);
        $started = hrtime(true);
        $endAt = $started + (int) ($this->durationSeconds * 500_000_000);
        $counts = $this->emptyPhaseCounts();

        try {
            new CoroutineRuntime()->run(function (CoroutineScope $scope) use (
                $manager, $runtime, $requestCount, $offset, $endAt, &$counts,
            ): void {
                $counts = $this->runPhaseLoad($scope, $manager, $runtime, $requestCount, $offset, $endAt);
            });

            $elapsed = (hrtime(true) - $started) / 1_000_000_000;
            $manager->warmUp('main', $this->concurrency);
            $stats = $pool->getStats();

            return $counts + [
                'generation' => $generation,
                'duration_seconds' => $elapsed,
                'idle_expiry_verified' => $idleExpiryVerified,
                'connections_created' => (int) $stats['created'],
                'connections_closed' => (int) $stats['closed'],
                'active_connections_after' => (int) $stats['active_connections'],
                'idle_connections_after' => (int) $stats['idle_connections'],
                'total_connections_after' => (int) $stats['total_connections'],
            ];
        } finally {
            $pool->closeAll();
        }
    }

    /**
     * @return array{completed_requests:int,retry_recovery_verified:bool,maximum_lifetime_verified:bool,successful_requests:int,expected_cancellations:int,iterator_fences:int,unexpected_errors:int}
     */
    private function emptyPhaseCounts(): array
    {
        return [
            'completed_requests' => 0, 'retry_recovery_verified' => true,
            'maximum_lifetime_verified' => false,
            'successful_requests' => 0, 'expected_cancellations' => 0,
            'iterator_fences' => 0, 'unexpected_errors' => 0,
        ];
    }

    /**
     * @return array{completed_requests:int,retry_recovery_verified:bool,maximum_lifetime_verified:bool,successful_requests:int,expected_cancellations:int,iterator_fences:int,unexpected_errors:int}
     */
    private function runPhaseLoad(
        CoroutineScope $scope,
        PoolManager $manager,
        RuntimeContext $runtime,
        int $requestCount,
        int $offset,
        int $endAt,
    ): array {
        $counts = $this->emptyPhaseCounts();
        for ($base = 0; $base < $requestCount || hrtime(true) < $endAt; $base += $this->concurrency) {
            if ($base % 2_000 === 0) {
                $recovered = $this->verifyRetryRecovery($manager, $runtime, $scope);
                $counts['retry_recovery_verified'] = $recovered && $counts['retry_recovery_verified'];
            }
            if ($base === $this->concurrency) {
                $counts['maximum_lifetime_verified'] = $this->verifyMaximumLifetime($scope, $runtime);
            }
            $batch = $this->runBatch($scope, $manager, $runtime, $offset + $base);
            foreach ($batch as $metric => $value) {
                $counts[$metric] += $value;
            }
            $counts['completed_requests'] += $this->concurrency;
            if ($counts['completed_requests'] % 200 === 0) {
                $fenced = $this->verifyIteratorFence($manager, $runtime, $offset + $counts['completed_requests']);
                $counts[$fenced ? 'iterator_fences' : 'unexpected_errors']++;
            }
        }

        return $counts;
    }

    /** @return array{successful_requests:int,expected_cancellations:int,unexpected_errors:int} */
    private function runBatch(
        CoroutineScope $scope,
        PoolManager $manager,
        RuntimeContext $runtime,
        int $offset,
    ): array {
        $tasks = [];
        for ($slot = 0; $slot < $this->concurrency; $slot++) {
            $sequence = $offset + $slot + 1;
            $tasks[] = $scope->spawn(fn(): string => $this->runRequest($manager, $runtime, $scope, $sequence));
        }
        $queued = count($tasks);
        $this->sampleHostState($queued);
        $counts = ['successful_requests' => 0, 'expected_cancellations' => 0, 'unexpected_errors' => 0];
        foreach ($tasks as $task) {
            try {
                $metric = match ($task->await()) {
                    'ok' => 'successful_requests',
                    'cancelled' => 'expected_cancellations',
                    default => 'unexpected_errors',
                };
                $counts[$metric]++;
            } catch (Throwable) {
                $counts['unexpected_errors']++;
            } finally {
                $this->sampleHostState(--$queued);
            }
        }

        return $counts;
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
                        fn(): mixed => $lease->connection()->scalar('select 1'),
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
                function () use ($lease, $scope, $sequence): mixed {
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

                    return $lease->connection()->scalar(
                        'select count(*) from release_soak_items where id between ? and ?',
                        [1, 256],
                    );
                },
                $request,
                $scope,
            );

            return ($result === 256 || $result === '256') ? 'ok' : 'unexpected-count';
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

    private function sampleHostState(int $queueDepth): void
    {
        $this->hostSamples++;
        $resources = ReleaseHostResources::snapshot(ReleaseHostResources::pid());
        $this->maxTaskQueueDepth = max($this->maxTaskQueueDepth, $queueDepth);
        $this->maxOpenSockets = max($this->maxOpenSockets, $resources['sockets']);
        $this->maxProcessTreeRssBytes = max($this->maxProcessTreeRssBytes, $resources['rss_bytes']);
        $second = (int) ((hrtime(true) - $this->startedNanoseconds) / 1_000_000_000);
        if ($second !== $this->lastSampleSecond) {
            $this->resourceTimeline[] = [
                'elapsed_seconds' => $second,
                'pid' => ReleaseHostResources::pid(),
                'cpu_user_seconds' => ReleaseHostResources::cpuTime()['user_seconds'],
                'cpu_system_seconds' => ReleaseHostResources::cpuTime()['system_seconds'],
                'rss_bytes' => $resources['rss_bytes'],
                'open_sockets' => $resources['sockets'],
                'queue_depth' => $queueDepth,
            ];
            $this->lastSampleSecond = $second;
        }
    }

    private function verifyMaximumLifetime(CoroutineScope $scope, RuntimeContext $runtime): bool
    {
        $pool = new Pool(['min_connections' => 0, 'max_connections' => 1, 'idle_timeout' => 0, 'max_lifetime' => 1]);
        $pool->addConfig('expiry', $this->config());
        $manager = new PoolManager($pool);
        $lease = $manager->checkout('expiry');
        $oldPdo = $lease->connection()->getPdo();
        $next = null;
        try {
            $scope->sleep(1.1);
            $heldValue = $lease->connection()->withRunwire(
                $runtime, fn(): mixed => $lease->connection()->scalar('select 1'), scope: $scope,
            );
            $lease->release();
            $next = $manager->checkout('expiry');
            $newPdo = $next->connection()->getPdo();
            $this->sampleHostState(0);

            return $oldPdo !== $newPdo && in_array($heldValue, [1, '1'], true);
        } finally {
            if (!$lease->isReleased()) {
                $lease->release();
            }
            $next?->release();
            $pool->closeAll();
        }
    }

    private function verifyRetryRecovery(
        PoolManager $manager,
        RuntimeContext $runtime,
        CoroutineScope $scope,
    ): bool {
        $blocker = new Connection($this->config(), 'release-soak-lock');
        $lease = $manager->checkout('main');
        $attempts = 0;
        $blocker->getPdo()->beginTransaction();
        try {
            $blocker->scalar('select id from release_soak_items where id = 1 for update');
            $policy = static function (Throwable $error, int $attempt) use ($blocker, &$attempts): bool {
                $attempts++;
                $blocker->getPdo()->rollBack();

                return $attempt === 1 && str_contains($error->getMessage(), 'lock timeout');
            };
            $connection = $lease->connection();
            $connection->getPdo()->exec("set lock_timeout = '5ms'");
            $value = $connection->withRunwire($runtime, fn() => $connection->withQueryRetryPolicy(
                $policy,
                fn() => $connection->scalar('select id from release_soak_items where id = 1 for update'),
            ), scope: $scope);

            return ($value === 1 || $value === '1') && $attempts === 1;
        } finally {
            if ($blocker->getPdo()->inTransaction()) {
                $blocker->getPdo()->rollBack();
            }
            $lease->connection()->getPdo()->exec('set lock_timeout = 0');
            $lease->release();
            $blocker->disconnect();
        }
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
                fn(): mixed => $oldLease->connection()->scalar('select count(*) from release_soak_items'),
                $oldRequest,
            );
            $newCount = $newLease->connection()->withRunwire(
                $newRuntime,
                fn(): mixed => $newLease->connection()->scalar('select count(*) from release_soak_items'),
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

/** @param array<array-key,mixed> $options
 * @return non-empty-string */
function releaseSoakRequiredOption(array $options, string $key): string
{
    $value = $options[$key] ?? null;

    $value = is_string($value) ? trim($value) : '';
    if ($value === '') {
        throw new InvalidArgumentException("Missing required --{$key} option.");
    }

    return $value;
}

$options = getopt('', ['project:', 'output:', 'requests::', 'concurrency::', 'duration::', 'revision:']);
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
    max(1.0, (float) ($options['duration'] ?? 300.0)),
    releaseSoakRequiredOption($options, 'revision'),
))->run();
