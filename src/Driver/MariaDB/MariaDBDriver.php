<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Driver\MariaDB;

use Infocyph\DBLayer\Driver\MySQLFamily\AbstractMySqlFamilyDriver;
use Infocyph\DBLayer\Exceptions\QueryException;
use PDO;
use PDOException;

/**
 * MariaDB driver with vendor-native timeout, EXPLAIN and RETURNING semantics.
 */
final class MariaDBDriver extends AbstractMySqlFamilyDriver
{
    protected const array CAPABILITIES = [
        'supportsReturning' => true,
        'supportsInsertIgnore' => true,
        'supportsUpsert' => true,
        'supportsSavepoints' => true,
        'supportsSchemas' => true,
        'supportsJson' => true,
        'supportsWindowFunctions' => true,
    ];

    protected const string COMPILER_CLASS = MariaDBCompiler::class;

    protected const string DRIVER_NAME = 'mariadb';

    #[\Override]
    public function applyStatementTimeout(PDO $pdo, int $timeoutMs): void
    {
        $seconds = number_format(max(0.0, $timeoutMs / 1_000.0), 3, '.', '');

        try {
            $pdo->exec('set session max_statement_time = ' . $seconds);
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
        unset($serverVersion);

        if ($buffers || $verbose) {
            throw QueryException::invalidParameter(
                'explain',
                'MariaDB supports analyze, but not PostgreSQL buffers or verbose options.',
            );
        }

        return $analyze ? 'ANALYZE FORMAT=JSON ' . $sql : 'EXPLAIN FORMAT=JSON ' . $sql;
    }
}
