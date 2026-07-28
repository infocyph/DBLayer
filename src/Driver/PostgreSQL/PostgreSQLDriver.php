<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Driver\PostgreSQL;

use Infocyph\DBLayer\Driver\AbstractPdoDriver;
use Infocyph\DBLayer\Exceptions\QueryException;

/**
 * PostgreSQL driver.
 */
final class PostgreSQLDriver extends AbstractPdoDriver
{
    protected const array CAPABILITIES = parent::CAPABILITIES_POSTGRES;

    protected const string COMPILER_CLASS = PostgreSQLCompiler::class;

    protected const array DRIVER_DEFAULTS = [
        'host' => '127.0.0.1',
        'port' => 5432,
        'charset' => 'utf8',
        'schema' => 'public',
    ];

    protected const string DRIVER_NAME = 'pgsql';

    protected const array NETWORK_OPTIONAL_STRINGS = ['schema'];

    protected const array NETWORK_REQUIRED = ['database', 'host'];

    protected const ?string TLS_REQUIREMENT_MESSAGE = 'Driver [{driver}] requires sslmode=require|verify-ca|verify-full in this environment.';

    /**
     * @var list<string>
     */
    private const array SSL_MODES = [
        'disable',
        'allow',
        'prefer',
        'require',
        'verify-ca',
        'verify-full',
    ];

    #[\Override]
    public function compileExplain(
        string $sql,
        bool $analyze = false,
        bool $buffers = false,
        bool $verbose = false,
        ?string $serverVersion = null,
    ): string {
        unset($serverVersion);

        if ($buffers && !$analyze) {
            throw QueryException::invalidParameter(
                'buffers',
                'PostgreSQL BUFFERS requires analyze=true.',
            );
        }

        $options = ['FORMAT JSON'];

        if ($analyze) {
            $options[] = 'ANALYZE TRUE';
        }

        if ($buffers) {
            $options[] = 'BUFFERS TRUE';
        }

        if ($verbose) {
            $options[] = 'VERBOSE TRUE';
        }

        return 'EXPLAIN (' . implode(', ', $options) . ') ' . $sql;
    }

    /**
     * @param array<string,mixed> $config
     */
    #[\Override]
    public function validateConfig(array $config): void
    {
        parent::validateConfig($config);

        $driver = $this->getName();
        $this->rejectUnsupportedSettings(
            $config,
            $driver,
            ['collation', 'unix_socket', 'ssl_ca', 'ssl_cert', 'ssl_key', 'ssl_verify_server_cert'],
        );
        $this->requireOptionalTokenSetting($config, 'charset', $driver);
        $this->requireOptionalTokenSetting(
            $config,
            'schema',
            $driver,
            '/^[A-Za-z_][A-Za-z0-9_$]*$/D',
        );

        $sslMode = $config['sslmode'] ?? null;
        if (
            $sslMode !== null
            && (!is_string($sslMode) || !in_array($sslMode, self::SSL_MODES, true))
        ) {
            $this->throwInvalidConfiguration(
                $driver,
                sprintf(
                    "Config key 'sslmode' must be one of: %s.",
                    implode(', ', self::SSL_MODES),
                ),
            );
        }
    }

    /**
     * libpq consumes connection timeout from the DSN.
     *
     * @param array<int,mixed> $options
     * @param array<string,mixed> $config
     * @return array<int,mixed>
     */
    #[\Override]
    protected function applyDerivedOptions(array $options, array $config): array
    {
        unset($config['timeout']);

        return parent::applyDerivedOptions($options, $config);
    }

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
        $dsn = [
            sprintf(
                'pgsql:host=%s;port=%d;dbname=%s',
                $host,
                $port,
                $database,
            ),
        ];

        $timeout = $this->intOrDefault($config['timeout'] ?? null, 0);
        if ($timeout > 0) {
            $dsn[] = 'connect_timeout=' . $timeout;
        }

        $charset = $config['charset'] ?? null;
        if (is_string($charset) && $charset !== '') {
            $dsn[] = 'client_encoding=' . $charset;
        }

        $schema = $config['schema'] ?? null;
        if (is_string($schema) && $schema !== '') {
            $dsn[] = "options='-csearch_path=" . $schema . "'";
        }

        $sslMode = $config['sslmode'] ?? null;
        if (is_string($sslMode) && $sslMode !== '') {
            $dsn[] = 'sslmode=' . $sslMode;
        }

        return implode(';', $dsn);
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
