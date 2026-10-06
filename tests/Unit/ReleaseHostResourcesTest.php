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

it('keeps native peaks and successful exits while short-lived workers drain', function (): void {
    $pids = [];
    for ($worker = 0; $worker < 20; $worker++) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Unable to fork telemetry test worker.');
        }
        if ($pid === 0) {
            pcntl_exec(PHP_BINARY, ['-r', 'usleep(10000);']);
            throw new RuntimeException('Unable to exec telemetry test worker.');
        }
        $pids[] = $pid;
    }
    $result = ReleaseHostResources::waitForWorkers($pids);
    expect($result['worker_process_failures'])->toBe(0)
        ->and($result['resource_samples'])->toBeGreaterThan(0)
        ->and($result['peak_rss_bytes'])->toBeGreaterThan(0)
        ->and(ReleaseHostResources::snapshot(getmypid())['pids'])->toBe([getmypid()]);
});
