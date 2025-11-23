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
          supportsInsertIgnore: false, // handled via ON CONFLICT DO NOTHING
          supportsUpsert: true,
          supportsSavepoints: true,
          supportsSchemas: true,
          supportsJson: true,
          supportsWindowFunctions: true,
        );
    }

    public function getName(): string
    {
        return 'pgsql';
    }

    /**
     * Merge driver-specific defaults (port).
     *
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
     * Build the PDO DSN for PostgreSQL.
     *
     * @param  array<string,mixed>  $config
     */
    protected function buildDsn(array $config, bool $readOnly): string
    {
        unset($readOnly); // handled at transaction-level

        $database = (string) ($config['database'] ?? '');
        $host     = (string) ($config['host'] ?? '127.0.0.1');
        $port     = (int) ($config['port'] ?? 5432);

        $dsn = sprintf(
          'pgsql:host=%s;port=%d;dbname=%s',
          $host,
          $port,
          $database
        );

        // Allow sslmode to be passed (if provided).
        if (! empty($config['sslmode'])) {
            $sslmode = (string) $config['sslmode'];
            $dsn    .= ';sslmode='.$sslmode;
        }

        return $dsn;
    }
}
