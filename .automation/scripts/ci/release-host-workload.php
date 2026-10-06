<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;

require_once __DIR__ . '/ReleaseHostResources.php';

/**
 * @phpstan-type WorkerResult array{successes:int,errors:int,queries:int,reads:int,writes:int,peak_rss_bytes:int,peak_php_heap_bytes:int,pid:int,cpu_user_seconds:float,cpu_system_seconds:float,latency_ms:list<float>}
 */
final class ReleaseHostWorkload
{
    /** @var list<int> */
    private array $concurrencies;

    /** @param list<int|string> $concurrencies */
    public function __construct(
        private readonly string $project,
        private readonly string $output,
        private readonly float $durationSeconds,
        private readonly int $trials,
        private readonly string $revision,
        private readonly float $warmupSeconds,
        private readonly int $pair,
        array $concurrencies,
    ) {
        if (!is_finite($durationSeconds) || $durationSeconds < 1.0
            || !is_finite($warmupSeconds) || $warmupSeconds < 0.0) {
            throw new InvalidArgumentException('Workload durations must be finite and within their declared bounds.');
        }
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
            'schema_version' => 2,
            'workload' => 'dblayer-release-host-v1',
            'environment' => $this->environment(),
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

    /** @return array<string,string> */
    private function environment(): array
    {
        $extensions = [];
        foreach (get_loaded_extensions() as $extension) {
            $extensions[$extension] = phpversion($extension) ?: 'builtin';
        }
        ksort($extensions);
        $cpu = file_get_contents('/proc/cpuinfo');
        $connection = $this->connection('release-host-environment');
        try {
            $database = $connection->scalar('select version()');
            $client = $connection->getPdo()->getAttribute(PDO::ATTR_CLIENT_VERSION);
            if (!is_string($database) || !is_string($client)) {
                throw new RuntimeException('Unable to identify database server and native client.');
            }
        } finally {
            $connection->disconnect();
        }

        return [
            'php' => PHP_VERSION,
            'extensions' => json_encode($extensions, JSON_THROW_ON_ERROR),
            'os' => php_uname('s') . ' ' . php_uname('r'),
            'architecture' => php_uname('m'),
            'cpu' => is_string($cpu) && preg_match('/^model name\s*:\s*(.+)$/m', $cpu, $matches) === 1
                ? $matches[1] : php_uname('m'),
            'database' => $database . ' / client ' . $client,
            'dataset' => '2000-items-16-tenants-v1',
        ];
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
            $count = $database->scalar('select count(*) from release_perf_items where id = ?', [$id]);
            if ($count !== 1 && $count !== '1') {
                throw new RuntimeException('Transactional-read correctness mismatch.');
            }

            $database->scalar('select score from release_perf_items where id = ?', [$id]);
        });

        $this->verifyStream($connection, $id);

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

    private function verifyStream(Connection $connection, int $id): void
    {
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

    }

    /**
     * @param list<WorkerResult> $workers
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
        $peakHeap = 0;
        $cpuUser = 0.0;
        $cpuSystem = 0.0;
        $workerResources = [];

        foreach ($workers as $worker) {
            $successes += $worker['successes'];
            $errors += $worker['errors'];
            $queries += $worker['queries'];
            $reads += $worker['reads'];
            $writes += $worker['writes'];
            $peakRss += $worker['peak_rss_bytes'];
            $peakHeap += $worker['peak_php_heap_bytes'];
            $cpuUser += $worker['cpu_user_seconds'];
            $cpuSystem += $worker['cpu_system_seconds'];
            $workerResources[] = [
                'pid' => $worker['pid'],
                'peak_rss_bytes' => $worker['peak_rss_bytes'],
                'peak_php_heap_bytes' => $worker['peak_php_heap_bytes'],
            ];

            foreach ($worker['latency_ms'] as $latency) {
                $latencies[] = $latency;
            }
        }

        sort($latencies, SORT_NUMERIC);

        return [
            'concurrency' => $concurrency,
            'trial' => $this->pair > 0 ? $this->pair : $trial,
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
            'peak_php_heap_bytes' => $peakHeap,
            'cpu_user_seconds' => $cpuUser,
            'cpu_system_seconds' => $cpuSystem,
            'worker_resource_peaks' => $workerResources,
        ];
    }

    /** @param list<float> $sorted */
    private function percentile(array $sorted, float $quantile): float
    {
        if ($sorted === []) {
            return 0.0;
        }

        $index = (int) floor((count($sorted) - 1) * $quantile);

        return $sorted[$index];
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
                $workerCode = $result['errors'] === 0
                    ? ''
                    : 'throw new RuntimeException("Release host worker failed.");';

                pcntl_exec(PHP_BINARY, ['-r', $workerCode]);

                throw new RuntimeException('Unable to terminate forked release workload worker.');
            }

            $pids[] = $pid;
        }

        $resources = ReleaseHostResources::waitForWorkers($pids);

        $workers = [];
        for ($worker = 0; $worker < $concurrency; $worker++) {
            $file = $directory . '/worker-' . $worker . '.json';
            $workers[] = $this->readWorkerResult($file);
            unlink($file);
        }
        rmdir($directory);

        $result = $this->aggregateWorkers($workers, $concurrency, $trial);
        $result['worker_process_failures'] = $resources['worker_process_failures'];
        $result['worker_peak_rss_bytes_sum'] = $result['peak_rss_bytes'];
        $result['peak_rss_bytes'] = $resources['peak_rss_bytes'];
        $result['resource_samples'] = $resources['resource_samples'];

        return $result;
    }

    /** @return WorkerResult */
    private function readWorkerResult(string $file): array
    {
        $contents = file_get_contents($file);
        if (!is_string($contents) || $contents === '') {
            throw new RuntimeException("Missing worker result: {$file}.");
        }
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('Malformed worker result.');
        }
        $counts = [];
        foreach (['successes', 'errors', 'queries', 'reads', 'writes', 'peak_rss_bytes', 'peak_php_heap_bytes', 'pid'] as $metric) {
            $value = $decoded[$metric] ?? null;
            if (!is_int($value) || $value < 0) {
                throw new RuntimeException("Invalid worker metric: {$metric}.");
            }
            $counts[$metric] = $value;
        }
        $latencies = $decoded['latency_ms'] ?? null;
        if (!is_array($latencies) || !array_is_list($latencies)) {
            throw new RuntimeException('Missing worker latency samples.');
        }

        return [
            'successes' => $counts['successes'], 'errors' => $counts['errors'],
            'queries' => $counts['queries'], 'reads' => $counts['reads'], 'writes' => $counts['writes'],
            'peak_rss_bytes' => $counts['peak_rss_bytes'],
            'peak_php_heap_bytes' => $counts['peak_php_heap_bytes'],
            'pid' => $counts['pid'],
            'cpu_user_seconds' => $this->workerCpuMetric($decoded, 'cpu_user_seconds'),
            'cpu_system_seconds' => $this->workerCpuMetric($decoded, 'cpu_system_seconds'),
            'latency_ms' => $this->validateLatencies($latencies),
        ];
    }

    /** @param array<array-key,mixed> $record */
    private function workerCpuMetric(array $record, string $field): float
    {
        $value = $record[$field] ?? null;
        if ((!is_float($value) && !is_int($value)) || !is_finite((float) $value) || $value < 0.0) {
            throw new RuntimeException("Missing worker CPU metric: {$field}.");
        }

        return (float) $value;
    }

    /** @param list<mixed> $values
     * @return list<float> */
    private function validateLatencies(array $values): array
    {
        $latencies = [];
        foreach ($values as $value) {
            if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < 0) {
                throw new RuntimeException('Invalid worker latency sample.');
            }
            $latencies[] = (float) $value;
        }

        return $latencies;
    }

    /** @return WorkerResult */
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

            $cpuBefore = ReleaseHostResources::cpuTime();
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
            $cpuAfter = ReleaseHostResources::cpuTime();

            return [
                'successes' => $successes,
                'errors' => $errors,
                'latency_ms' => $latencies,
                'queries' => $stats['queries'],
                'reads' => $stats['reads'],
                'writes' => $stats['writes'],
                'peak_rss_bytes' => ReleaseHostResources::peakRssBytes(),
                'peak_php_heap_bytes' => memory_get_peak_usage(true),
                'pid' => ReleaseHostResources::pid(),
                'cpu_user_seconds' => $cpuAfter['user_seconds'] - $cpuBefore['user_seconds'],
                'cpu_system_seconds' => $cpuAfter['system_seconds'] - $cpuBefore['system_seconds'],
            ];
        } finally {
            $connection->disconnect();
        }
    }
}

/** @param array<array-key,mixed> $options
 * @return non-empty-string */
function releaseHostRequiredOption(array $options, string $key): string
{
    $value = $options[$key] ?? null;

    $value = is_string($value) ? trim($value) : '';
    if ($value === '') {
        throw new InvalidArgumentException("Missing required --{$key} option.");
    }

    return $value;
}

$options = getopt('', ['project:', 'output:', 'duration::', 'trials::', 'concurrency::', 'revision::', 'warmup::', 'pair::']);
$projectOption = releaseHostRequiredOption($options, 'project');
$project = realpath($projectOption);
if (!is_string($project)) {
    throw new InvalidArgumentException("Project path does not exist: {$projectOption}");
}

$output = releaseHostRequiredOption($options, 'output');
$duration = max(1.0, (float) ($options['duration'] ?? 3.0));
$trials = max(1, (int) ($options['trials'] ?? 3));
$concurrency = explode(',', releaseHostRequiredOption($options + ['concurrency' => '1,2,4'], 'concurrency'));

(new ReleaseHostWorkload(
    $project,
    $output,
    $duration,
    $trials,
    releaseHostRequiredOption($options, 'revision'),
    max(0.0, (float) ($options['warmup'] ?? 1.0)),
    max(0, (int) ($options['pair'] ?? 0)),
    $concurrency,
))->run();
