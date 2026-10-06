<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('runs until elapsed load duration and request count are both met without certifying a short smoke', function (): void {
    $config = dblayerRequireDriver('pgsql');
    $output = sys_get_temp_dir() . '/dblayer-soak-test-' . bin2hex(random_bytes(6)) . '.json';
    $revision = str_repeat('a', 40);
    $process = new Process([
        PHP_BINARY, dirname(__DIR__, 2) . '/.automation/scripts/ci/release-runwire-soak.php',
        '--project=' . dirname(__DIR__, 2), '--output=' . $output,
        '--requests=1000', '--concurrency=2', '--duration=1', '--revision=' . $revision,
    ], env: [
        'DBLAYER_PERF_HOST' => $config['host'], 'DBLAYER_PERF_PORT' => (string) $config['port'],
        'DBLAYER_PERF_DATABASE' => $config['database'], 'DBLAYER_PERF_USER' => $config['username'],
        'DBLAYER_PERF_PASSWORD' => $config['password'],
    ]);
    try {
        $process->mustRun();
        $result = json_decode(file_get_contents($output), true, 512, JSON_THROW_ON_ERROR);
        expect($result['passed'])->toBeTrue()
            ->and($result['release_duration_met'])->toBeFalse()
            ->and($result['load_duration_seconds'])->toBeGreaterThanOrEqual(1.0)
            ->and($result['requests'])->toBeGreaterThanOrEqual(1000)
            ->and($result['unexpected_errors'])->toBe(0)
            ->and($result['socket_growth'])->toBe(0)
            ->and($result['revision'])->toBe($revision)
            ->and($result['peak_rss_bytes'])->toBeGreaterThan($result['peak_php_heap_bytes']);
        foreach ($result['phases'] as $phase) {
            expect($phase['idle_expiry_verified'])->toBeTrue()
                ->and($phase['maximum_lifetime_verified'])->toBeTrue()
                ->and($phase['retry_recovery_verified'])->toBeTrue()
                ->and($phase['active_connections_after'])->toBe(0);
        }
    } finally {
        if (is_file($output)) {
            unlink($output);
        }
    }
});
