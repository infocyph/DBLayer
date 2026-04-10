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
 * Intentionally minimal and opt-in; disabled by default.
 */
final class Logger
{
    private readonly string $logFile;

    private bool $enabled = false;

    /**
     * Whether to redact binding values in logs.
     */
    private bool $redactBindings = true;

    /**
     * Create a new logger instance.
     *
     * @param string|null $logFile Log file path (default: system temp directory)
     */
    public function __construct(?string $logFile = null)
    {
        $this->logFile = $logFile
          ?? (rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'dblayer.log');
    }

    /**
     * Clear the log file.
     */
    public function clear(): void
    {
        if (is_file($this->logFile)) {
            @unlink($this->logFile);
        }
    }

    /**
     * Disable logging.
     */
    public function disable(): void
    {
        $this->enabled = false;
    }

    /**
     * Enable logging.
     */
    public function enable(): void
    {
        $this->enabled = true;
    }

    /**
     * Log an error message.
     */
    public function error(string $message, ?Throwable $exception = null): void
    {
        if (! $this->enabled) {
            return;
        }

        $context = [
            'level'   => 'ERROR',
            'message' => $message,
        ];

        if ($exception !== null) {
            $context['exception'] = $exception::class;
            $context['file']      = $exception->getFile();
            $context['line']      = $exception->getLine();
            $context['trace']     = $exception->getTraceAsString();
        }

        $this->write($context);
    }

    /**
     * Get log file path.
     */
    public function getLogFile(): string
    {
        return $this->logFile;
    }

    /**
     * Check if logging is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Log a query execution.
     *
     * @param string                   $sql      The SQL statement
     * @param array<int|string, mixed> $bindings Bound parameters
     * @param float                    $time     Execution time in milliseconds
     */
    public function query(string $sql, array $bindings = [], float $time = 0.0): void
    {
        if (! $this->enabled) {
            return;
        }

        $context = [
            'level' => 'QUERY',
            'sql'   => $sql,
        ];

        if ($bindings !== []) {
            $context['bindings'] = $this->normalizeBindingsForLog($bindings);
        }

        if ($time > 0.0) {
            $context['time_ms'] = round($time, 2);
        }

        $this->write($context);
    }

    /**
     * Control whether binding values are redacted in query logs.
     */
    public function setRedactBindings(bool $redact): void
    {
        $this->redactBindings = $redact;
    }

    /**
     * Normalize bindings before writing them to logs.
     *
     * @param  array<int|string,mixed>  $bindings
     * @return array<int|string,mixed>
     */
    private function normalizeBindingsForLog(array $bindings): array
    {
        if (! $this->redactBindings) {
            return $bindings;
        }

        $sanitized = [];

        foreach ($bindings as $key => $value) {
            $sanitized[$key] = $this->redactBindingValue($value);
        }

        return $sanitized;
    }

    /**
     * Redact one binding value while preserving debugging shape.
     */
    private function redactBindingValue(mixed $value): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_resource($value)) {
            return '[resource]';
        }

        if (is_string($value)) {
            return '[redacted:string:' . strlen($value) . ']';
        }

        if ($value instanceof \DateTimeInterface) {
            return '[datetime]';
        }

        if (is_array($value)) {
            return '[array:' . count($value) . ']';
        }

        if (is_object($value)) {
            return '[object:' . $value::class . ']';
        }

        return '[redacted]';
    }

    /**
     * Write a structured log entry to the log file.
     *
     * @param array<string, mixed> $context
     */
    private function write(array $context): void
    {
        $timestamp = date('Y-m-d H:i:s');

        $level = $context['level'] ?? 'INFO';
        unset($context['level']);

        $json = json_encode(
            $context,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) ?: '{}';

        $entry = sprintf(
            "[%s] %s: %s\n",
            $timestamp,
            $level,
            $json,
        );

        @file_put_contents($this->logFile, $entry, FILE_APPEND | LOCK_EX);
    }
}
