<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Driver;

use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\DBLayer\Driver\Contracts\DriverInterface;
use Infocyph\DBLayer\Driver\Contracts\QueryCompilerInterface;
use Infocyph\DBLayer\Driver\Support\Capabilities;
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
     * Concrete drivers must still provide compiler + capabilities.
     */
    abstract public function createCompiler(): QueryCompilerInterface;

    abstract public function getCapabilities(): Capabilities;

    /**
     * Canonical engine name, e.g. "mysql", "pgsql", "sqlite".
     */
    abstract public function getName(): string;

    /**
     * Build the PDO DSN string for the driver.
     *
     * @param  array<string,mixed>  $config
     */
    abstract protected function buildDsn(array $config, bool $readOnly): string;

    /**
     * Create a PDO instance for this driver.
     *
     * Concrete drivers only need to implement buildDsn().
     */
    final public function createPdo(ConnectionConfig $config, bool $readOnly = false): PDO
    {
        /** @var array<string,mixed> $data */
        $data = method_exists($config, 'toArray') ? $config->toArray() : [];

        // Allow drivers to stamp their defaults and validate config.
        $data = $this->mergeDefaults($data);
        $this->validateConfig($data);

        $dsn = $this->buildDsn($data, $readOnly);

        $username = (string) ($data['username'] ?? '');
        $password = (string) ($data['password'] ?? '');

        $options = $data['options'] ?? [];
        if (! is_array($options)) {
            $options = [];
        }

        // Derive PDO attributes from config if not explicitly set.
        $options = $this->applyDerivedOptions($options, $data);

        // Driver-provided defaults (secure by default).
        $defaults = $this->defaultPdoOptions($data);

        // User-specified options should win.
        $options = $options + $defaults;

        return new PDO($dsn, $username, $password, $options);
    }

    /**
     * Merge driver-specific defaults into user config.
     *
     * Default: ensure "driver" is set to this driver's canonical name.
     *
     * @param  array<string,mixed>  $config
     * @return array<string,mixed>
     */
    public function mergeDefaults(array $config): array
    {
        $config['driver'] ??= $this->getName();

        return $config;
    }

    /**
     * Validate driver-specific configuration.
     *
     * By default, rely on ConnectionConfig to enforce core invariants.
     *
     * @param  array<string,mixed>  $config
     */
    public function validateConfig(array $config): void
    {
        // Intentionally empty for now.
        // Concrete drivers may throw if required keys are missing/misconfigured.
    }

    /**
     * Apply derived PDO options based on generic config keys.
     *
     * @param  array<int,mixed>      $options
     * @param  array<string,mixed>   $config
     * @return array<int,mixed>
     */
    protected function applyDerivedOptions(array $options, array $config): array
    {
        // Connection timeout (seconds) → ATTR_TIMEOUT (if not explicitly set).
        if (isset($config['timeout']) && is_numeric($config['timeout'])) {
            $timeout = (int) $config['timeout'];

            if ($timeout > 0 && ! array_key_exists(PDO::ATTR_TIMEOUT, $options)) {
                $options[PDO::ATTR_TIMEOUT] = $timeout;
            }
        }

        // Persistent connections.
        if (! empty($config['persistent']) && ! array_key_exists(PDO::ATTR_PERSISTENT, $options)) {
            $options[PDO::ATTR_PERSISTENT] = true;
        }

        return $options;
    }

    /**
     * Default PDO attributes for this driver.
     *
     * @param  array<string,mixed>  $config
     * @return array<int,mixed>
     */
    protected function defaultPdoOptions(array $config): array
    {
        unset($config); // reserved for future driver-specific tuning

        return [
          PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
          PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
          PDO::ATTR_EMULATE_PREPARES   => false,
          PDO::ATTR_STRINGIFY_FETCHES  => false,
        ];
    }
}
