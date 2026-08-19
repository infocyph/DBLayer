<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Driver\SQLServer;

use Infocyph\DBLayer\Driver\AbstractPdoDriver;
use Infocyph\DBLayer\Exceptions\ConnectionException;
use Infocyph\DBLayer\Exceptions\QueryException;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/** Microsoft SQL Server driver using Microsoft's PDO_SQLSRV extension. */
final class SQLServerDriver extends AbstractPdoDriver
{
    protected const array CAPABILITIES = [
        'supportsReturning' => true,
        'supportsInsertIgnore' => false,
        'supportsUpsert' => true,
        'supportsSavepoints' => true,
        'supportsSchemas' => true,
        'supportsJson' => true,
        'supportsWindowFunctions' => true,
    ];

    protected const string COMPILER_CLASS = SQLServerCompiler::class;

    protected const array DRIVER_DEFAULTS = [
        'host' => '127.0.0.1',
        'port' => 1433,
        'encrypt' => true,
        'trust_server_certificate' => false,
        'application_intent' => 'ReadWrite',
    ];

    protected const string DRIVER_NAME = 'mssql';
    protected const array NETWORK_REQUIRED = ['database', 'host', 'username'];
    protected const ?string TLS_REQUIREMENT_MESSAGE = 'Driver [{driver}] requires encrypted transport. Set encrypt=true and trust_server_certificate=false for verified TLS.';

    #[\Override]
    public function applyReadOnlyTransaction(PDO $pdo): void
    {
        unset($pdo);
    }

    #[\Override]
    public function applyStatementTimeout(PDO $pdo, int $timeoutMs): void
    {
        if (!\defined('PDO::SQLSRV_ATTR_QUERY_TIMEOUT')) {
            return;
        }

        $seconds = $timeoutMs <= 0 ? 0 : max(1, (int) ceil($timeoutMs / 1_000));

        try {
            /** @var int $attribute */
            $attribute = constant('PDO::SQLSRV_ATTR_QUERY_TIMEOUT');
            $pdo->setAttribute($attribute, $seconds);
        } catch (Throwable) {
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
        unset($sql, $analyze, $buffers, $verbose, $serverVersion);

        throw QueryException::invalidParameter(
            'explain',
            'SQL Server execution plans require Connection::explain() because SHOWPLAN/STATISTICS modes are session scoped.',
        );
    }

    /**
     * @param array<int|string,mixed> $bindings
     * @return list<array<string,mixed>>
     */
    public function executeExplain(PDO $pdo, string $sql, array $bindings, bool $analyze = false, bool $buffers = false, bool $verbose = false): array
    {
        if ($buffers || $verbose) {
            throw QueryException::invalidParameter('explain', 'SQL Server does not map PostgreSQL buffers/verbose EXPLAIN options.');
        }

        $mode = $analyze ? 'STATISTICS XML' : 'SHOWPLAN_XML';
        $pdo->exec('SET ' . $mode . ' ON');

        try {
            $statement = $pdo->prepare($sql);
            if (!$statement instanceof PDOStatement) {
                throw ConnectionException::invalidConfiguration('Unable to prepare SQL Server execution-plan statement.');
            }

            foreach ($bindings as $key => $value) {
                $parameter = is_int($key) ? $key + 1 : $key;
                $statement->bindValue($parameter, $value, $this->parameterType($value));
            }

            $statement->execute();
            $planRows = [];

            do {
                while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                    if ($this->containsShowplanXml($row)) {
                        $planRows[] = $row;
                    }
                }
            } while ($statement->nextRowset());

            $statement->closeCursor();

            return $planRows;
        } finally {
            try {
                $pdo->exec('SET ' . $mode . ' OFF');
            } catch (PDOException) {
            }
        }
    }

    #[\Override]
    public function maxBindParameters(): int
    {
        return 2_100;
    }

    /** @param array<string,mixed> $config */
    #[\Override]
    public function validateConfig(array $config): void
    {
        parent::validateConfig($config);

        $driver = $this->getName();
        $this->rejectUnsupportedSettings(
            $config,
            $driver,
            ['charset', 'collation', 'unix_socket', 'ssl_ca', 'ssl_cert', 'ssl_key', 'ssl_verify_server_cert', 'sslmode', 'schema', 'read_session_read_only'],
        );
        $this->requireOptionalBooleanSetting($config, 'encrypt', $driver);
        $this->requireOptionalBooleanSetting($config, 'trust_server_certificate', $driver);
        $this->requireOptionalStringSetting($config, 'application_intent', $driver);

        $intent = $config['application_intent'] ?? 'ReadWrite';
        if (!is_string($intent) || !in_array(strtolower($intent), ['readonly', 'readwrite'], true)) {
            $this->throwInvalidConfiguration($driver, "Config key 'application_intent' must be ReadOnly or ReadWrite for driver 'mssql'.");
        }
    }

    /** @param array<string,mixed> $config */
    #[\Override]
    protected function buildDsn(array $config, bool $readOnly): string
    {
        $host = $this->stringOrDefault($config['host'] ?? null, '127.0.0.1');
        $port = $this->intOrDefault($config['port'] ?? null, 1433);
        $database = $this->stringOrDefault($config['database'] ?? null, '');
        $encrypt = (bool) ($config['encrypt'] ?? true);
        $trust = (bool) ($config['trust_server_certificate'] ?? false);
        $intent = $readOnly ? 'ReadOnly' : $this->normalizeApplicationIntent($config['application_intent'] ?? 'ReadWrite');

        $parts = [
            'Server=' . $host . ',' . $port,
            'Database=' . $database,
            'Encrypt=' . ($encrypt ? 'yes' : 'no'),
            'TrustServerCertificate=' . ($trust ? 'yes' : 'no'),
            'ApplicationIntent=' . $intent,
        ];

        if (isset($config['timeout']) && is_numeric($config['timeout']) && (int) $config['timeout'] > 0) {
            $parts[] = 'LoginTimeout=' . (int) $config['timeout'];
        }

        return 'sqlsrv:' . implode(';', $parts);
    }

    #[\Override]
    protected function hasRequiredTlsConfiguration(array $config): bool
    {
        return (bool) ($config['encrypt'] ?? true);
    }

    #[\Override]
    protected function isTlsEnforcedForConfig(array $config): bool
    {
        return $this->isTlsRequired($config);
    }

    #[\Override]
    protected function applyDerivedOptions(array $options, array $config): array
    {
        unset($config['timeout']);

        return parent::applyDerivedOptions($options, $config);
    }

    /** @param array<string,mixed> $row */
    private function containsShowplanXml(array $row): bool
    {
        return array_any($row, static fn(mixed $value): bool => is_string($value) && stripos($value, '<ShowPlanXML') !== false);
    }

    private function normalizeApplicationIntent(mixed $intent): string
    {
        return is_string($intent) && strtolower($intent) === 'readonly' ? 'ReadOnly' : 'ReadWrite';
    }

    private function parameterType(mixed $value): int
    {
        return match (true) {
            is_int($value) => PDO::PARAM_INT,
            is_bool($value) => PDO::PARAM_BOOL,
            $value === null => PDO::PARAM_NULL,
            is_resource($value) => PDO::PARAM_LOB,
            default => PDO::PARAM_STR,
        };
    }
}
