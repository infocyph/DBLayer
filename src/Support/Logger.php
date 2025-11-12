<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Support;

use Throwable;

/**
 * Query Logger
 *
 * Simple file-based logging for debugging database operations.
 * Provides query logging, error tracking, and timing information.
 *
 * @package Infocyph\DBLayer\Support
 * @author Hasan
 */
class Logger
{
    /**
     * Whether logging is enabled
     */
    private bool $enabled = false;

    /**
     * Log file path
     */
    private string $logFile;

    /**
     * Create a new logger instance
     *
     * @param string|null $logFile Log file path (default: system temp directory)
     */
    public function __construct(?string $logFile = null)
    {
        $this->logFile = $logFile ?? sys_get_temp_dir() . '/dblayer.log';
    }

    /**
     * Clear the log file
     *
     * @return void
     */
    public function clear(): void
    {
        if (file_exists($this->logFile)) {
            file_put_contents($this->logFile, '');
        }
    }

    /**
     * Disable logging
     *
     * @return void
     */
    public function disable(): void
    {
        $this->enabled = false;
    }

    /**
     * Enable logging
     *
     * @return void
     */
    public function enable(): void
    {
        $this->enabled = true;
    }

    /**
     * Log an error message
     *
     * @param string $message Error message
     * @param Throwable|null $exception Optional exception
     * @return void
     */
    public function error(string $message, ?Throwable $exception = null): void
    {
        if (!$this->enabled) {
            return;
        }

        $context = [
            'level' => 'ERROR',
            'message' => $message,
        ];

        if ($exception) {
            $context['exception'] = get_class($exception);
            $context['file'] = $exception->getFile();
            $context['line'] = $exception->getLine();
            $context['trace'] = $exception->getTraceAsString();
        }

        $this->write($context);
    }

    /**
     * Get the log file path
     *
     * @return string
     */
    public function getLogFile(): string
    {
        return $this->logFile;
    }

    /**
     * Check if logging is enabled
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Log a query execution
     *
     * @param string $sql SQL query
     * @param array<string, mixed> $bindings Query bindings
     * @param float $time Execution time in milliseconds
     * @return void
     */
    public function query(string $sql, array $bindings = [], float $time = 0.0): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->write([
            'level' => 'QUERY',
            'sql' => $sql,
            'bindings' => $bindings,
            'time' => round($time, 2) . 'ms',
        ]);
    }

    /**
     * Write log entry to file
     *
     * @param array<string, mixed> $context Log context
     * @return void
     */
    private function write(array $context): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $level = $context['level'] ?? 'INFO';
        unset($context['level']);

        $entry = sprintf(
            "[%s] %s: %s
",
            $timestamp,
            $level,
            json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        file_put_contents($this->logFile, $entry, FILE_APPEND);
    }
}
