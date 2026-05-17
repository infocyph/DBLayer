<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Events\DatabaseEvents;

use Infocyph\DBLayer\Connection\Connection;
use Throwable;

/**
 * Query Failed Event
 *
 * Dispatched after a query attempt fails permanently.
 */
final readonly class QueryFailed
{
    public string $error;

    public string $exceptionClass;

    public string $fingerprint;

    public string $statement;

    /**
     * @param array<int|string,mixed> $bindings
     */
    public function __construct(
        public string $sql,
        public array $bindings,
        /**
         * Execution time in milliseconds.
         */
        public float $time,
        public Connection $connection,
        public Throwable $exception,
        /**
         * Retry attempts consumed before failure.
         */
        public int $attempts = 1,
    ) {
        $this->error = $exception->getMessage();
        $this->exceptionClass = $exception::class;
        $this->statement = self::statementFromSql($sql);
        $this->fingerprint = self::fingerprintFromSql($sql);
    }

    /**
     * Get event data as array.
     *
     * @return array{
     *   sql:string,
     *   bindings:array<int|string,mixed>,
     *   time:float,
     *   connection:string,
     *   attempts:int,
     *   error:string,
     *   exception:string,
     *   statement:string,
     *   fingerprint:string
     * }
     */
    public function toArray(): array
    {
        return [
            'sql' => $this->sql,
            'bindings' => $this->bindings,
            'time' => $this->time,
            'connection' => $this->connection->getDriverName(),
            'attempts' => $this->attempts,
            'error' => $this->error,
            'exception' => $this->exceptionClass,
            'statement' => $this->statement,
            'fingerprint' => $this->fingerprint,
        ];
    }

    private static function fingerprintFromSql(string $sql): string
    {
        $normalized = strtolower(trim((string) (preg_replace('/\s+/', ' ', $sql) ?? $sql)));

        return substr(hash('sha256', $normalized), 0, 16);
    }

    private static function statementFromSql(string $sql): string
    {
        $trimmed = ltrim($sql);

        return strtoupper(substr($trimmed, 0, strcspn($trimmed, " \t\n\r")));
    }
}
