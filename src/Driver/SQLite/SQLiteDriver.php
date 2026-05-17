<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Driver\SQLite;

use Infocyph\DBLayer\Driver\AbstractPdoDriver;
use Infocyph\DBLayer\Exceptions\ConnectionException;

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
        unset($readOnly);

        return 'sqlite:' . $database;
    }
}
