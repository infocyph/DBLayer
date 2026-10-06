<?php

declare(strict_types=1);

/**
 * Linux operating-system measurements for the release harnesses, including native clients.
 */
final class ReleaseHostResources
{
    /** @return array{rss_bytes:int,sockets:int,pids:list<int>} */
    public static function snapshot(int $pid): array
    {
        $pids = [$pid];
        $rss = 0;
        $sockets = 0;
        for ($index = 0; $index < count($pids); $index++) {
            $current = $pids[$index];
            $residentRss = self::residentRss($current, $pid);
            if ($residentRss === null) {
                continue;
            }
            $rss += $residentRss;
            $sockets += self::socketCount($current);
            $childrenFile = '/proc/' . $current . '/task/' . $current . '/children';
            if (!is_readable($childrenFile)) {
                continue;
            }
            $children = file_get_contents($childrenFile);
            foreach (preg_split('/\s+/', trim((string) $children), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $child) {
                $childPid = (int) $child;
                if ($childPid > 0 && !in_array($childPid, $pids, true)) {
                    $pids[] = $childPid;
                }
            }
        }

        return ['rss_bytes' => $rss, 'sockets' => $sockets, 'pids' => $pids];
    }

    /**
     * @param list<int> $pids
     * @return array{worker_process_failures:int,peak_rss_bytes:int,resource_samples:int}
     */
    public static function waitForWorkers(array $pids): array
    {
        $failures = 0;
        $peakRss = 0;
        $samples = 0;
        while ($pids !== []) {
            $resources = self::snapshot(self::pid());
            $peakRss = max($peakRss, $resources['rss_bytes']);
            $samples++;
            foreach ($pids as $index => $pid) {
                $status = 0;
                $waited = pcntl_waitpid($pid, $status, WNOHANG);
                if ($waited === 0) {
                    continue;
                }
                unset($pids[$index]);
                if ($waited === -1 || !is_int($status) || !pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
                    $failures++;
                }
            }
            usleep(50_000);
        }

        return ['worker_process_failures' => $failures, 'peak_rss_bytes' => $peakRss, 'resource_samples' => $samples];
    }

    private static function residentRss(int $pid, int $root): ?int
    {
        $status = self::status($pid);
        if ($status !== null && preg_match('/^VmRSS:\s+(\d+)\s+kB$/m', $status, $matches) === 1) {
            return (int) $matches[1] * 1024;
        }
        // Linux can reclaim a child's memory before its status changes to zombie.
        if ($pid !== $root) {
            return null;
        }

        throw new RuntimeException('Missing live release-controller operating-system RSS.');
    }

    /** @return array{user_seconds:float,system_seconds:float} */
    public static function cpuTime(): array
    {
        $usage = getrusage();
        $values = [];
        foreach (['ru_utime.tv_sec', 'ru_utime.tv_usec', 'ru_stime.tv_sec', 'ru_stime.tv_usec'] as $key) {
            $value = $usage[$key] ?? null;
            if (!is_int($value)) {
                throw new RuntimeException('Missing operating-system CPU usage.');
            }
            $values[$key] = $value;
        }

        return [
            'user_seconds' => $values['ru_utime.tv_sec'] + $values['ru_utime.tv_usec'] / 1_000_000,
            'system_seconds' => $values['ru_stime.tv_sec'] + $values['ru_stime.tv_usec'] / 1_000_000,
        ];
    }

    public static function pid(): int
    {
        $pid = getmypid();
        if ($pid === false) {
            throw new RuntimeException('Unable to identify release host process.');
        }

        return $pid;
    }

    public static function peakRssBytes(): int
    {
        $status = self::status(self::pid());
        if ($status === null) {
            throw new RuntimeException('Release memory measurement requires readable Linux /proc status.');
        }

        return self::statusMetric($status, 'VmHWM');
    }

    private static function status(int $pid): ?string
    {
        $file = '/proc/' . $pid . '/status';
        if (!is_readable($file)) {
            return null;
        }
        $status = file_get_contents($file);

        return is_string($status) ? $status : null;
    }

    private static function statusMetric(string $status, string $metric): int
    {
        if (preg_match('/^' . $metric . ':\s+(\d+)\s+kB$/m', $status, $matches) !== 1) {
            throw new RuntimeException("Missing operating-system memory metric: {$metric}.");
        }

        return (int) $matches[1] * 1024;
    }

    private static function readSocketLink(string $file): string|false
    {
        // Descriptor disappearance is an expected race while observing a live worker.
        set_error_handler(static fn(int $severity, string $message): bool => $severity === E_WARNING
            && str_contains($message, 'readlink(): No such file or directory'));
        try {
            return readlink($file);
        } finally {
            restore_error_handler();
        }
    }

    private static function socketCount(int $pid): int
    {
        $directory = '/proc/' . $pid . '/fd';
        $count = 0;
        foreach (glob($directory . '/*') ?: [] as $file) {
            if (!is_link($file)) {
                continue;
            }
            $target = self::readSocketLink($file);
            if (is_string($target) && str_starts_with($target, 'socket:[')) {
                $count++;
            }
        }

        return $count;
    }
}
