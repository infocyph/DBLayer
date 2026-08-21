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

        yield from match ($this->getDriverName()) {
            'mysql', 'mariadb' => $this->mysqlUnbufferedStream($sql, $bindings, $fetchMode),
            'pgsql' => $this->postgresUnbufferedStream($sql, $bindings, $fetchMode, $fetchSize),
            'mssql', 'sqlite' => $this->stream($sql, $bindings, $fetchMode),
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
    private function mysqlUnbufferedStream(
        string $sql,
        array $bindings,
        ?int $fetchMode,
    ): Generator {
        $pdo = $this->getReadPdo();
        $attribute = Mysql::ATTR_USE_BUFFERED_QUERY;
        $wasBuffered = (bool) $pdo->getAttribute($attribute);
        $pdo->setAttribute($attribute, false);

        try {
            yield from $this->stream($sql, $bindings, $fetchMode);
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
    private function postgresUnbufferedStream(
        string $sql,
        array $bindings,
        ?int $fetchMode,
        int $fetchSize,
    ): Generator {
        $cursor = $this->nextPostgresStreamCursor();
        [$pdo, $ownsTransaction] = $this->declarePostgresStreamCursor($cursor, $sql, $bindings);

        if (!$pdo instanceof PDO) {
            return;
        }

        $failed = false;

        try {
            while (true) {
                $statement = $pdo->query("FETCH FORWARD {$fetchSize} FROM {$cursor}");
                if (!$statement instanceof PDOStatement) {
                    throw new PDOException('Unable to fetch from PostgreSQL server cursor.');
                }

                $rows = $this->fetchAllRows($statement, $fetchMode ?? $this->fetchMode);
                $statement->closeCursor();

                foreach ($rows as $row) {
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
            $this->closePostgresStreamCursor($pdo, $cursor, $ownsTransaction, $failed);
        }
    }
}
