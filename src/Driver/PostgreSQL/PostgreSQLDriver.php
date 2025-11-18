<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Driver\PostgreSQL;

use Infocyph\DBLayer\Driver\AbstractPdoDriver;
use Infocyph\DBLayer\Driver\Contracts\QueryCompilerInterface;
use Infocyph\DBLayer\Driver\Support\Capabilities;

/**
 * PostgreSQL driver.
 */
final class PostgreSQLDriver extends AbstractPdoDriver
{
    public function createCompiler(): QueryCompilerInterface
    {
        return new PostgreSQLCompiler();
    }

    public function getCapabilities(): Capabilities
    {
        return new Capabilities(
            supportsReturning: true,
            supportsInsertIgnore: false, // typically handled via ON CONFLICT DO NOTHING
            supportsUpsert: true,
            supportsSavepoints: true,
            supportsSchemas: true,
        );
    }
    public function getName(): string
    {
        return 'pgsql';
    }

    /**
     * @param  array<string,mixed>  $config
     * @return array<string,mixed>
     */
    public function mergeDefaults(array $config): array
    {
        $config = parent::mergeDefaults($config);

        $config['port'] ??= 5432;

        return $config;
    }

    /**
     * @param  array<string,mixed>  $config
     */
    protected function buildDsn(array $config, bool $readOnly): string
    {
        $database = (string) ($config['database'] ?? '');
        $host     = (string) ($config['host'] ?? '127.0.0.1');
        $port     = (int) ($config['port'] ?? 5432);

        // Charset/client_encoding handled via post-connect commands / options
        // (existing Connection logic already does this; we keep DSN simple here).
        return sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $host,
            $port,
            $database
        );
    }
}
