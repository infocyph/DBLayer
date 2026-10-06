<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/.automation/scripts/ci/ReleaseHostResources.php';
require_once dirname(__DIR__, 2) . '/.automation/scripts/ci/ReleaseHostStartBarrier.php';

function dblayerWithReleaseStartBarrier(Closure $callback, float $timeoutSeconds = 5.0): void
{
    $directory = sys_get_temp_dir() . '/dblayer-start-barrier-' . bin2hex(random_bytes(6));
    mkdir($directory, 0700);
    try {
        $callback(new ReleaseHostStartBarrier($directory, 2, $timeoutSeconds), $directory);
    } finally {
        foreach (glob($directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
}

it('starts the complete measurement window only after every worker has initialized', function (): void {
    dblayerWithReleaseStartBarrier(function (ReleaseHostStartBarrier $barrier, string $directory): void {
        $pids = [];
        for ($worker = 0; $worker < 2; $worker++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Unable to fork delayed release worker.');
            }
            if ($pid === 0) {
                if ($worker === 1) {
                    usleep(400_000);
                }
                $initialized = hrtime(true);
                $barrier->awaitRelease($worker);
                $started = hrtime(true);
                usleep(100_000);
                file_put_contents($directory . '/result-' . $worker, json_encode([
                    'initialized' => $initialized, 'started' => $started, 'finished' => hrtime(true),
                ], JSON_THROW_ON_ERROR));
                ReleaseHostResources::exitForkedWorker(0);
            }
            $pids[] = $pid;
        }
        $resources = ReleaseHostResources::waitForWorkers($pids, $barrier->releaseIfReady(...));
        expect($resources['worker_process_failures'])->toBe(0)
            ->and($resources['resource_samples'])->toBeGreaterThan(1);
        $early = json_decode(file_get_contents($directory . '/result-0'), true, 512, JSON_THROW_ON_ERROR);
        $late = json_decode(file_get_contents($directory . '/result-1'), true, 512, JSON_THROW_ON_ERROR);
        expect($early['started'])->toBeGreaterThanOrEqual($late['initialized'])
            ->and($late['started'])->toBeGreaterThanOrEqual($early['initialized']);
        foreach ([$early, $late] as $worker) {
            expect($worker['finished'] - $worker['started'])->toBeGreaterThanOrEqual(100_000_000);
        }
    });
});

it('bounds startup waiting when another worker never becomes ready', function (): void {
    dblayerWithReleaseStartBarrier(function (ReleaseHostStartBarrier $barrier, string $directory): void {
        expect(fn() => $barrier->awaitRelease(0))->toThrow(RuntimeException::class, 'startup deadline');
        $barrier->releaseIfReady();
        expect(is_file($directory . '/start'))->toBeFalse();
    }, 0.02);
});

it('rejects invalid worker identities without publishing readiness', function (int $worker): void {
    dblayerWithReleaseStartBarrier(function (ReleaseHostStartBarrier $barrier, string $directory) use ($worker): void {
        expect(fn() => $barrier->awaitRelease($worker))->toThrow(InvalidArgumentException::class);
        expect(glob($directory . '/*'))->toBe([]);
    });
})->with([-1, 2]);
