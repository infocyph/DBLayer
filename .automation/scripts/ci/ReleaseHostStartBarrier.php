<?php

declare(strict_types=1);

/** Synchronizes initialized workers before their full measured workload window. */
final class ReleaseHostStartBarrier
{
    private readonly float $deadline;
    private bool $released = false;

    public function __construct(
        private readonly string $directory,
        private readonly int $workers,
        float $timeoutSeconds = 30.0,
    ) {
        if ($workers < 1 || !is_finite($timeoutSeconds) || $timeoutSeconds <= 0.0) {
            throw new InvalidArgumentException('Worker readiness requires a positive count and finite timeout.');
        }
        $this->deadline = microtime(true) + $timeoutSeconds;
    }

    public function awaitRelease(int $worker): void
    {
        if ($worker < 0 || $worker >= $this->workers) {
            throw new InvalidArgumentException('Invalid release worker identity.');
        }
        if (file_put_contents($this->readyFile($worker), 'ready') === false) {
            throw new RuntimeException('Unable to publish release worker readiness.');
        }
        while (true) {
            clearstatcache(true, $this->startFile());
            if (is_file($this->startFile())) {
                return;
            }
            if (microtime(true) >= $this->deadline) {
                throw new RuntimeException('Release workers did not become ready before the startup deadline.');
            }
            usleep(1_000);
        }
    }

    public function releaseIfReady(): void
    {
        if ($this->released) {
            return;
        }
        for ($worker = 0; $worker < $this->workers; $worker++) {
            $file = $this->readyFile($worker);
            clearstatcache(true, $file);
            if (!is_file($file)) {
                return;
            }
        }
        if (file_put_contents($this->startFile(), 'start') === false) {
            throw new RuntimeException('Unable to release initialized workload workers.');
        }
        $this->released = true;
    }

    private function readyFile(int $worker): string
    {
        return $this->directory . '/worker-' . $worker . '.ready';
    }

    private function startFile(): string
    {
        return $this->directory . '/start';
    }
}
