<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Driver\MySQL;

use Infocyph\DBLayer\Driver\MySQLFamily\AbstractMySqlFamilyDriver;
use Infocyph\DBLayer\Exceptions\QueryException;
use PDO;
use PDOException;

/**
 * MySQL driver. MariaDB is intentionally handled by its own driver pathway.
 */
final class MySQLDriver extends AbstractMySqlFamilyDriver
{
    protected const array CAPABILITIES = parent::CAPABILITIES_MODERN_NETWORK;

    protected const string COMPILER_CLASS = MySQLCompiler::class;

    protected const string DRIVER_NAME = 'mysql';

    #[\Override]
    public function applyStatementTimeout(PDO $pdo, int $timeoutMs): void
    {
        try {
            $pdo->exec('set session max_execution_time = ' . max(0, $timeoutMs));
        } catch (PDOException) {
            // Native enforcement is best effort; DBLayer still tracks its budget.
        }
    }

    #[\Override]
    public function compileExplain(
        string $sql,
        bool $analyze = false,
        bool $buffers = false,
        bool $verbose = false,
        ?string $serverVersion = null,
    ): string {
        if ($buffers || $verbose) {
            throw QueryException::invalidParameter(
                'explain',
                'MySQL supports analyze, but not PostgreSQL buffers or verbose options.',
            );
        }

        return $analyze ? 'EXPLAIN ANALYZE ' . $sql : 'EXPLAIN FORMAT=JSON ' . $sql;
    }
}
