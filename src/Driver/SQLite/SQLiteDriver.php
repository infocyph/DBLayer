<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Driver\SQLite;

use Infocyph\DBLayer\Driver\AbstractPdoDriver;
use Infocyph\DBLayer\Driver\Contracts\QueryCompilerInterface;
use Infocyph\DBLayer\Driver\Support\Capabilities;
use Infocyph\DBLayer\Exceptions\ConnectionException;

/**
 * SQLite driver.
 *
 * Supports file-based and in-memory databases.
 */
final class SQLiteDriver extends AbstractPdoDriver
{
    #[\Override]
    public function createCompiler(): QueryCompilerInterface
    {
        return new SQLiteCompiler();
    }

    #[\Override]
    public function getCapabilities(): Capabilities
    {
        // Treat JSON + window functions as available (SQLite 3.25+ with JSON1).
        return new Capabilities(
            supportsReturning: false, // SQLite >= 3.35.0 has RETURNING, but we treat as off by default.
            supportsInsertIgnore: true,
            supportsUpsert: true,
            supportsSavepoints: true,
            supportsSchemas: false,
            supportsJson: true,
            supportsWindowFunctions: true,
        );
    }

    #[\Override]
    public function getName(): string
    {
        return 'sqlite';
    }

    /**
     * Merge driver-specific defaults (database path).
     *
     * @param  array<string,mixed>  $config
     * @return array<string,mixed>
     */
    #[\Override]
    public function mergeDefaults(array $config): array
    {
        $config = parent::mergeDefaults($config);

        // Default to in-memory if no database path is provided.
        $config['database'] ??= ':memory:';

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

        if (! is_string($database) || $database === '') {
            throw ConnectionException::invalidConfiguration(
                $driver,
            );
        }

        // Optional: guard against accidentally passing a directory.
        if ($database !== ':memory:' && str_ends_with($database, DIRECTORY_SEPARATOR)) {
            throw ConnectionException::invalidConfiguration(
                $driver,
            );
        }
    }

    /**
     * Build the PDO DSN for SQLite.
     *
     * @param  array<string,mixed>  $config
     */
    #[\Override]
    protected function buildDsn(array $config, bool $readOnly): string
    {
        $database = (string) ($config['database'] ?? ':memory:');

        if ($database === ':memory:') {
            return 'sqlite::memory:';
        }

        // SQLite read-only can be expressed via URI with mode=ro.
        if ($readOnly && ! str_contains($database, 'mode=')) {
            return sprintf('sqlite:%s?mode=ro', $database);
        }

        return 'sqlite:' . $database;
    }
}
