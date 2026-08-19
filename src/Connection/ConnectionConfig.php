<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Connection;

use Infocyph\ArrayKit\Array\ArrayShape;
use Infocyph\DBLayer\Driver\Contracts\DriverInterface;
use Infocyph\DBLayer\Driver\Support\DriverRegistry;
use Infocyph\DBLayer\Exceptions\ConnectionException;
use Infocyph\DBLayer\Support\ArrayNormalizer;

final class ConnectionConfig
{
    private const array DEFAULTS = [
        'driver' => 'mysql', 'database' => '', 'prefix' => '', 'options' => [], 'write' => [], 'read' => [],
        'read_strategy' => 'random', 'read_health_cooldown' => 30, 'read_latency_ttl' => 15,
        'read_probe_sample_size' => 0, 'least_latency_ttl' => 15, 'statement_cache_enabled' => false,
        'statement_cache_size' => 64, 'query_comment_enabled' => false, 'query_comment_max_length' => 160,
        'query_comment_context' => [], 'sticky' => false, 'security' => [],
    ];

    private const array DRIVER_ALIASES = [
        'pdo_mysql' => 'mysql', 'mysqli' => 'mysql', 'mariadb' => 'mariadb',
        'pgsql' => 'pgsql', 'postgres' => 'pgsql', 'postgresql' => 'pgsql', 'psql' => 'pgsql', 'pdo_pgsql' => 'pgsql',
        'mssql' => 'mssql', 'sqlsrv' => 'mssql', 'sqlserver' => 'mssql', 'pdo_sqlsrv' => 'mssql',
        'sqlite3' => 'sqlite', 'pdo_sqlite' => 'sqlite',
    ];

    private const array SAFE_EXPORT_REDACT_KEYS = [
        'password', 'passwd', 'pwd', 'secret', 'token', 'ssl_key', 'ssl_cert', 'ssl_ca', 'tls_key',
        'private_key', 'ssl_passphrase', 'passphrase', 'cursor_signing_key',
    ];

    private const array SECURITY_DEFAULT = [
        'enabled' => true, 'max_sql_length' => 16_384, 'max_params' => 512, 'max_param_bytes' => 1_024,
        'queries_per_second' => 0, 'queries_per_minute' => 0, 'rate_limit_key' => null, 'rate_limit_callback' => null,
        'strict_identifiers' => true, 'cursor_signing_key' => null, 'require_tls' => null, 'allow_insecure' => false,
        'raw_sql_policy' => 'allow', 'raw_sql_allowlist' => [],
    ];

    /** @var array<string,mixed> */
    private array $config;

    /** @param array<string,mixed> $config */
    public function __construct(array $config)
    {
        if (isset($config['driver']) && is_string($config['driver'])) {
            $config['driver'] = $this->normalizeDriverName($config['driver']);
        }

        /** @var array<string,mixed> $config */
        $config = array_replace(self::DEFAULTS, $config);
        $config['read_strategy'] = $this->normalizeReadStrategy($config['read_strategy'] ?? 'random');
        $config['security'] = array_replace(self::SECURITY_DEFAULT, $this->normalizeStringKeyArray($config['security'] ?? []));

        ArrayShape::require($config, [
            'driver' => 'string', 'database' => 'string', 'options' => 'array', 'write' => 'array',
            'read' => 'array', 'security' => 'array',
        ]);
        $this->validateSecurityConfig($this->normalizeStringKeyArray($config['security']));

        $driver = $this->resolveDriver($config['driver'] ?? null);
        if ($driver instanceof DriverInterface) {
            $config = $driver->mergeDefaults($config);
        }

        foreach (['read', 'write'] as $replicaKey) {
            $replicas = $this->expandReplicaHostVariants(
                $this->normalizeReplicaConfigs($this->requireReplicaArray($config[$replicaKey] ?? [], $replicaKey)),
            );
            $config[$replicaKey] = $replicas;

            foreach ($replicas as $replica) {
                $this->validateReplicaDescriptor($replica, $replicaKey);
                $driver?->validateConfig(array_replace($config, $replica, ['read' => [], 'write' => []]));
            }
        }

        $this->validateConfig($config);
        $driver?->validateConfig($config);
        $this->config = $config;
    }

    /** @param array<string,mixed> $config */
    public static function fromArray(array $config): self { return new self($config); }
    public function get(string $key, mixed $default = null): mixed { return $this->config[$key] ?? $default; }
    public function getDatabase(): string { $v = $this->config['database'] ?? ''; return is_string($v) ? $v : ''; }
    public function getDriver(): string
    {
        $v = $this->config['driver'] ?? '';
        if (!is_string($v) || $v === '') { throw ConnectionException::invalidConfiguration('Database driver is required.'); }
        return $v;
    }
    public function getLeastLatencyCacheTtl(): int
    {
        $v = $this->config['read_latency_ttl'] ?? ($this->config['least_latency_ttl'] ?? 15);
        return (!is_int($v) && !is_numeric($v)) ? 15 : max(0, (int) $v);
    }
    /** @return array<string,mixed> */
    public function getQueryCommentContext(): array { return $this->normalizeStringKeyArray($this->config['query_comment_context'] ?? []); }
    public function getQueryCommentMaxLength(): int
    {
        $v = $this->config['query_comment_max_length'] ?? 160;
        return (!is_int($v) && !is_numeric($v)) ? 160 : max(32, (int) $v);
    }
    /** @return array<string,mixed> */
    public function getReadConfig(): array { return $this->getReadConfigs()[0] ?? []; }
    /** @return list<array<string,mixed>> */
    public function getReadConfigs(): array { return $this->resolveReplicaConfigs('read'); }
    public function getReadHealthCooldown(): int
    {
        $v = $this->config['read_health_cooldown'] ?? 30;
        return (!is_int($v) && !is_numeric($v)) ? 30 : max(0, (int) $v);
    }
    public function getReadProbeSampleSize(): int
    {
        $v = $this->config['read_probe_sample_size'] ?? 0;
        return (!is_int($v) && !is_numeric($v)) ? 0 : max(0, (int) $v);
    }
    public function getReadStrategy(): string
    {
        $strategy = $this->config['read_strategy'] ?? 'random';

        return is_string($strategy) ? $strategy : 'random';
    }
    /** @return array<string,mixed> */
    public function getWriteConfig(): array { return $this->getWriteConfigs()[0] ?? []; }
    /** @return list<array<string,mixed>> */
    public function getWriteConfigs(): array { return $this->resolveReplicaConfigs('write'); }
    public function hasReadConfig(): bool { return $this->getReadConfigs() !== []; }
    public function hasWriteConfig(): bool { return $this->getWriteConfigs() !== []; }
    public function isSecurityEnabled(): bool
    {
        $security = $this->config['security'] ?? [];
        return is_array($security) && !empty($security['enabled']);
    }
    public function isSticky(): bool { return (bool) ($this->config['sticky'] ?? false); }
    /** @return array<string,mixed> */
    public function securityConfig(): array
    {
        return array_replace(self::SECURITY_DEFAULT, $this->normalizeStringKeyArray($this->config['security'] ?? []));
    }
    public function shouldEnforceReadSessionReadOnly(): bool { return (bool) ($this->config['read_session_read_only'] ?? false); }
    public function shouldUseQueryComments(): bool { return (bool) ($this->config['query_comment_enabled'] ?? false); }
    public function shouldUseStatementCache(): bool { return (bool) ($this->config['statement_cache_enabled'] ?? false); }
    public function statementCacheSize(): int
    {
        $v = $this->config['statement_cache_size'] ?? 64;
        return (!is_int($v) && !is_numeric($v)) ? 64 : max(0, (int) $v);
    }
    /** @return array<string,mixed> */
    public function toArray(): array { return $this->config; }
    /** @return array<string,mixed> */
    public function toSafeArray(): array
    {
        $safe = [];
        foreach ($this->config as $key => $value) { $safe[$key] = $this->redactSensitiveValue($key, $value); }
        return $safe;
    }
    public function with(string $key, mixed $value): self
    {
        $config = $this->config; $config[$key] = $value; return new self($config);
    }

    /** @param list<array<string,mixed>> $replicas @return list<array<string,mixed>> */
    private function expandReplicaHostVariants(array $replicas): array
    {
        $expanded = [];
        foreach ($replicas as $replica) {
            $hosts = $replica['host'] ?? null;
            if (!is_array($hosts)) { $expanded[] = $replica; continue; }
            $hasExpandedHost = false;
            foreach ($hosts as $host) {
                if (!is_string($host) || trim($host) === '') {
                    throw ConnectionException::invalidConfiguration('Replica host lists must contain non-empty strings.');
                }
                $copy = $replica; $copy['host'] = trim($host); $expanded[] = $copy; $hasExpandedHost = true;
            }
            if (!$hasExpandedHost) { $expanded[] = $replica; }
        }
        return $expanded;
    }

    private function normalizeDriverName(string $driver): string
    {
        $driver = strtolower(trim($driver));
        return self::DRIVER_ALIASES[$driver] ?? $driver;
    }

    private function normalizeReadStrategy(mixed $strategy): string
    {
        if (!is_string($strategy)) { throw ConnectionException::invalidConfiguration('read_strategy must be a string.'); }
        $strategy = strtolower(trim($strategy));
        if (!in_array($strategy, ['random', 'round_robin', 'weighted', 'least_latency'], true)) {
            throw ConnectionException::invalidConfiguration('read_strategy must be one of: random, round_robin, weighted, least_latency.');
        }
        return $strategy;
    }

    /** @param array<int|string,mixed> $replicas @return list<array<string,mixed>> */
    private function normalizeReplicaConfigs(array $replicas): array
    {
        if ($replicas === []) { return []; }
        if (\array_is_list($replicas)) {
            $normalized = [];
            foreach ($replicas as $replica) {
                if (!is_array($replica) || $replica === []) {
                    throw ConnectionException::invalidConfiguration('Replica lists must contain non-empty configuration arrays.');
                }
                $normalized[] = $this->normalizeStringKeyArray($replica);
            }
            return $normalized;
        }
        return [$this->normalizeStringKeyArray($replicas)];
    }

    /** @return array<string,mixed> */
    private function normalizeStringKeyArray(mixed $value): array { return ArrayNormalizer::stringKeyArray($value); }

    /**
     * @param array<array-key,mixed> $config
     * @return array<array-key,mixed>
     */
    private function redactSensitiveConfig(array $config): array
    {
        $redacted = [];
        foreach ($config as $key => $value) {
            if (is_string($key) && $this->shouldRedactConfigKey($key)) { $redacted[$key] = '[redacted]'; continue; }
            $redacted[$key] = is_array($value) ? $this->redactSensitiveConfig($value) : $value;
        }
        return $redacted;
    }
    private function redactSensitiveValue(string $key, mixed $value): mixed
    {
        if ($this->shouldRedactConfigKey($key)) { return '[redacted]'; }
        return is_array($value) ? $this->redactSensitiveConfig($value) : $value;
    }
    /** @return array<int|string,mixed> */
    private function requireReplicaArray(mixed $replicas, string $key): array
    {
        if (!is_array($replicas)) { throw ConnectionException::invalidConfiguration("Config key '{$key}' must be an array."); }
        return $replicas;
    }
    private function resolveDriver(mixed $driverName): ?DriverInterface
    {
        if (!is_string($driverName) || $driverName === '') { return null; }
        try { return DriverRegistry::resolve($driverName); } catch (ConnectionException) { return null; }
    }
    /** @return list<array<string,mixed>> */
    private function resolveReplicaConfigs(string $key): array
    {
        $replica = $this->config[$key] ?? [];
        if (!is_array($replica) || $replica === []) {
            return [];
        }

        return $this->normalizeReplicaConfigs($replica);
    }
    private function shouldRedactConfigKey(string $key): bool
    {
        return in_array(strtolower(trim($key)), self::SAFE_EXPORT_REDACT_KEYS, true);
    }
    /** @param array<string,mixed> $config */
    private function validateConfig(array $config): void
    {
        $driver = $config['driver'] ?? null;
        if (!is_string($driver) || $driver === '') {
            throw ConnectionException::invalidConfiguration('Database driver must be a non-empty string.');
        }
        if (in_array($driver, ['mysql', 'mariadb', 'pgsql', 'mssql', 'sqlite'], true)) {
            if (!isset($config['database']) || !is_string($config['database']) || $config['database'] === '') {
                throw ConnectionException::invalidConfiguration(sprintf("Config key 'database' is required for driver '%s'.", $driver));
            }
        }
        if (in_array($driver, ['mysql', 'mariadb', 'pgsql', 'mssql'], true)) {
            foreach (['host', 'username'] as $key) {
                if (!isset($config[$key]) || !is_string($config[$key]) || $config[$key] === '') {
                    throw ConnectionException::invalidConfiguration(sprintf("Config key '%s' is required for driver '%s'.", $key, $driver));
                }
            }
        }
    }
    /** @param array<string,mixed> $replica */
    private function validateReplicaDescriptor(array $replica, string $key): void
    {
        if (array_key_exists('host', $replica) && !is_string($replica['host'])) {
            throw ConnectionException::invalidConfiguration("{$key} replica host must be a string.");
        }
        if (array_key_exists('weight', $replica)) {
            $weight = $replica['weight'];
            if ((!is_int($weight) && !is_float($weight)) || $weight <= 0) {
                throw ConnectionException::invalidConfiguration("{$key} replica weight must be a positive number.");
            }
        }
    }
    /** @param array<string,mixed> $security */
    private function validateSecurityConfig(array $security): void { ConnectionSecurityConfigValidator::validate($security); }
}
