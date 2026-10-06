<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;

final class ReleaseHostWorkload
{
    /** @var list<int> */
    private array $concurrencies;

    public function __construct(
        private readonly string $project,
        private readonly string $output,
        private readonly float $durationSeconds,
        private readonly int $trials,
        private readonly string $revision,
        private readonly float $warmupSeconds,
        array $concurrencies,
    ) {
        $this->concurrencies = array_values(array_unique(array_map('intval', $concurrencies)));
    }

    public function run(): void
    {
        $autoload = $this->project . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            throw new RuntimeException("Missing target autoloader: {$autoload}");
        }

        require_once $autoload;

        if (!function_exists('pcntl_fork')) {
            throw new RuntimeException('The release workload requires ext-pcntl.');
        }

        $this->resetDataset();
        $runs = [];

        foreach ($this->concurrencies as $concurrency) {
            if ($concurrency < 1) {
                throw new InvalidArgumentException('Concurrency values must be positive.');
            }

            $this->warmUp($concurrency);

            for ($trial = 1; $trial <= $this->trials; $trial++) {
                $runs[] = $this->runTrial($concurrency, $trial);
            }
        }

        $payload = [
            'project' => basename($this->project),
            'revision' => $this->revision,
            'duration_seconds' => $this->durationSeconds,
            'trials' => $this->trials,
            'warmup_seconds' => $this->warmupSeconds,
            'concurrencies' => $this->concurrencies,
            'php_version' => PHP_VERSION,
            'runs' => $runs,
        ];

        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($this->output, $encoded . PHP_EOL) === false) {
            throw new RuntimeException("Unable to write workload result: {$this->output}");
        }
    }

    private function connection(string $name): Connection
    {
        return new Connection(ConnectionConfig::fromArray([
            'driver' => 'pgsql',
            'host' => getenv('DBLAYER_PERF_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('DBLAYER_PERF_PORT') ?: 5432),
            'database' => getenv('DBLAYER_PERF_DATABASE') ?: 'dblayer_perf',
            'username' => getenv('DBLAYER_PERF_USER') ?: 'dblayer',
            'password' => getenv('DBLAYER_PERF_PASSWORD') ?: 'dblayer',
            'statement_cache_enabled' => true,
            'statement_cache_size' => 64,
        ]), $name);
    }

    private function executeRequest(Connection $connection, int $worker, int $sequence): void
    {
        $id = (($sequence * 17 + $worker * 29) % 2_000) + 1;
        $row = $connection->table('release_perf_items')->where('id', '=', $id)->first();
        if (($row['id'] ?? null) !== $id) {
            throw new RuntimeException('Point-read correctness mismatch.');
        }

        $rows = $connection->table('release_perf_items')
            ->where('id', '>=', $id)
            ->orderBy('id')
            ->limit(20)
            ->get();
        if ($rows === []) {
            throw new RuntimeException('Range-read correctness mismatch.');
        }

        $cached = $connection->table('release_perf_items')
            ->where('id', '=', $id)
            ->cacheFor(30)
            ->cacheKey('release.host.hit.' . $id)
            ->get();
        if (($cached[0]['id'] ?? null) !== $id) {
            throw new RuntimeException('Cached-read correctness mismatch.');
        }

        $connection->transaction(static function (Connection $database) use ($id): void {
            if ((int) $database->scalar('select count(*) from release_perf_items where id = ?', [$id]) !== 1) {
                throw new RuntimeException('Transactional-read correctness mismatch.');
            }

            $database->scalar('select score from release_perf_items where id = ?', [$id]);
        });

        $streamed = 0;
        foreach ($connection->stream(
            'select id, score from release_perf_items where id >= ? order by id limit 10',
            [$id],
        ) as $streamRow) {
            if (!is_array($streamRow)) {
                throw new RuntimeException('Stream row shape mismatch.');
            }
            $streamed++;
        }

        if ($streamed < 1) {
            throw new RuntimeException('Stream correctness mismatch.');
        }

        if ($sequence % 20 === 0) {
            $ownedId = $worker + 1;
            $affected = $connection->table('release_perf_items')
                ->where('id', '=', $ownedId)
                ->update(['score' => ($sequence + $worker) % 100_000]);

            if ($affected !== 1) {
                throw new RuntimeException('Structured-write correctness mismatch.');
            }
        }

        if ($sequence % 25 === 0) {
            $connection->table('release_perf_items')
                ->where('id', '=', $id)
                ->cacheFor(30)
                ->cacheKey('release.host.miss.' . $worker . '.' . $sequence)
                ->get();
        }
    }

    /**
     * @param list<array<string,mixed>> $workers
     * @return array<string,mixed>
     */
    private function aggregateWorkers(array $workers, int $concurrency, int $trial): array
    {
        $latencies = [];
        $successes = 0;
        $errors = 0;
        $queries = 0;
        $reads = 0;
        $writes = 0;
        $peakRss = 0;

        foreach ($workers as $worker) {
            $successes += (int) ($worker['successes'] ?? 0);
            $errors += (int) ($worker['errors'] ?? 0);
            $queries += (int) ($worker['queries'] ?? 0);
            $reads += (int) ($worker['reads'] ?? 0);
            $writes += (int) ($worker['writes'] ?? 0);
            $peakRss += (int) ($worker['peak_rss_bytes'] ?? 0);

            foreach (($worker['latency_ms'] ?? []) as $latency) {
                if (is_int($latency) || is_float($latency)) {
                    $latencies[] = (float) $latency;
                }
            }
        }

        sort($latencies, SORT_NUMERIC);

        return [
            'concurrency' => $concurrency,
            'trial' => $trial,
            'successful_requests' => $successes,
            'errors' => $errors,
            'successful_rps' => $successes / $this->durationSeconds,
            'p50_ms' => $this->percentile($latencies, 0.50),
            'p95_ms' => $this->percentile($latencies, 0.95),
            'p99_ms' => $this->percentile($latencies, 0.99),
            'queries' => $queries,
            'reads' => $reads,
            'writes' => $writes,
            'peak_rss_bytes' => $peakRss,
            'connections' => $concurrency,
        ];
    }

    private function percentile(array $sorted, float $quantile): float
    {
        if ($sorted === []) {
            return 0.0;
        }

        $index = (int) floor((count($sorted) - 1) * $quantile);

        return (float) $sorted[$index];
    }

    private function resetDataset(): void
    {
        $connection = $this->connection('release-host-setup');

        try {
            $connection->statement('drop table if exists release_perf_items');
            $connection->statement(
                'create table release_perf_items ('
                . 'id integer primary key, tenant_id integer not null, score integer not null, payload varchar(96) not null'
                . ')',
            );
            $connection->statement('create index release_perf_tenant_score on release_perf_items (tenant_id, score)');

            for ($start = 1; $start <= 2_000; $start += 100) {
                $rows = [];

                for ($id = $start; $id < $start + 100 && $id <= 2_000; $id++) {
                    $rows[] = [
                        'id' => $id,
                        'tenant_id' => ($id % 16) + 1,
                        'score' => $id * 10,
                        'payload' => 'payload-' . $id,
                    ];
                }

                if (!$connection->table('release_perf_items')->insert($rows)) {
                    throw new RuntimeException('Unable to seed representative workload dataset.');
                }
            }
        } finally {
            $connection->disconnect();
        }
    }

    private function warmUp(int $concurrency): void
    {
        if ($this->warmupSeconds <= 0.0) {
            return;
        }

        $connections = [];
        try {
            for ($worker = 0; $worker < $concurrency; $worker++) {
                $connection = $this->connection('release-host-warmup-' . $worker);
                $connection->scalar('select 1');
                $connections[] = $connection;
            }

            $endAt = microtime(true) + $this->warmupSeconds;
            $sequence = 0;

            while (microtime(true) < $endAt) {
                foreach ($connections as $worker => $connection) {
                    $sequence++;
                    $this->executeRequest($connection, $worker, $sequence);
                }
            }
        } finally {
            foreach ($connections as $connection) {
                $connection->disconnect();
            }
        }
    }

    /** @return array<string,mixed> */
    private function runTrial(int $concurrency, int $trial): array
    {
        $directory = sys_get_temp_dir() . '/dblayer-release-host-' . getmypid() . '-' . $concurrency . '-' . $trial;
        if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create trial directory: {$directory}");
        }

        $startAt = microtime(true) + 0.35;
        $pids = [];

        for ($worker = 0; $worker < $concurrency; $worker++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Unable to fork release workload worker.');
            }

            if ($pid === 0) {
                $result = $this->runWorker($worker, $startAt);
                file_put_contents(
                    $directory . '/worker-' . $worker . '.json',
                    json_encode($result, JSON_THROW_ON_ERROR),
                );
                $workerCode = ($result['errors'] ?? 1) === 0
                    ? ''
                    : 'throw new RuntimeException("Release host worker failed.");';

                pcntl_exec(PHP_BINARY, ['-r', $workerCode]);

                throw new RuntimeException('Unable to terminate forked release workload worker.');
            }

            $pids[] = $pid;
        }

        $processFailures = 0;
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
                $processFailures++;
            }
        }

        $workers = [];
        for ($worker = 0; $worker < $concurrency; $worker++) {
            $file = $directory . '/worker-' . $worker . '.json';
            $contents = is_file($file) ? file_get_contents($file) : false;
            if (!is_string($contents) || $contents === '') {
                $workers[] = ['errors' => 1, 'latency_ms' => []];

                continue;
            }

            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            $workers[] = is_array($decoded) ? $decoded : ['errors' => 1, 'latency_ms' => []];
            unlink($file);
        }
        rmdir($directory);

        $result = $this->aggregateWorkers($workers, $concurrency, $trial);
        $result['worker_process_failures'] = $processFailures;

        return $result;
    }

    /** @return array<string,mixed> */
    private function runWorker(int $worker, float $startAt): array
    {
        $connection = $this->connection('release-host-' . $worker);
        $successes = 0;
        $errors = 0;
        $latencies = [];
        $sequence = 0;

        try {
            $connection->scalar('select 1');

            while (microtime(true) < $startAt) {
                usleep(1_000);
            }

            $endAt = $startAt + $this->durationSeconds;

            while (microtime(true) < $endAt) {
                $sequence++;
                $started = hrtime(true);

                try {
                    $this->executeRequest($connection, $worker, $sequence);
                    $successes++;
                } catch (Throwable) {
                    $errors++;
                }

                $elapsed = hrtime(true) - $started;
                $latencies[] = $elapsed / 1_000_000;
            }

            $stats = $connection->getStats();

            return [
                'successes' => $successes,
                'errors' => $errors,
                'latency_ms' => $latencies,
                'queries' => (int) ($stats['queries'] ?? 0),
                'reads' => (int) ($stats['reads'] ?? 0),
                'writes' => (int) ($stats['writes'] ?? 0),
                'peak_rss_bytes' => memory_get_peak_usage(true),
            ];
        } finally {
            $connection->disconnect();
        }
    }
}

/** @return non-empty-string */
function releaseHostRequiredOption(array $options, string $key): string
{
    $value = $options[$key] ?? null;

    if (!is_string($value) || trim($value) === '') {
        throw new InvalidArgumentException("Missing required --{$key} option.");
    }

    return trim($value);
}

$options = getopt('', ['project:', 'output:', 'duration::', 'trials::', 'concurrency::', 'revision::', 'warmup::']);
$projectOption = releaseHostRequiredOption($options, 'project');
$project = realpath($projectOption);
if (!is_string($project)) {
    throw new InvalidArgumentException("Project path does not exist: {$projectOption}");
}

$output = releaseHostRequiredOption($options, 'output');
$duration = max(1.0, (float) ($options['duration'] ?? 3.0));
$trials = max(1, (int) ($options['trials'] ?? 3));
$concurrency = explode(',', (string) ($options['concurrency'] ?? '1,2,4'));

(new ReleaseHostWorkload(
    $project,
    $output,
    $duration,
    $trials,
    (string) ($options['revision'] ?? 'unknown'),
    max(0.0, (float) ($options['warmup'] ?? 1.0)),
    $concurrency,
))->run();
