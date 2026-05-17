<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Driver\MySQL;

use Infocyph\DBLayer\Driver\AbstractPdoDriver;
use PDO;

/**
 * MySQL / MariaDB driver.
 */
final class MySQLDriver extends AbstractPdoDriver
{
    protected const array CAPABILITIES = parent::CAPABILITIES_MODERN_NETWORK;

    protected const string COMPILER_CLASS = MySQLCompiler::class;

    protected const array DRIVER_DEFAULTS = [
        'port' => 3306,
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
    ];

    protected const string DRIVER_NAME = 'mysql';

    protected const array NETWORK_OPTIONAL_STRINGS = ['charset'];

    protected const array NETWORK_REQUIRED = ['database'];

    protected const array NETWORK_REQUIRED_ANY = ['host', 'unix_socket'];

    protected const ?string TLS_REQUIREMENT_MESSAGE = 'Driver [{driver}] requires TLS in this environment. Configure ssl_ca/ssl_cert/ssl_key or secure sslmode.';

    /**
     * Build the PDO DSN for MySQL / MariaDB.
     *
     * @param array<string,mixed> $config
     */
    #[\Override]
    protected function buildDsn(array $config, bool $readOnly): string
    {
        unset($readOnly); // handled via transaction semantics, not DSN

        $database = $this->stringOrDefault($config['database'] ?? null, '');
        $charset = $this->stringOrDefault($config['charset'] ?? null, 'utf8mb4');

        // Prefer unix socket when provided.
        if (!empty($config['unix_socket'])) {
            $socket = $this->stringOrDefault($config['unix_socket'], '');

            return sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $socket, $database, $charset);
        }

        $host = $this->stringOrDefault($config['host'] ?? null, '127.0.0.1');
        $port = $this->intOrDefault($config['port'] ?? null, 3306);

        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $host,
            $port,
            $database,
            $charset,
        );
    }

    /**
     * @param array<string,mixed> $config
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
     * @param array<string,mixed> $config
     */
    #[\Override]
    protected function hasRequiredTlsConfiguration(array $config): bool
    {
        return $this->hasTlsConfiguration($config);
    }

    /**
     * @param array<string,mixed> $config
     */
    #[\Override]
    protected function isTlsEnforcedForConfig(array $config): bool
    {
        return $this->requiresTls($config);
    }

    /**
     * @param array<string,mixed> $config
     */
    private function hasNonEmptyTlsFiles(array $config): bool
    {
        foreach (['ssl_ca', 'ssl_cert', 'ssl_key'] as $key) {
            $value = $config[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether enough TLS config is present for secure client/server transport.
     *
     * @param array<string,mixed> $config
     */
    private function hasTlsConfiguration(array $config): bool
    {
        if ($this->hasNonEmptyTlsFiles($config)) {
            return true;
        }

        if ($this->isSecureSslMode($config['sslmode'] ?? null)) {
            return true;
        }

        return $this->optionsContainSslFlag($config['options'] ?? null);
    }

    private function isSecureSslMode(mixed $sslMode): bool
    {
        if (!is_string($sslMode)) {
            return false;
        }

        $normalized = strtolower(trim($sslMode));

        return \in_array($normalized, ['require', 'required', 'verify-ca', 'verify-full', 'verify_identity', 'verify_ca'], true);
    }

    private function optionsContainSslFlag(mixed $options): bool
    {
        if (!is_array($options)) {
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
     * @param array<string,mixed> $config
     */
    private function requiresTls(array $config): bool
    {
        if (!empty($config['unix_socket'])) {
            return false;
        }

        return $this->isTlsRequired($config);
    }
}
