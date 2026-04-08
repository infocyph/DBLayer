<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Driver\MySQL;

use Infocyph\DBLayer\Driver\AbstractPdoDriver;
use Infocyph\DBLayer\Driver\Contracts\QueryCompilerInterface;
use Infocyph\DBLayer\Driver\Support\Capabilities;
use Infocyph\DBLayer\Exceptions\ConnectionException;

/**
 * MySQL / MariaDB driver.
 */
final class MySQLDriver extends AbstractPdoDriver
{
    public function createCompiler(): QueryCompilerInterface
    {
        return new MySQLCompiler();
    }

    public function getCapabilities(): Capabilities
    {
        // We assume modern MySQL (8.x) / MariaDB with JSON + window functions.
        return new Capabilities(
            supportsReturning: false,
            supportsInsertIgnore: true,
            supportsUpsert: true,
            supportsSavepoints: true,
            supportsSchemas: true,
            supportsJson: true,
            supportsWindowFunctions: true,
        );
    }

    public function getName(): string
    {
        return 'mysql';
    }

    /**
     * Merge driver-specific defaults (port, charset, collation).
     *
     * @param  array<string,mixed>  $config
     * @return array<string,mixed>
     */
    public function mergeDefaults(array $config): array
    {
        $config = parent::mergeDefaults($config);

        $config['port'] ??= 3306;
        $config['charset'] ??= 'utf8mb4';
        $config['collation'] ??= 'utf8mb4_unicode_ci';

        return $config;
    }

    /**
     * @param  array<string,mixed>  $config
     */
    public function validateConfig(array $config): void
    {
        $driver = $this->getName();

        $database = $config['database'] ?? null;
        $host = $config['host'] ?? null;
        $socket = $config['unix_socket'] ?? null;

        if (! is_string($database) || $database === '') {
            throw ConnectionException::invalidConfiguration(
                $driver,
                'Missing or empty "database" for MySQL connection.',
            );
        }

        // Either host OR unix_socket must be provided.
        if (
            (! is_string($host) || $host === '')
            && (! is_string($socket) || $socket === '')
        ) {
            throw ConnectionException::invalidConfiguration(
                $driver,
                'MySQL connection requires either a non-empty "host" or "unix_socket".',
            );
        }

        if (isset($config['port'])
          && ! is_int($config['port'])
          && ! ctype_digit((string) $config['port'])) {
            throw ConnectionException::invalidConfiguration(
                $driver,
                '"port" must be an integer for MySQL connection.',
            );
        }

        if (isset($config['charset']) && ! is_string($config['charset'])) {
            throw ConnectionException::invalidConfiguration(
                $driver,
                '"charset" must be a string for MySQL connection.',
            );
        }
    }

    /**
     * Build the PDO DSN for MySQL / MariaDB.
     *
     * @param  array<string,mixed>  $config
     */
    protected function buildDsn(array $config, bool $readOnly): string
    {
        unset($readOnly); // handled via transaction semantics, not DSN

        $database = (string) ($config['database'] ?? '');
        $charset = (string) ($config['charset'] ?? 'utf8mb4');

        // Prefer unix socket when provided.
        if (! empty($config['unix_socket'])) {
            $socket = (string) $config['unix_socket'];

            return sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $socket, $database, $charset);
        }

        $host = (string) ($config['host'] ?? '127.0.0.1');
        $port = (int) ($config['port'] ?? 3306);

        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $host,
            $port,
            $database,
            $charset,
        );
    }
}
