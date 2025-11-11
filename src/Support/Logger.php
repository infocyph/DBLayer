<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Support;

/**
 * Query Logger
 *
 * Simple query logging for debugging:
 * - Query logging
 * - Error logging
 * - File-based output
 *
 * @package Infocyph\DBLayer\Support
 * @author Hasan
 */
class Logger
{
    private bool $enabled = false;
    private ?string $logFile = null;

    public function __construct(?string $logFile = null)
    {
        $this->logFile = $logFile ?? sys_get_temp_dir() . '/dblayer.log';
    }

    public function clear(): void
    {
        if (file_exists($this->logFile)) {
            file_put_contents($this->logFile, '');
        }
    }

    public function disable(): void
    {
        $this->enabled = false;
    }

    public function enable(): void
    {
        $this->enabled = true;
    }

    public function error(string $message, ?\Throwable $exception = null): void
    {
        $context = ['message' => $message];

        if ($exception) {
            $context['exception'] = get_class($exception);
            $context['error'] = $exception->getMessage();
            $context['file'] = $exception->getFile();
            $context['line'] = $exception->getLine();
        }

        $this->log('ERROR', $context);
    }

    public function getLogFile(): string
    {
        return $this->logFile;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function log(string $message, array $context = []): void
    {
        if (!$this->enabled) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' ' . json_encode($context) : '';
        $line = "[{$timestamp}] {$message}{$contextStr}" . PHP_EOL;

        file_put_contents($this->logFile, $line, FILE_APPEND);
    }

    public function query(string $sql, array $bindings = [], float $duration = 0): void
    {
        $this->log('QUERY', [
            'sql' => $sql,
            'bindings' => $bindings,
            'duration' => round($duration * 1000, 2) . 'ms',
        ]);
    }
}
