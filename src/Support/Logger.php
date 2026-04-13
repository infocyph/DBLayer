<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Support;

use Psr\Log\LoggerInterface as PsrLoggerInterface;
use Psr\Log\LogLevel;
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
    public function __construct(?string $logFile = null, private ?PsrLoggerInterface $psrLogger = null)
    {
        $this->logFile = $logFile ?? $this->defaultLogFile();
    }

    /**
     * Clear the log file.
     */
    public function clear(): void
    {
        if (is_file($this->logFile) && ! is_link($this->logFile)) {
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
     * Get configured PSR-3 logger backend.
     */
    public function getPsrLogger(): ?PsrLoggerInterface
    {
        return $this->psrLogger;
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
     * Set PSR-3 logger backend.
     */
    public function setPsrLogger(?PsrLoggerInterface $logger): void
    {
        $this->psrLogger = $logger;
    }

    /**
     * Control whether binding values are redacted in query logs.
     */
    public function setRedactBindings(bool $redact): void
    {
        $this->redactBindings = $redact;
    }

    private function defaultLogFile(): string
    {
        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'dblayer'
            . DIRECTORY_SEPARATOR
            . 'dblayer.log';
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function defaultMessageForLevel(string $level): string
    {
        return match ($level) {
            'ERROR' => 'db.error',
            'QUERY' => 'db.query.executed',
            default => 'db.log',
        };
    }

    private function isSafeLogTarget(): bool
    {
        $directory = dirname($this->logFile);
        if ($directory === '' || is_link($directory)) {
            return false;
        }

        if (! is_dir($directory) && ! @mkdir($directory, 0o700, true) && ! is_dir($directory)) {
            return false;
        }

        if (! is_writable($directory)) {
            return false;
        }

        if (is_link($this->logFile)) {
            return false;
        }

        if (is_file($this->logFile) && ! is_writable($this->logFile)) {
            return false;
        }

        return true;
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

        return array_map($this->redactBindingValue(...), $bindings);
    }

    private function normalizePsrLevel(string $level): string
    {
        return match ($level) {
            'DEBUG' => LogLevel::DEBUG,
            'NOTICE' => LogLevel::NOTICE,
            'WARNING' => LogLevel::WARNING,
            'ERROR' => LogLevel::ERROR,
            'CRITICAL' => LogLevel::CRITICAL,
            'ALERT' => LogLevel::ALERT,
            'EMERGENCY' => LogLevel::EMERGENCY,
            default => LogLevel::INFO,
        };
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
        $level = strtoupper((string) ($context['level'] ?? 'INFO'));
        unset($context['level']);

        $message = (string) ($context['message'] ?? $this->defaultMessageForLevel($level));
        unset($context['message']);

        $this->writeToPsrLogger($level, $message, $context);
        $this->writeToFile($level, $message, $context);
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function writeToFile(string $level, string $message, array $context): void
    {
        if (! $this->isSafeLogTarget()) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $payload = $context;
        $payload['message'] = $message;

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        $entry = sprintf("[%s] %s: %s\n", $timestamp, $level, $json);
        $isNewFile = ! is_file($this->logFile);

        if (@file_put_contents($this->logFile, $entry, FILE_APPEND | LOCK_EX) === false) {
            return;
        }

        if ($isNewFile) {
            @chmod($this->logFile, 0o600);
        }
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function writeToPsrLogger(string $level, string $message, array $context): void
    {
        if ($this->psrLogger === null) {
            return;
        }

        $this->psrLogger->log($this->normalizePsrLevel($level), $message, $context);
    }
}
