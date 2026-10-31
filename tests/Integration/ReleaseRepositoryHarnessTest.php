<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('measures repository mutations and relation projections as part of complete host requests', function (): void {
    $config = dblayerRequireDriver('pgsql');
    $output = sys_get_temp_dir() . '/dblayer-repository-host-' . bin2hex(random_bytes(6)) . '.json';
    $process = new Process([
        PHP_BINARY, dirname(__DIR__, 2) . '/.automation/scripts/ci/release-host-workload.php',
        '--project=' . dirname(__DIR__, 2), '--output=' . $output,
        '--duration=1', '--warmup=0', '--trials=1', '--concurrency=1', '--revision=' . str_repeat('b', 40),
    ], env: [
        'DBLAYER_PERF_HOST' => $config['host'], 'DBLAYER_PERF_PORT' => (string) $config['port'],
        'DBLAYER_PERF_DATABASE' => $config['database'], 'DBLAYER_PERF_USER' => $config['username'],
        'DBLAYER_PERF_PASSWORD' => $config['password'],
    ]);
    try {
        $process->mustRun();
        $result = json_decode(file_get_contents($output), true, 512, JSON_THROW_ON_ERROR);
        $run = $result['runs'][0];
        expect($result['workload'])->toBe('dblayer-release-host-v2')
            ->and($run['successful_requests'])->toBeGreaterThan(0)
            ->and($run['errors'])->toBe(0)
            ->and($run['worker_process_failures'])->toBe(0)
            ->and($run['writes'])->toBeGreaterThanOrEqual($run['successful_requests'])
            ->and($run['resource_samples'])->toBeGreaterThan(0);
    } finally {
        if (is_file($output)) {
            unlink($output);
        }
    }
});
