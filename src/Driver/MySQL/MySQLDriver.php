<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Driver\MySQL;

use Infocyph\DBLayer\Driver\AbstractPdoDriver;
use Infocyph\DBLayer\Driver\Contracts\QueryCompilerInterface;
use Infocyph\DBLayer\Driver\Support\Capabilities;
use Infocyph\DBLayer\Exceptions\ConnectionException;
use PDO;

/**
 * MySQL / MariaDB driver.
 */
final class MySQLDriver extends AbstractPdoDriver
{
    #[\Override]
    public function createCompiler(): QueryCompilerInterface
    {
        return new MySQLCompiler();
    }

    #[\Override]
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

    #[\Override]
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
    #[\Override]
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
    #[\Override]
    public function validateConfig(array $config): void
    {
        $driver = $this->getName();

        $database = $config['database'] ?? null;
        $host = $config['host'] ?? null;
        $socket = $config['unix_socket'] ?? null;

        if (! is_string($database) || $database === '') {
            throw ConnectionException::invalidConfiguration(
                $driver,
            );
        }

        // Either host OR unix_socket must be provided.
        if (
            (! is_string($host) || $host === '')
            && (! is_string($socket) || $socket === '')
        ) {
            throw ConnectionException::invalidConfiguration(
                $driver,
            );
        }

        if (
            isset($config['port'])
            && ! is_int($config['port'])
            && ! ctype_digit((string) $config['port'])
        ) {
            throw ConnectionException::invalidConfiguration(
                $driver,
            );
        }

        if (isset($config['charset']) && ! is_string($config['charset'])) {
            throw ConnectionException::invalidConfiguration(
                $driver,
            );
        }

        if ($this->requiresTls($config) && ! $this->hasTlsConfiguration($config)) {
            throw ConnectionException::invalidConfiguration(
                "Driver [{$driver}] requires TLS in this environment. Configure ssl_ca/ssl_cert/ssl_key or secure sslmode.",
            );
        }
    }

    /**
     * Build the PDO DSN for MySQL / MariaDB.
     *
     * @param  array<string,mixed>  $config
     */
    #[\Override]
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

    /**
     * @param  array<string,mixed>  $config
     * @return array<int,mixed>
     */
    #[\Override]
    protected function defaultPdoOptions(array $config): array
    {
        $options = parent::defaultPdoOptions($config);

        $multiStatementsAttr = null;

        if (class_exists(\Pdo\Mysql::class) && \defined(\Pdo\Mysql::class . '::ATTR_MULTI_STATEMENTS')) {
            /** @var int $multiStatementsAttr */
            $multiStatementsAttr = \Pdo\Mysql::ATTR_MULTI_STATEMENTS;
        } elseif (\defined('PDO::MYSQL_ATTR_MULTI_STATEMENTS')) {
            /** @var int $multiStatementsAttr */
            $multiStatementsAttr = constant('PDO::MYSQL_ATTR_MULTI_STATEMENTS');
        }

        if ($multiStatementsAttr !== null) {
            $options[$multiStatementsAttr] = false;
        }

        return $options;
    }

    /**
     * Whether enough TLS config is present for secure client/server transport.
     *
     * @param  array<string,mixed>  $config
     */
    private function hasTlsConfiguration(array $config): bool
    {
        foreach (['ssl_ca', 'ssl_cert', 'ssl_key'] as $key) {
            if (isset($config[$key]) && is_string($config[$key]) && trim($config[$key]) !== '') {
                return true;
            }
        }

        $sslMode = $config['sslmode'] ?? null;

        if (is_string($sslMode)) {
            $normalized = strtolower(trim($sslMode));

            if (\in_array($normalized, ['require', 'required', 'verify-ca', 'verify-full', 'verify_identity', 'verify_ca'], true)) {
                return true;
            }
        }

        $options = $config['options'] ?? [];

        if (! is_array($options)) {
            return false;
        }

        foreach ($options as $key => $value) {
            unset($value);

            if (is_string($key) && str_contains(strtolower($key), 'ssl')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether TLS should be required for this config.
     *
     * @param  array<string,mixed>  $config
     */
    private function requiresTls(array $config): bool
    {
        if (! empty($config['unix_socket'])) {
            return false;
        }

        $security = $config['security'] ?? [];

        if (! is_array($security)) {
            return false;
        }

        if (! isset($security['require_tls'])) {
            return false;
        }

        return (bool) $security['require_tls'];
    }
}
