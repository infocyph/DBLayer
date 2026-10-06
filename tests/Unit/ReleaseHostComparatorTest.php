<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

function dblayerValidReleaseEvidence(): array
{
    $runs = [];
    foreach ([1, 2, 4] as $concurrency) {
        for ($trial = 1; $trial <= 7; $trial++) {
            $runs[] = [
                'concurrency' => $concurrency, 'trial' => $trial,
                'successful_requests' => 80, 'successful_rps' => 10,
                'errors' => 0, 'worker_process_failures' => 0,
                'p50_ms' => 1, 'p95_ms' => 2, 'p99_ms' => 3,
                'queries' => 480, 'reads' => 480, 'writes' => 0,
                'peak_rss_bytes' => 50 * 1024 * 1024,
            ];
        }
    }

    return [
        'schema_version' => 2, 'workload' => 'dblayer-release-host-v1',
        'revision' => str_repeat('a', 40), 'duration_seconds' => 8,
        'warmup_seconds' => 1, 'trials' => 7, 'concurrencies' => [1, 2, 4],
        'environment' => array_fill_keys(['php', 'extensions', 'os', 'architecture', 'cpu', 'database', 'dataset'], 'same'),
        'runs' => $runs,
    ];
}

function dblayerCompareReleaseEvidence(array $baseline, array $candidate, ?string $rawCandidate = null): array
{
    $directory = sys_get_temp_dir() . '/dblayer-evidence-' . bin2hex(random_bytes(6));
    mkdir($directory, 0700);
    try {
        file_put_contents($directory . '/baseline.json', json_encode($baseline, JSON_THROW_ON_ERROR));
        file_put_contents($directory . '/candidate.json', $rawCandidate ?? json_encode($candidate, JSON_THROW_ON_ERROR));
        $process = new Process([
            PHP_BINARY, dirname(__DIR__, 2) . '/.automation/scripts/ci/compare-release-host-workload.php',
            '--baseline=' . $directory . '/baseline.json', '--candidate=' . $directory . '/candidate.json',
            '--output=' . $directory . '/comparison.json',
            '--baseline-revision=' . str_repeat('a', 40), '--candidate-revision=' . str_repeat('a', 40),
        ]);
        $process->run();
        $result = is_file($directory . '/comparison.json')
            ? json_decode(file_get_contents($directory . '/comparison.json'), true, 512, JSON_THROW_ON_ERROR) : null;

        return [$process->getExitCode(), $result, $process->getErrorOutput()];
    } finally {
        foreach (glob($directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
}

it('pairs complete valid trials by identity rather than file order', function (): void {
    $baseline = dblayerValidReleaseEvidence();
    $candidate = $baseline;
    foreach ($baseline['runs'] as $index => &$run) {
        $run['successful_rps'] = 10 + $index;
        $candidate['runs'][$index]['successful_rps'] = $run['successful_rps'];
    }
    unset($run);
    $candidate['runs'] = array_reverse($candidate['runs']);
    [$exit, $result] = dblayerCompareReleaseEvidence($baseline, $candidate);
    expect($exit)->toBe(0)->and($result['passed'])->toBeTrue()
        ->and(array_column($result['comparisons'], 'throughput_regression_percent'))->toBe([0, 0, 0]);
});

it('fails closed on incomplete or mismatched evidence', function (string $case): void {
    $baseline = dblayerValidReleaseEvidence();
    $candidate = $baseline;
    switch ($case) {
        case 'empty': $baseline['runs'] = $candidate['runs'] = []; break;
        case 'missing runs': unset($candidate['runs']); break;
        case 'missing trial': array_pop($candidate['runs']); break;
        case 'missing concurrency': $candidate['runs'] = array_slice($candidate['runs'], 0, 14); break;
        case 'duplicate trial': $candidate['runs'][1] = $candidate['runs'][0]; break;
        case 'bad concurrency': $candidate['runs'][0]['concurrency'] = 8; break;
        case 'malformed trial': $candidate['runs'][0] = null; break;
        case 'zero throughput': $candidate['runs'][0]['successful_rps'] = 0; break;
        case 'negative throughput': $candidate['runs'][0]['successful_rps'] = -1; break;
        case 'nonnumeric throughput': $candidate['runs'][0]['successful_rps'] = 'INF'; break;
        case 'missing errors': unset($candidate['runs'][0]['errors']); break;
        case 'missing process failures': unset($candidate['runs'][0]['worker_process_failures']); break;
        case 'zero successes': $candidate['runs'][0]['successful_requests'] = 0; break;
        case 'zero rss': $candidate['runs'][0]['peak_rss_bytes'] = 0; break;
        case 'wrong revision': $candidate['revision'] = str_repeat('b', 40); break;
        case 'wrong environment': $candidate['environment']['php'] = 'different'; break;
        case 'empty environment': $candidate['environment'] = []; break;
        case 'missing environment field': unset($candidate['environment']['database']); break;
        case 'wrong workload': $candidate['workload'] = 'other'; break;
        case 'duration mismatch': $candidate['duration_seconds'] = 10; break;
        case 'failed requests': $candidate['runs'][0]['errors'] = 1; break;
        case 'process failure': $candidate['runs'][0]['worker_process_failures'] = 1; break;
    }
    [$exit, $result] = dblayerCompareReleaseEvidence($baseline, $candidate);
    expect($exit)->not->toBe(0);
    if ($result !== null) {
        expect($result['passed'])->toBeFalse();
    }
})->with([
    'empty', 'missing runs', 'missing trial', 'missing concurrency', 'duplicate trial',
    'bad concurrency', 'malformed trial', 'zero throughput', 'negative throughput',
    'nonnumeric throughput', 'missing errors', 'missing process failures', 'zero successes',
    'zero rss', 'wrong revision', 'wrong environment', 'empty environment',
    'missing environment field', 'wrong workload', 'duration mismatch', 'failed requests', 'process failure',
]);

it('retains throughput and native memory regression budgets', function (string $metric, int $value): void {
    $baseline = dblayerValidReleaseEvidence();
    $candidate = $baseline;
    foreach ($candidate['runs'] as &$run) {
        $run[$metric] = $value;
    }
    unset($run);
    [$exit, $result] = dblayerCompareReleaseEvidence($baseline, $candidate);
    expect($exit)->not->toBe(0)->and($result['passed'])->toBeFalse();
})->with([['successful_rps', 8], ['peak_rss_bytes', 100 * 1024 * 1024], ['p95_ms', 10]]);

it('rejects malformed JSON and nonfinite numeric evidence', function (bool $overflow): void {
    $evidence = dblayerValidReleaseEvidence();
    $raw = $overflow
        ? preg_replace('/"successful_rps":10/', '"successful_rps":1e309', json_encode($evidence, JSON_THROW_ON_ERROR), 1)
        : '{';
    [$exit] = dblayerCompareReleaseEvidence($evidence, $evidence, $raw);
    expect($exit)->not->toBe(0);
})->with([false, true]);
