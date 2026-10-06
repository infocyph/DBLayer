<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

require_once dirname(__DIR__, 2) . '/.automation/scripts/ci/ReleaseHostResources.php';

it('measures live descendant native memory and sockets during load', function (): void {
    $before = ReleaseHostResources::snapshot(getmypid());
    $process = new Process([PHP_BINARY, '-r', <<<'PHP'
        $buffer = str_repeat('x', 32 * 1024 * 1024);
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) { throw new RuntimeException('No test socket.'); }
        fwrite(STDOUT, 'ready');
        usleep(5_000_000);
        PHP]);
    $process->start();
    $pid = $process->getPid();
    try {
        $process->waitUntil(static fn(string $type, string $output): bool => ($type === Process::OUT && str_contains($output, 'ready')));
        $during = ReleaseHostResources::snapshot(getmypid());
        expect($during['pids'])->toContain($pid)
            ->and($during['rss_bytes'])->toBeGreaterThan($before['rss_bytes'] + 32 * 1024 * 1024)
            ->and($during['sockets'])->toBeGreaterThan($before['sockets']);
    } finally {
        $process->stop();
    }
    expect(ReleaseHostResources::snapshot(getmypid())['pids'])->not->toContain($pid);
});

it('reports operating-system high-water memory including allocations outside PHP heap', function (): void {
    expect(ReleaseHostResources::peakRssBytes())->toBeGreaterThan(memory_get_peak_usage(true));
});

it('exits workers without reloading runtime configuration or inherited shutdown hooks', function (int $exitCode): void {
    $directory = sys_get_temp_dir() . '/dblayer-worker-exit-' . bin2hex(random_bytes(6));
    mkdir($directory, 0700);
    $configuration = $directory . '/php.ini';
    file_put_contents($configuration, "display_startup_errors=1\nextension=dblayer_exit_startup_sentinel.so\n");
    try {
        $process = new Process([PHP_BINARY, '-r', <<<'PHP'
            require $argv[1];
            putenv('PHPRC=' . $argv[3]);
            putenv('PHP_INI_SCAN_DIR=' . $argv[3]);
            register_shutdown_function(static function (): void {
                fwrite(STDERR, 'Inherited shutdown hook executed.');
            });
            ReleaseHostResources::exitForkedWorker((int) $argv[2]);
            PHP,
            dirname(__DIR__, 2) . '/.automation/scripts/ci/ReleaseHostResources.php',
            (string) $exitCode,
            $directory,
        ]);
        $process->run();
        expect($process->getExitCode())->toBe($exitCode)
            ->and($process->getOutput())->toBe('')
            ->and($process->getErrorOutput())->toBe('');
    } finally {
        unlink($configuration);
        rmdir($directory);
    }
})->with([0, 1, 17]);

it('rejects exit statuses that would be truncated by the operating system', function (int $exitCode): void {
    expect(fn() => ReleaseHostResources::exitForkedWorker($exitCode))->toThrow(InvalidArgumentException::class);
})->with([-1, 256]);

it('keeps native peaks and successful exits while short-lived workers drain', function (): void {
    $pids = [];
    for ($worker = 0; $worker < 20; $worker++) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Unable to fork telemetry test worker.');
        }
        if ($pid === 0) {
            usleep(10_000);
            ReleaseHostResources::exitForkedWorker(0);
        }
        $pids[] = $pid;
    }
    $result = ReleaseHostResources::waitForWorkers($pids);
    expect($result['worker_process_failures'])->toBe(0)
        ->and($result['resource_samples'])->toBeGreaterThan(0)
        ->and($result['peak_rss_bytes'])->toBeGreaterThan(0)
        ->and(ReleaseHostResources::snapshot(getmypid())['pids'])->toBe([getmypid()]);
});

it('retains workload memory peaks and failed exits with configuration-free termination', function (): void {
    $before = ReleaseHostResources::snapshot(getmypid());
    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new RuntimeException('Unable to fork telemetry test worker.');
    }
    if ($pid === 0) {
        $buffer = str_repeat('x', 32 * 1024 * 1024);
        usleep(200_000);
        ReleaseHostResources::exitForkedWorker(17);
    }
    $result = ReleaseHostResources::waitForWorkers([$pid]);
    expect($result['worker_process_failures'])->toBe(1)
        ->and($result['peak_rss_bytes'])->toBeGreaterThan($before['rss_bytes'] + 32 * 1024 * 1024)
        ->and(ReleaseHostResources::snapshot(getmypid())['pids'])->toBe([getmypid()]);
});
