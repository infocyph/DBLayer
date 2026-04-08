<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Driver\PostgreSQL;

use Infocyph\DBLayer\Driver\AbstractPdoDriver;
use Infocyph\DBLayer\Driver\Contracts\QueryCompilerInterface;
use Infocyph\DBLayer\Driver\Support\Capabilities;
use Infocyph\DBLayer\Exceptions\ConnectionException;

/**
 * PostgreSQL driver.
 */
final class PostgreSQLDriver extends AbstractPdoDriver
{
    #[\Override]
    public function createCompiler(): QueryCompilerInterface
    {
        return new PostgreSQLCompiler();
    }

    #[\Override]
    public function getCapabilities(): Capabilities
    {
        return new Capabilities(
            supportsReturning: true,
            supportsInsertIgnore: false, // handled via ON CONFLICT DO NOTHING
            supportsUpsert: true,
            supportsSavepoints: true,
            supportsSchemas: true,
            supportsJson: true,
            supportsWindowFunctions: true,
        );
    }

    #[\Override]
    public function getName(): string
    {
        return 'pgsql';
    }

    /**
     * Merge driver-specific defaults (port).
     *
     * @param  array<string,mixed>  $config
     * @return array<string,mixed>
     */
    #[\Override]
    public function mergeDefaults(array $config): array
    {
        $config = parent::mergeDefaults($config);

        $config['port'] ??= 5432;

        return $config;
    }

    /**
     * @param  array<string,mixed>  $config
     */
    #[\Override]
    public function validateConfig(array $config): void
    {
        $driver = $this->getName();

        $database = $config['database'] ?? null;
        $host = $config['host'] ?? null;

        if (! is_string($database) || $database === '') {
            throw ConnectionException::invalidConfiguration(
                $driver,
                'Missing or empty "database" for PostgreSQL connection.',
            );
        }

        if (! is_string($host) || $host === '') {
            throw ConnectionException::invalidConfiguration(
                $driver,
                'Missing or empty "host" for PostgreSQL connection.',
            );
        }

        if (isset($config['port']) && $config['port'] !== null) {
            if (! is_int($config['port']) && ! ctype_digit((string) $config['port'])) {
                throw ConnectionException::invalidConfiguration(
                    $driver,
                    '"port" must be an integer for PostgreSQL connection.',
                );
            }
        }

        if (isset($config['schema']) && ! is_string($config['schema'])) {
            throw ConnectionException::invalidConfiguration(
                $driver,
                '"schema" must be a string for PostgreSQL connection.',
            );
        }
    }

    /**
     * Build the PDO DSN for PostgreSQL.
     *
     * @param  array<string,mixed>  $config
     */
    #[\Override]
    protected function buildDsn(array $config, bool $readOnly): string
    {
        unset($readOnly); // handled at transaction-level

        $database = (string) ($config['database'] ?? '');
        $host = (string) ($config['host'] ?? '127.0.0.1');
        $port = (int) ($config['port'] ?? 5432);

        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $host,
            $port,
            $database,
        );

        // Allow sslmode to be passed (if provided).
        if (! empty($config['sslmode'])) {
            $sslmode = (string) $config['sslmode'];
            $dsn .= ';sslmode=' . $sslmode;
        }

        return $dsn;
    }
}
