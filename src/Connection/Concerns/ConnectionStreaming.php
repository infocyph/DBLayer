<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Connection\Concerns;

use Generator;
use Infocyph\DBLayer\Exceptions\ConnectionException;
use Infocyph\DBLayer\Exceptions\QueryException;
use PDO;
use Pdo\Mysql;
use PDOException;
use PDOStatement;
use Throwable;

/**
 * Driver-specific streaming implementations with explicit memory guarantees.
 */
trait ConnectionStreaming
{
    use ConnectionMonitoring;

    private int $postgresStreamCursorSequence = 0;

    /**
     * Stream without a full client-side result buffer where the driver supports it.
     *
     * MySQL and MariaDB temporarily disable PDO-MySQL buffering. PostgreSQL uses
     * a transaction-scoped server cursor. SQLite delegates to incremental fetch().
     * Microsoft SQL Server uses PDO_SQLSRV's default forward-only cursor, which
     * fetches rows incrementally without a client-side result buffer.
     *
     * @param array<int|string,mixed> $bindings
     * @return Generator<mixed>
     */
    public function unbufferedStream(
        string $sql,
        array $bindings = [],
        ?int $fetchMode = null,
        int $fetchSize = 1000,
    ): Generator {
        if ($fetchSize <= 0) {
            throw QueryException::invalidLimit($fetchSize);
        }

        $runwireBinding = $this->runwireBinding();
        $reuseGeneration = $this->runtimeReuseGeneration;

        return match ($this->getDriverName()) {
            'mysql', 'mariadb' => $this->mysqlUnbufferedStream(
                $sql,
                $bindings,
                $fetchMode,
                $runwireBinding,
                $reuseGeneration,
            ),
            'pgsql' => $this->postgresUnbufferedStream(
                $sql,
                $bindings,
                $fetchMode,
                $fetchSize,
                $runwireBinding,
                $reuseGeneration,
            ),
            'mssql', 'sqlite' => $this->streamGenerator(
                $sql,
                $bindings,
                $fetchMode,
                $runwireBinding,
                $reuseGeneration,
            ),
            default => throw ConnectionException::invalidConfiguration(
                "Driver [{$this->getDriverName()}] has no declared unbuffered streaming strategy.",
            ),
        };
    }

    private function closePostgresStreamCursor(
        PDO $pdo,
        string $cursor,
        bool $ownsTransaction,
        bool $failed,
    ): void {
        if ($pdo->inTransaction()) {
            try {
                $pdo->exec("CLOSE {$cursor}");
            } catch (PDOException) {
                $failed = true;
            }
        }

        if (!$ownsTransaction || !$pdo->inTransaction()) {
            return;
        }

        if ($failed) {
            $pdo->rollBack();

            return;
        }

        $pdo->commit();
    }

    /**
     * @param array<int|string,mixed> $bindings
     * @return array{0:PDO|null,1:bool}
     */
    private function declarePostgresStreamCursor(string $cursor, string $sql, array $bindings): array
    {
        $finalSql = $this->prepareSqlForExecution($sql, $bindings);
        $declareSql = "DECLARE {$cursor} NO SCROLL CURSOR FOR {$finalSql}";

        return $this->executeWithRetry(
            $sql,
            $bindings,
            false,
            function (PDO $pdo) use ($declareSql, $bindings): array {
                $ownsTransaction = !$pdo->inTransaction();

                if ($ownsTransaction && !$pdo->beginTransaction()) {
                    throw new PDOException('Unable to start PostgreSQL cursor transaction.');
                }

                try {
                    $statement = $pdo->prepare($declareSql);
                    if (!$statement instanceof PDOStatement) {
                        throw new PDOException('Unable to prepare PostgreSQL server cursor.');
                    }

                    $this->executePreparedStatement($statement, $bindings)->closeCursor();
                } catch (Throwable $exception) {
                    if ($ownsTransaction) {
                        try {
                            $pdo->rollBack();
                        } catch (Throwable) {
                            // Preserve the declaration failure that triggered cleanup.
                        }
                    }

                    throw $exception;
                }

                return [$pdo, $ownsTransaction];
            },
            static fn(): array => [null, false],
        );
    }

    /**
     * @param array<int|string,mixed> $bindings
     * @return Generator<mixed>
     */
    /**
     * @param array{
     *   runtime:\Infocyph\Runwire\RuntimeContext,
     *   request:?\Infocyph\Runwire\RequestContext,
     *   scope:?\Infocyph\Runwire\Coroutine\CoroutineScope
     * }|null $runwireBinding
     * @param array<int|string,mixed> $bindings
     * @return Generator<mixed>
     */
    private function mysqlUnbufferedStream(
        string $sql,
        array $bindings,
        ?int $fetchMode,
        ?array $runwireBinding,
        int $reuseGeneration,
    ): Generator {
        $this->assertStreamGeneration($reuseGeneration);
        $pdo = $this->runWithRunwireBinding(
            $runwireBinding,
            fn(): PDO => $this->getReadPdo(),
        );
        $attribute = Mysql::ATTR_USE_BUFFERED_QUERY;
        $wasBuffered = (bool) $pdo->getAttribute($attribute);
        $pdo->setAttribute($attribute, false);

        try {
            yield from $this->streamGenerator(
                $sql,
                $bindings,
                $fetchMode,
                $runwireBinding,
                $reuseGeneration,
            );
        } finally {
            $pdo->setAttribute($attribute, $wasBuffered);
        }
    }

    private function nextPostgresStreamCursor(): string
    {
        $this->postgresStreamCursorSequence++;

        return 'dblayer_stream_' . $this->postgresStreamCursorSequence;
    }

    /**
     * @param array<int|string,mixed> $bindings
     * @return Generator<mixed>
     */
    /**
     * @param array{
     *   runtime:\Infocyph\Runwire\RuntimeContext,
     *   request:?\Infocyph\Runwire\RequestContext,
     *   scope:?\Infocyph\Runwire\Coroutine\CoroutineScope
     * }|null $runwireBinding
     * @param array<int|string,mixed> $bindings
     * @return Generator<mixed>
     */
    private function postgresUnbufferedStream(
        string $sql,
        array $bindings,
        ?int $fetchMode,
        int $fetchSize,
        ?array $runwireBinding,
        int $reuseGeneration,
    ): Generator {
        $cursor = $this->nextPostgresStreamCursor();
        $startedAt = microtime(true);
        $pdo = null;
        $ownsTransaction = false;
        $failed = false;
        $this->activeStreamIterators++;

        try {
            $this->assertStreamGeneration($reuseGeneration);
            [$pdo, $ownsTransaction] = $this->runWithRunwireBinding(
                $runwireBinding,
                fn(): array => $this->declarePostgresStreamCursor($cursor, $sql, $bindings),
            );

            if (!$pdo instanceof PDO) {
                return;
            }

            while (true) {
                $this->assertStreamGeneration($reuseGeneration);
                $rows = $this->runWithRunwireBinding(
                    $runwireBinding,
                    function () use ($pdo, $cursor, $fetchSize, $fetchMode, $startedAt): array {
                        $this->assertQueryCheckpoint($startedAt);
                        $statement = $pdo->query("FETCH FORWARD {$fetchSize} FROM {$cursor}");
                        if (!$statement instanceof PDOStatement) {
                            throw new PDOException('Unable to fetch from PostgreSQL server cursor.');
                        }

                        $rows = $this->fetchAllRows($statement, $fetchMode ?? $this->fetchMode);
                        $statement->closeCursor();
                        $this->assertQueryCheckpoint($startedAt);

                        return $rows;
                    },
                );

                foreach ($rows as $row) {
                    $this->assertStreamGeneration($reuseGeneration);
                    $this->runWithRunwireBinding(
                        $runwireBinding,
                        function () use ($startedAt): void {
                            $this->assertQueryCheckpoint($startedAt);
                        },
                    );

                    yield $row;
                }

                if (\count($rows) < $fetchSize) {
                    break;
                }
            }
        } catch (PDOException $exception) {
            $failed = true;

            throw ConnectionException::queryFailed($sql, $exception->getMessage());
        } finally {
            if ($pdo instanceof PDO) {
                $this->closePostgresStreamCursor($pdo, $cursor, $ownsTransaction, $failed);
            }

            $this->activeStreamIterators = max(0, $this->activeStreamIterators - 1);
        }
    }
}
