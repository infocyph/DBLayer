<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Driver\PostgreSQL;

use Infocyph\DBLayer\Driver\AbstractPdoDriver;

/**
 * PostgreSQL driver.
 */
final class PostgreSQLDriver extends AbstractPdoDriver
{
    protected const array CAPABILITIES = parent::CAPABILITIES_POSTGRES;

    protected const string COMPILER_CLASS = PostgreSQLCompiler::class;

    protected const array DRIVER_DEFAULTS = [
        'port' => 5432,
    ];

    protected const string DRIVER_NAME = 'pgsql';

    protected const array NETWORK_OPTIONAL_STRINGS = ['schema'];

    protected const array NETWORK_REQUIRED = ['database', 'host'];

    protected const ?string TLS_REQUIREMENT_MESSAGE = 'Driver [{driver}] requires sslmode=require|verify-ca|verify-full in this environment.';

    /**
     * Build the PDO DSN for PostgreSQL.
     *
     * @param array<string,mixed> $config
     */
    #[\Override]
    protected function buildDsn(array $config, bool $readOnly): string
    {
        unset($readOnly); // handled at transaction-level

        $database = $this->stringOrDefault($config['database'] ?? null, '');
        $host = $this->stringOrDefault($config['host'] ?? null, '127.0.0.1');
        $port = $this->intOrDefault($config['port'] ?? null, 5432);

        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $host,
            $port,
            $database,
        );

        // Allow sslmode to be passed (if provided).
        if (!empty($config['sslmode'])) {
            $sslmode = $this->stringOrDefault($config['sslmode'], '');
            $dsn .= ';sslmode=' . $sslmode;
        }

        return $dsn;
    }

    /**
     * @param array<string,mixed> $config
     */
    #[\Override]
    protected function hasRequiredTlsConfiguration(array $config): bool
    {
        return $this->hasTlsConfiguration($config);
    }

    /**
     * Whether TLS config satisfies required transport policy.
     *
     * @param array<string,mixed> $config
     */
    private function hasTlsConfiguration(array $config): bool
    {
        $sslMode = $config['sslmode'] ?? null;

        if (!is_string($sslMode)) {
            return false;
        }

        $normalized = strtolower(trim($sslMode));

        return \in_array($normalized, ['require', 'verify-ca', 'verify-full'], true);
    }
}
