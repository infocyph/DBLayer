<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Driver\SQLite;

use Infocyph\DBLayer\Driver\AbstractPdoDriver;
use Infocyph\DBLayer\Exceptions\ConnectionException;
use Infocyph\DBLayer\Exceptions\QueryException;
use PDO;
use PDOException;

/**
 * SQLite driver.
 *
 * Supports file-based and in-memory databases.
 */
final class SQLiteDriver extends AbstractPdoDriver
{
    protected const array CAPABILITIES = parent::CAPABILITIES_SQLITE;

    protected const string COMPILER_CLASS = SQLiteCompiler::class;

    protected const array DRIVER_DEFAULTS = [
        'database' => ':memory:',
    ];

    protected const string DRIVER_NAME = 'sqlite';

    #[\Override]
    public function applyStatementTimeout(PDO $pdo, int $timeoutMs): void
    {
        try {
            $pdo->exec('pragma busy_timeout = ' . max(0, $timeoutMs));
        } catch (PDOException) {
            // SQLite exposes lock-wait timeout only; DBLayer tracks execution budget.
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
        if ($analyze || $buffers || $verbose) {
            throw QueryException::invalidParameter(
                'explain',
                'SQLite supports query-plan inspection only; analyze, buffers, and verbose must be false.',
            );
        }

        return 'EXPLAIN QUERY PLAN ' . $sql;
    }

    /**
     * @param array<string,mixed> $config
     */
    #[\Override]
    public function validateConfig(array $config): void
    {
        $driver = $this->getName();
        $database = $config['database'] ?? null;

        if (!is_string($database) || $database === '') {
            throw ConnectionException::invalidConfiguration(
                $driver,
            );
        }

        $this->rejectUnsupportedSettings(
            $config,
            $driver,
            [
                'host',
                'port',
                'username',
                'password',
                'charset',
                'collation',
                'schema',
                'unix_socket',
                'sslmode',
                'ssl_ca',
                'ssl_cert',
                'ssl_key',
                'ssl_verify_server_cert',
                'read_session_read_only',
            ],
        );

        $security = $config['security'] ?? [];
        if (is_array($security) && ($security['require_tls'] ?? null) !== null) {
            throw ConnectionException::invalidConfiguration(
                "Security config key 'require_tls' is not supported by driver 'sqlite'.",
            );
        }

        // Optional: guard against accidentally passing a directory.
        if ($database !== ':memory:' && str_ends_with($database, DIRECTORY_SEPARATOR)) {
            throw ConnectionException::invalidConfiguration(
                $driver,
            );
        }
    }

    /**
     * Build the PDO DSN for SQLite.
     *
     * @param array<string,mixed> $config
     */
    #[\Override]
    protected function buildDsn(array $config, bool $readOnly): string
    {
        $database = $this->stringOrDefault($config['database'] ?? null, ':memory:');

        if ($database === ':memory:') {
            return 'sqlite::memory:';
        }

        // Keep a stable DSN target for both read and write handles.
        // Using "sqlite:<path>?mode=ro" is not portable across runtimes and
        // may resolve to a different file target, causing schema drift.
        return 'sqlite:' . $database;
    }
}
