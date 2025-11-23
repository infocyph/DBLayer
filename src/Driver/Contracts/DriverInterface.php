<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Driver\Contracts;

use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\DBLayer\Driver\Support\Capabilities;
use PDO;

/**
 * PDO-backed driver abstraction.
 *
 * One implementation per engine (MySQL, PostgreSQL, SQLite, ...).
 */
interface DriverInterface
{
    /**
     * Create a new query compiler instance for this driver.
     */
    public function createCompiler(): QueryCompilerInterface;

    /**
     * Create a PDO instance for this driver.
     */
    public function createPdo(ConnectionConfig $config, bool $readOnly = false): PDO;

    /**
     * Describe driver capabilities / dialect features.
     */
    public function getCapabilities(): Capabilities;

    /**
     * Canonical engine name, e.g. "mysql", "pgsql", "sqlite".
     */
    public function getName(): string;

    /**
     * Merge driver-specific defaults into user config.
     *
     * @param  array<string,mixed>  $config
     * @return array<string,mixed>
     */
    public function mergeDefaults(array $config): array;

    /**
     * Validate driver-specific configuration.
     *
     * Implementations SHOULD throw a descriptive exception if a required
     * setting is missing or malformed (e.g. missing "database" for MySQL).
     *
     * @param  array<string,mixed>  $config
     */
    public function validateConfig(array $config): void;
}
