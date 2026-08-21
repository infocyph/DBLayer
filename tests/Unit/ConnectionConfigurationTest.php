<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Driver\Contracts\DriverInterface;
use Infocyph\DBLayer\Driver\Contracts\QueryCompilerInterface;
use Infocyph\DBLayer\Driver\MySQL\MySQLDriver;
use Infocyph\DBLayer\Driver\PostgreSQL\PostgreSQLDriver;
use Infocyph\DBLayer\Driver\SQLite\SQLiteDriver;
use Infocyph\DBLayer\Driver\SQLServer\SQLServerDriver;
use Infocyph\DBLayer\Driver\Support\Capabilities;
use Infocyph\DBLayer\Driver\Support\DriverRegistry;
use Infocyph\DBLayer\Exceptions\ConnectionException;
use Pdo\Mysql;

it('applies defaults owned by each concrete driver', function (): void {
    $mysql = ConnectionConfig::fromArray([
        'driver' => 'mysql',
        'database' => 'app',
        'username' => 'app',
    ])->toArray();
    $pgsql = ConnectionConfig::fromArray([
        'driver' => 'pgsql',
        'database' => 'app',
        'username' => 'app',
    ])->toArray();
    $sqlite = ConnectionConfig::fromArray([
        'driver' => 'sqlite',
    ])->toArray();

    expect($mysql)->toMatchArray([
        'host' => '127.0.0.1',
        'port' => 3306,
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
    ])->and($pgsql)->toMatchArray([
        'host' => '127.0.0.1',
        'port' => 5432,
        'charset' => 'utf8',
        'schema' => 'public',
    ])->and($sqlite['database'])->toBe(':memory:')
        ->and($sqlite)->not->toHaveKeys([
            'host',
            'port',
            'username',
            'password',
            'charset',
            'collation',
            'schema',
            'read_session_read_only',
        ]);
});

it('rejects built-in options that do not apply to the selected driver', function (
    array $config,
    string $message,
): void {
    expect(static fn(): ConnectionConfig => ConnectionConfig::fromArray($config))
        ->toThrow(ConnectionException::class, $message);
})->with([
    'mysql schema' => [[
        'driver' => 'mysql',
        'database' => 'app',
        'username' => 'app',
        'schema' => 'public',
    ], "Config key 'schema' is not supported by driver 'mysql'."],
    'mysql PostgreSQL SSL mode' => [[
        'driver' => 'mysql',
        'database' => 'app',
        'username' => 'app',
        'sslmode' => 'require',
    ], "Config key 'sslmode' is not supported by driver 'mysql'."],
    'PostgreSQL collation' => [[
        'driver' => 'pgsql',
        'database' => 'app',
        'username' => 'app',
        'collation' => 'utf8mb4_unicode_ci',
    ], "Config key 'collation' is not supported by driver 'pgsql'."],
    'PostgreSQL MySQL CA path' => [[
        'driver' => 'pgsql',
        'database' => 'app',
        'username' => 'app',
        'ssl_ca' => '/run/secrets/ca.pem',
    ], "Config key 'ssl_ca' is not supported by driver 'pgsql'."],
    'SQLite charset' => [[
        'driver' => 'sqlite',
        'database' => ':memory:',
        'charset' => 'utf8',
    ], "Config key 'charset' is not supported by driver 'sqlite'."],
    'SQLite network host' => [[
        'driver' => 'sqlite',
        'database' => ':memory:',
        'host' => '127.0.0.1',
    ], "Config key 'host' is not supported by driver 'sqlite'."],
    'SQLite credentials' => [[
        'driver' => 'sqlite',
        'database' => ':memory:',
        'username' => 'unused',
    ], "Config key 'username' is not supported by driver 'sqlite'."],
    'SQLite TLS policy' => [[
        'driver' => 'sqlite',
        'database' => ':memory:',
        'security' => [
            'require_tls' => true,
        ],
    ], "Security config key 'require_tls' is not supported by driver 'sqlite'."],
]);

it('keeps the DBLayer 4.0 custom driver interface backward compatible', function (): void {
    $legacyDriver = new class implements DriverInterface {
        private SQLiteDriver $driver;

        public function __construct()
        {
            $this->driver = new SQLiteDriver();
        }

        public function applyReadOnlyTransaction(\PDO $pdo): void
        {
            $this->driver->applyReadOnlyTransaction($pdo);
        }

        public function applyStatementTimeout(\PDO $pdo, int $timeoutMs): void
        {
            $this->driver->applyStatementTimeout($pdo, $timeoutMs);
        }

        public function compileExplain(
            string $sql,
            bool $analyze = false,
            bool $buffers = false,
            bool $verbose = false,
            ?string $serverVersion = null,
        ): string {
            return $this->driver->compileExplain($sql, $analyze, $buffers, $verbose, $serverVersion);
        }

        public function createCompiler(): QueryCompilerInterface
        {
            return $this->driver->createCompiler();
        }

        public function createPdo(ConnectionConfig $config, bool $readOnly = false): \PDO
        {
            return $this->driver->createPdo($config, $readOnly);
        }

        public function dateFormat(): string
        {
            return $this->driver->dateFormat();
        }

        public function getCapabilities(): Capabilities
        {
            return $this->driver->getCapabilities();
        }

        public function getName(): string
        {
            return 'legacy_4_0';
        }

        public function maxBindParameters(): int
        {
            return $this->driver->maxBindParameters();
        }

        public function mergeDefaults(array $config): array
        {
            return $this->driver->mergeDefaults($config);
        }

        public function validateConfig(array $config): void
        {
            $this->driver->validateConfig($config);
        }
    };
    $driverClass = $legacyDriver::class;

    DriverRegistry::register('legacy_4_0', $driverClass);
    DB::addConnection([
        'driver' => 'legacy_4_0',
        'database' => ':memory:',
    ], 'legacy_4_0');

    DB::transaction(static function ($connection): void {
        $connection->statement('create table legacy_rows (id integer primary key)');
        $connection->statement('insert into legacy_rows (id) values (1)');
    }, connection: 'legacy_4_0');

    expect((new ReflectionClass(DriverInterface::class))->hasMethod('beginTransaction'))->toBeFalse()
        ->and(DB::scalar('select count(*) from legacy_rows', [], 'legacy_4_0'))->toBe(1);
});

it('builds an effective PostgreSQL DSN for every accepted connection option', function (): void {
    $config = ConnectionConfig::fromArray([
        'driver' => 'pgsql',
        'host' => 'db.internal',
        'port' => 5433,
        'database' => 'billing',
        'username' => 'app',
        'charset' => 'UTF8',
        'schema' => 'tenant_42',
        'timeout' => 7,
        'sslmode' => 'verify-full',
    ]);

    $method = new ReflectionMethod(PostgreSQLDriver::class, 'buildDsn');
    $dsn = $method->invoke(new PostgreSQLDriver(), $config->toArray(), false);

    expect($dsn)->toBe(
        'pgsql:host=db.internal;port=5433;dbname=billing'
        . ';connect_timeout=7;client_encoding=UTF8'
        . ";options='-csearch_path=tenant_42';sslmode=verify-full",
    );
});

it('builds SQL Server DSNs with explicit transport and routing policy', function (): void {
    $config = ConnectionConfig::fromArray([
        'driver' => 'mssql',
        'host' => 'sql.internal',
        'port' => 1444,
        'database' => 'billing',
        'username' => 'app',
        'timeout' => 7,
        'encrypt' => true,
        'trust_server_certificate' => false,
        'application_intent' => 'ReadWrite',
        'security' => [
            'require_tls' => true,
        ],
    ]);

    $method = new ReflectionMethod(SQLServerDriver::class, 'buildDsn');
    $driver = new SQLServerDriver();

    expect($method->invoke($driver, $config->toArray(), false))->toBe(
        'sqlsrv:Server=sql.internal,1444;Database=billing;Encrypt=yes;TrustServerCertificate=no;ApplicationIntent=ReadWrite;LoginTimeout=7',
    )->and($method->invoke($driver, $config->toArray(), true))->toContain(
        'ApplicationIntent=ReadOnly',
    );
});

it('enables native SQL Server numeric result types when the driver supports them', function (): void {
    $method = new ReflectionMethod(SQLServerDriver::class, 'defaultPdoOptions');
    $options = $method->invoke(new SQLServerDriver(), []);

    expect($options[PDO::ATTR_STRINGIFY_FETCHES] ?? null)->toBeFalse();

    if (defined('PDO::SQLSRV_ATTR_FETCHES_NUMERIC_TYPE')) {
        expect($options[constant('PDO::SQLSRV_ATTR_FETCHES_NUMERIC_TYPE')] ?? null)->toBeTrue();
    }
});

it('enforces SQL Server TLS and application-intent configuration', function (array $override): void {
    expect(static fn(): ConnectionConfig => ConnectionConfig::fromArray(array_replace_recursive([
        'driver' => 'mssql',
        'database' => 'app',
        'username' => 'app',
    ], $override)))->toThrow(ConnectionException::class);
})->with([
    'unencrypted required transport' => [[
        'encrypt' => false,
        'security' => ['require_tls' => true],
    ]],
    'unknown application intent' => [[
        'application_intent' => 'NearestReplica',
    ]],
]);

it('validates PostgreSQL DSN tokens before interpolation', function (
    string $key,
    string $value,
): void {
    expect(static fn(): ConnectionConfig => ConnectionConfig::fromArray([
        'driver' => 'pgsql',
        'database' => 'app',
        'username' => 'app',
        $key => $value,
    ]))->toThrow(ConnectionException::class);
})->with([
    'schema injection' => ['schema', "public';-cstatement_timeout=0"],
    'charset injection' => ['charset', 'UTF8;sslmode=disable'],
    'unknown SSL mode' => ['sslmode', 'opportunistic'],
]);

it('maps MySQL collation and TLS settings to constructor-only PDO attributes', function (): void {
    if (!class_exists(Mysql::class)) {
        test()->markTestSkipped('pdo_mysql is not installed.');
    }

    $config = ConnectionConfig::fromArray([
        'driver' => 'mysql',
        'database' => 'app',
        'username' => 'app',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_0900_ai_ci',
        'ssl_ca' => '/run/secrets/mysql-ca.pem',
        'ssl_cert' => '/run/secrets/mysql-client.pem',
        'ssl_key' => '/run/secrets/mysql-client-key.pem',
        'ssl_verify_server_cert' => true,
        'security' => [
            'require_tls' => true,
        ],
    ]);

    $method = new ReflectionMethod(MySQLDriver::class, 'defaultPdoOptions');
    $options = $method->invoke(new MySQLDriver(), $config->toArray());

    expect($options[Mysql::ATTR_INIT_COMMAND] ?? null)
        ->toBe('SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci')
        ->and($options[Mysql::ATTR_SSL_CA] ?? null)->toBe('/run/secrets/mysql-ca.pem')
        ->and($options[Mysql::ATTR_SSL_CERT] ?? null)->toBe('/run/secrets/mysql-client.pem')
        ->and($options[Mysql::ATTR_SSL_KEY] ?? null)->toBe('/run/secrets/mysql-client-key.pem')
        ->and($options[Mysql::ATTR_SSL_VERIFY_SERVER_CERT] ?? null)->toBeTrue();
});

it('accepts only null or sufficiently long cursor signing keys', function (): void {
    $config = ConnectionConfig::fromArray([
        'driver' => 'sqlite',
        'security' => [
            'cursor_signing_key' => str_repeat('k', 32),
        ],
    ]);

    expect($config->securityConfig()['cursor_signing_key'] ?? null)->toBe(str_repeat('k', 32));

    expect(static fn(): ConnectionConfig => ConnectionConfig::fromArray([
        'driver' => 'sqlite',
        'security' => [
            'cursor_signing_key' => 'too-short',
        ],
    ]))->toThrow(ConnectionException::class, 'at least 32 bytes');
});

it('rejects invalid strategies replicas security limits and allowlist regexes', function (array $override): void {
    expect(fn() => new ConnectionConfig(array_replace_recursive([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ], $override)))->toThrow(ConnectionException::class);
})->with([
    'unknown read strategy' => [['read_strategy' => 'fastest']],
    'non-positive replica weight' => [['read' => [['database' => ':memory:', 'weight' => 0]]]],
    'malformed replica entry' => [['read' => ['not-a-replica']]],
    'zero maximum parameters' => [['security' => ['max_params' => 0]]],
    'negative maximum binding bytes' => [['security' => ['max_param_bytes' => -1]]],
    'invalid raw allowlist regex' => [[
        'security' => [
            'raw_sql_policy' => 'allowlist',
            'raw_sql_allowlist' => ['/[invalid/'],
        ],
    ]],
]);
