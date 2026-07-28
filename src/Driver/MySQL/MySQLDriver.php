<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Driver\MySQL;

use Infocyph\DBLayer\Driver\AbstractPdoDriver;
use Infocyph\DBLayer\Exceptions\QueryException;
use PDO;
use Pdo\Mysql;

/**
 * MySQL / MariaDB driver.
 */
final class MySQLDriver extends AbstractPdoDriver
{
    protected const array CAPABILITIES = parent::CAPABILITIES_MODERN_NETWORK;

    protected const string COMPILER_CLASS = MySQLCompiler::class;

    protected const array DRIVER_DEFAULTS = [
        'host' => '127.0.0.1',
        'port' => 3306,
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
    ];

    protected const string DRIVER_NAME = 'mysql';

    protected const array NETWORK_OPTIONAL_STRINGS = ['charset'];

    protected const array NETWORK_REQUIRED = ['database'];

    protected const array NETWORK_REQUIRED_ANY = ['host', 'unix_socket'];

    protected const ?string TLS_REQUIREMENT_MESSAGE = 'Driver [{driver}] requires TLS in this environment. Configure ssl_ca/ssl_cert/ssl_key or a Pdo\\Mysql ATTR_SSL_* option.';

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
                'MySQL/MariaDB supports analyze, but not the PostgreSQL-only buffers or verbose options.',
            );
        }

        if (!$analyze) {
            return 'EXPLAIN FORMAT=JSON ' . $sql;
        }

        if ($serverVersion !== null && str_contains(strtolower($serverVersion), 'mariadb')) {
            return 'ANALYZE FORMAT=JSON ' . $sql;
        }

        return 'EXPLAIN ANALYZE ' . $sql;
    }

    /**
     * @param array<string,mixed> $config
     */
    #[\Override]
    public function validateConfig(array $config): void
    {
        parent::validateConfig($config);

        $driver = $this->getName();
        $this->rejectUnsupportedSettings($config, $driver, ['schema', 'sslmode']);
        $this->requireOptionalTokenSetting($config, 'charset', $driver);
        $this->requireOptionalTokenSetting($config, 'collation', $driver);
        $this->requireOptionalBooleanSetting($config, 'ssl_verify_server_cert', $driver);

        foreach (['unix_socket', 'ssl_ca', 'ssl_cert', 'ssl_key'] as $key) {
            $this->requireOptionalStringSetting($config, $key, $driver);
        }
    }

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
        if (!class_exists(Mysql::class)) {
            return $options;
        }

        $collation = $config['collation'] ?? null;
        if (is_string($collation) && $collation !== '') {
            $options[Mysql::ATTR_INIT_COMMAND] = sprintf(
                'SET NAMES %s COLLATE %s',
                $this->stringOrDefault($config['charset'] ?? null, 'utf8mb4'),
                $collation,
            );
        }

        foreach ($this->sslPdoOptions($config) as $attribute => $value) {
            $options[$attribute] = $value;
        }

        $multiStatementsAttr = null;

        if (\defined(Mysql::class . '::ATTR_MULTI_STATEMENTS')) {
            $multiStatementsAttr = Mysql::ATTR_MULTI_STATEMENTS;
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

        return $this->optionsContainSslFlag($config['options'] ?? null);
    }

    private function optionsContainSslFlag(mixed $options): bool
    {
        if (!is_array($options) || !class_exists(Mysql::class)) {
            return false;
        }

        $sslAttributes = [
            Mysql::ATTR_SSL_CA,
            Mysql::ATTR_SSL_CAPATH,
            Mysql::ATTR_SSL_CERT,
            Mysql::ATTR_SSL_CIPHER,
            Mysql::ATTR_SSL_KEY,
        ];

        return array_any(
            $options,
            static fn(mixed $value, int|string $key): bool => (is_int($key) || ctype_digit($key))
                && in_array((int) $key, $sslAttributes, true)
                && $value !== null
                && $value !== '',
        );
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

    /**
     * @param array<string,mixed> $config
     * @return array<int,mixed>
     */
    private function sslPdoOptions(array $config): array
    {
        $options = [];
        $attributes = [
            'ssl_ca' => Mysql::ATTR_SSL_CA,
            'ssl_cert' => Mysql::ATTR_SSL_CERT,
            'ssl_key' => Mysql::ATTR_SSL_KEY,
        ];

        foreach ($attributes as $key => $attribute) {
            $value = $config[$key] ?? null;
            if (is_string($value) && $value !== '') {
                $options[$attribute] = $value;
            }
        }

        $verify = $config['ssl_verify_server_cert'] ?? null;
        if (is_bool($verify)) {
            $options[Mysql::ATTR_SSL_VERIFY_SERVER_CERT] = $verify;
        }

        return $options;
    }
}
