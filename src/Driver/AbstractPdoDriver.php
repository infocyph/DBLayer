<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Driver;

use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\DBLayer\Driver\Contracts\DriverInterface;
use Infocyph\DBLayer\Driver\Contracts\QueryCompilerInterface;
use Infocyph\DBLayer\Driver\Support\Capabilities;
use Infocyph\DBLayer\Exceptions\ConnectionException;
use LogicException;
use PDO;

/**
 * Base PDO-backed driver.
 *
 * - Normalises createPdo() so concrete drivers only care about DSN + capabilities.
 * - Leaves config validation to ConnectionConfig by default (drivers can override).
 * - Provides a consistent set of secure, production-grade PDO attributes.
 */
abstract class AbstractPdoDriver implements DriverInterface
{
    /**
     * @var array{
     *   supportsReturning:bool,
     *   supportsInsertIgnore:bool,
     *   supportsUpsert:bool,
     *   supportsSavepoints:bool,
     *   supportsSchemas:bool,
     *   supportsJson:bool,
     *   supportsWindowFunctions:bool
     * }
     */
    protected const array CAPABILITIES = [
        'supportsReturning' => false,
        'supportsInsertIgnore' => false,
        'supportsUpsert' => false,
        'supportsSavepoints' => false,
        'supportsSchemas' => false,
        'supportsJson' => false,
        'supportsWindowFunctions' => false,
    ];

    protected const array CAPABILITIES_MODERN_NETWORK = [
        'supportsReturning' => false,
        'supportsInsertIgnore' => true,
        'supportsUpsert' => true,
        'supportsSavepoints' => true,
        'supportsSchemas' => true,
        'supportsJson' => true,
        'supportsWindowFunctions' => true,
    ];

    protected const array CAPABILITIES_POSTGRES = [
        'supportsReturning' => true,
        'supportsInsertIgnore' => false,
        'supportsUpsert' => true,
        'supportsSavepoints' => true,
        'supportsSchemas' => true,
        'supportsJson' => true,
        'supportsWindowFunctions' => true,
    ];

    protected const array CAPABILITIES_SQLITE = [
        'supportsReturning' => false,
        'supportsInsertIgnore' => true,
        'supportsUpsert' => true,
        'supportsSavepoints' => true,
        'supportsSchemas' => false,
        'supportsJson' => true,
        'supportsWindowFunctions' => true,
    ];

    /**
     * @var class-string<QueryCompilerInterface>
     */
    protected const ?string COMPILER_CLASS = null;

    /**
     * @var array<string,mixed>
     */
    protected const array DRIVER_DEFAULTS = [];

    protected const string DRIVER_NAME = '';

    /**
     * @var list<string>
     */
    protected const array NETWORK_OPTIONAL_STRINGS = [];

    /**
     * @var list<string>
     */
    protected const array NETWORK_REQUIRED = [];

    /**
     * @var list<string>
     */
    protected const array NETWORK_REQUIRED_ANY = [];

    protected const ?string TLS_REQUIREMENT_MESSAGE = null;

    /**
     * Build the PDO DSN string for the driver.
     *
     * @param array<string,mixed> $config
     */
    abstract protected function buildDsn(array $config, bool $readOnly): string;

    #[\Override]
    final public function createCompiler(): QueryCompilerInterface
    {
        $compilerClass = static::COMPILER_CLASS;

        if ($compilerClass === null) {
            throw new LogicException(sprintf('%s must define COMPILER_CLASS.', static::class));
        }

        return new $compilerClass();
    }

    /**
     * Create a PDO instance for this driver.
     *
     * Concrete drivers only need to implement buildDsn().
     */
    #[\Override]
    final public function createPdo(ConnectionConfig $config, bool $readOnly = false): PDO
    {
        $data = $config->toArray();

        // Allow drivers to stamp their defaults and validate config.
        $data = $this->mergeDefaults($data);
        $this->validateConfig($data);

        $dsn = $this->buildDsn($data, $readOnly);

        $username = $this->stringValue($data['username'] ?? '');
        $password = $this->stringValue($data['password'] ?? '');
        $options = $this->normalizePdoOptions($data['options'] ?? null);

        // Derive PDO attributes from config if not explicitly set.
        $options = $this->applyDerivedOptions($options, $data);

        // Driver-provided defaults (secure by default).
        $defaults = $this->defaultPdoOptions($data);

        // User-specified options should win.
        $options = $options + $defaults;

        return new PDO($dsn, $username, $password, $options);
    }

    #[\Override]
    final public function getCapabilities(): Capabilities
    {
        $capabilities = static::CAPABILITIES;

        return new Capabilities(
            supportsReturning: $capabilities['supportsReturning'],
            supportsInsertIgnore: $capabilities['supportsInsertIgnore'],
            supportsUpsert: $capabilities['supportsUpsert'],
            supportsSavepoints: $capabilities['supportsSavepoints'],
            supportsSchemas: $capabilities['supportsSchemas'],
            supportsJson: $capabilities['supportsJson'],
            supportsWindowFunctions: $capabilities['supportsWindowFunctions'],
        );
    }

    /**
     * Canonical engine name, e.g. "mysql", "pgsql", "sqlite".
     */
    #[\Override]
    final public function getName(): string
    {
        $name = static::DRIVER_NAME;

        if ($name === '') {
            throw new LogicException(sprintf('%s must define DRIVER_NAME.', static::class));
        }

        return $name;
    }

    /**
     * Merge driver-specific defaults into user config.
     *
     * Default: ensure "driver" is set to this driver's canonical name.
     *
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    #[\Override]
    public function mergeDefaults(array $config): array
    {
        $config['driver'] ??= $this->getName();

        foreach (static::DRIVER_DEFAULTS as $key => $value) {
            if (!array_key_exists($key, $config) || $config[$key] === null) {
                $config[$key] = $value;
            }
        }

        return $config;
    }

    /**
     * Validate driver-specific configuration.
     *
     * By default, rely on ConnectionConfig to enforce core invariants.
     *
     * @param array<string,mixed> $config
     */
    #[\Override]
    public function validateConfig(array $config): void
    {
        $driver = $this->getName();

        $this->validateNetworkConfig(
            $config,
            $driver,
            required: $this->requiredNetworkSettings(),
            requiredAny: $this->requiredAnyNetworkSettings(),
            optionalStrings: $this->optionalNetworkStringSettings(),
        );

        $message = $this->tlsRequirementMessage($driver);
        if ($message === null) {
            return;
        }

        $this->validateTlsPolicy(
            $config,
            $driver,
            fn(array $settings): bool => $this->hasRequiredTlsConfiguration($settings),
            $message,
            fn(array $settings): bool => $this->isTlsEnforcedForConfig($settings),
        );
    }

    /**
     * Apply derived PDO options based on generic config keys.
     *
     * @param array<int,mixed> $options
     * @param array<string,mixed> $config
     * @return array<int,mixed>
     */
    protected function applyDerivedOptions(array $options, array $config): array
    {
        // Connection timeout (seconds) → ATTR_TIMEOUT (if not explicitly set).
        if (isset($config['timeout']) && is_numeric($config['timeout'])) {
            $timeout = (int) $config['timeout'];

            if ($timeout > 0 && !array_key_exists(PDO::ATTR_TIMEOUT, $options)) {
                $options[PDO::ATTR_TIMEOUT] = $timeout;
            }
        }

        // Persistent connections.
        if (!empty($config['persistent']) && !array_key_exists(PDO::ATTR_PERSISTENT, $options)) {
            $options[PDO::ATTR_PERSISTENT] = true;
        }

        return $options;
    }

    /**
     * Default PDO attributes for this driver.
     *
     * @param array<string,mixed> $config
     * @return array<int,mixed>
     */
    protected function defaultPdoOptions(array $config): array
    {
        unset($config); // reserved for future driver-specific tuning

        return [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];
    }

    /**
     * @param array<string,mixed> $config
     */
    protected function hasRequiredTlsConfiguration(array $config): bool
    {
        unset($config);

        return true;
    }

    protected function intOrDefault(mixed $value, int $default): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return $default;
    }

    protected function isNumericString(mixed $value): bool
    {
        return is_string($value) && ctype_digit($value);
    }

    /**
     * @param array<string,mixed> $config
     */
    protected function isTlsEnforcedForConfig(array $config): bool
    {
        return $this->isTlsRequired($config);
    }

    /**
     * Whether TLS is required by generic security config.
     *
     * @param array<string,mixed> $config
     */
    protected function isTlsRequired(array $config): bool
    {
        $security = $config['security'] ?? [];

        if (!is_array($security)) {
            return false;
        }

        if (!isset($security['require_tls'])) {
            return false;
        }

        return (bool) $security['require_tls'];
    }

    /**
     * @return list<string>
     */
    protected function optionalNetworkStringSettings(): array
    {
        return static::NETWORK_OPTIONAL_STRINGS;
    }

    /**
     * @param array<string,mixed> $config
     * @param list<string> $keys
     */
    protected function requireAnyNonEmptyStringSetting(array $config, array $keys, string $driver): void
    {
        foreach ($keys as $key) {
            $value = $config[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return;
            }
        }

        $this->throwInvalidConfiguration($driver);
    }

    /**
     * @return list<string>
     */
    protected function requiredAnyNetworkSettings(): array
    {
        return static::NETWORK_REQUIRED_ANY;
    }

    /**
     * @return list<string>
     */
    protected function requiredNetworkSettings(): array
    {
        return static::NETWORK_REQUIRED;
    }

    /**
     * @param array<string,mixed> $config
     */
    protected function requireNonEmptyStringSetting(array $config, string $key, string $driver): void
    {
        $value = $config[$key] ?? null;

        if (!is_string($value) || $value === '') {
            $this->throwInvalidConfiguration($driver);
        }
    }

    /**
     * @param array<string,mixed> $config
     */
    protected function requireOptionalNumericPort(array $config, string $driver): void
    {
        if (isset($config['port']) && !is_int($config['port']) && !$this->isNumericString($config['port'])) {
            $this->throwInvalidConfiguration($driver);
        }
    }

    /**
     * @param array<string,mixed> $config
     */
    protected function requireOptionalStringSetting(array $config, string $key, string $driver): void
    {
        if (isset($config[$key]) && !is_string($config[$key])) {
            $this->throwInvalidConfiguration($driver);
        }
    }

    protected function stringOrDefault(mixed $value, string $default): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        return $default;
    }

    protected function throwInvalidConfiguration(string $driver, ?string $message = null): never
    {
        throw ConnectionException::invalidConfiguration($message ?? $driver);
    }

    protected function tlsRequirementMessage(string $driver): ?string
    {
        $message = static::TLS_REQUIREMENT_MESSAGE;

        if ($message === null) {
            return null;
        }

        return str_replace('{driver}', $driver, $message);
    }

    /**
     * @param array<string,mixed> $config
     * @param list<string> $required
     * @param list<string> $requiredAny
     * @param list<string> $optionalStrings
     */
    protected function validateNetworkConfig(
        array $config,
        string $driver,
        array $required = [],
        array $requiredAny = [],
        array $optionalStrings = [],
    ): void {
        foreach ($required as $key) {
            $this->requireNonEmptyStringSetting($config, $key, $driver);
        }

        if ($requiredAny !== []) {
            $this->requireAnyNonEmptyStringSetting($config, $requiredAny, $driver);
        }

        $this->requireOptionalNumericPort($config, $driver);

        foreach ($optionalStrings as $key) {
            $this->requireOptionalStringSetting($config, $key, $driver);
        }
    }

    /**
     * @param array<string,mixed> $config
     * @param callable(array<string,mixed>):bool $hasTlsConfiguration
     * @param null|callable(array<string,mixed>):bool $isTlsRequired
     */
    protected function validateTlsPolicy(
        array $config,
        string $driver,
        callable $hasTlsConfiguration,
        string $message,
        ?callable $isTlsRequired = null,
    ): void {
        $requiresTls = $isTlsRequired !== null
            ? $isTlsRequired($config)
            : $this->isTlsRequired($config);

        if (!$requiresTls) {
            return;
        }

        if ($hasTlsConfiguration($config)) {
            return;
        }

        $this->throwInvalidConfiguration($driver, $message);
    }

    /**
     * @return array<int,mixed>
     */
    private function normalizePdoOptions(mixed $options): array
    {
        if (!is_array($options)) {
            return [];
        }

        $normalized = [];

        foreach ($options as $key => $value) {
            if (is_int($key)) {
                $normalized[$key] = $value;

                continue;
            }

            if (ctype_digit($key)) {
                $normalized[(int) $key] = $value;
            }
        }

        return $normalized;
    }

    private function stringValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        return '';
    }
}
