<?php

declare(strict_types=1);

namespace Infocyph\DBLayer;

use PDO;
use PDOException;
use PDOStatement;

/**
 * Database connection manager with pooling and read/write split support
 */
class Connection
{
    /**
     * PDO instances
     */
    private ?PDO $pdo = null;
    private ?PDO $readPdo = null;
    private ?PDO $writePdo = null;

    /**
     * Connection configuration
     */
    private array $config;
    private string $driver;

    /**
     * Read/write split configuration
     */
    private bool $readWriteSplit = false;
    private array $readConfigs = [];
    private array $writeConfig = [];
    private string $loadBalancer = 'random';
    private int $currentReadIndex = 0;

    /**
     * Connection pool
     */
    private static array $pool = [];

    /**
     * Sticky reads (use write connection after write)
     */
    private bool $recordsModified = false;
    private bool $stickyReads = true;

    /**
     * Connection attempts and timeout
     */
    private int $maxAttempts = 3;
    private int $timeout = 5;

    /**
     * Secure PDO options
     */
    private const SECURE_PDO_OPTIONS = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];

    /**
     * Driver defaults
     */
    private const DRIVER_DEFAULTS = [
        'mysql' => [
            'port' => 3306,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'options' => [
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
            ],
        ],
        'pgsql' => [
            'port' => 5432,
            'charset' => 'utf8',
            'schema' => 'public',
            'options' => [],
        ],
        'sqlite' => [
            'options' => [
                PDO::ATTR_TIMEOUT => 5,
            ],
        ],
    ];

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->driver = $config['driver'] ?? 'mysql';

        // Setup read/write split if configured
        if (isset($config['read']) && isset($config['write'])) {
            $this->setupReadWriteSplit($config);
        }

        // Connection options
        $this->maxAttempts = $config['max_attempts'] ?? 3;
        $this->timeout = $config['timeout'] ?? 5;
        $this->stickyReads = $config['sticky'] ?? true;
    }

    /**
     * Setup read/write split configuration
     */
    private function setupReadWriteSplit(array $config): void
    {
        $this->readWriteSplit = true;
        $this->writeConfig = array_merge($config, $config['write']);
        
        // Support multiple read replicas
        $reads = is_array($config['read']) && isset($config['read'][0]) 
            ? $config['read'] 
            : [$config['read']];

        foreach ($reads as $readConfig) {
            $this->readConfigs[] = array_merge($config, $readConfig);
        }

        $this->loadBalancer = $config['read_balancer'] ?? 'random';
    }

    /**
     * Get PDO connection
     */
    public function getPdo(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = $this->createConnection($this->config);
        }

        return $this->pdo;
    }

    /**
     * Get read PDO connection
     */
    public function getReadPdo(): PDO
    {
        if (!$this->readWriteSplit) {
            return $this->getPdo();
        }

        if ($this->readPdo === null) {
            $config = $this->selectReadConfig();
            $this->readPdo = $this->createConnection($config);
        }

        return $this->readPdo;
    }

    /**
     * Get write PDO connection
     */
    public function getWritePdo(): PDO
    {
        if (!$this->readWriteSplit) {
            return $this->getPdo();
        }

        if ($this->writePdo === null) {
            $this->writePdo = $this->createConnection($this->writeConfig);
        }

        return $this->writePdo;
    }

    /**
     * Select read configuration based on load balancing strategy
     */
    private function selectReadConfig(): array
    {
        if (count($this->readConfigs) === 1) {
            return $this->readConfigs[0];
        }

        return match ($this->loadBalancer) {
            'round-robin' => $this->roundRobinRead(),
            'random' => $this->randomRead(),
            default => $this->readConfigs[0]
        };
    }

    /**
     * Round-robin read selection
     */
    private function roundRobinRead(): array
    {
        $config = $this->readConfigs[$this->currentReadIndex];
        $this->currentReadIndex = ($this->currentReadIndex + 1) % count($this->readConfigs);
        return $config;
    }

    /**
     * Random read selection
     */
    private function randomRead(): array
    {
        return $this->readConfigs[array_rand($this->readConfigs)];
    }

    /**
     * Create PDO connection
     */
    private function createConnection(array $config): PDO
    {
        $attempts = 0;

        while ($attempts < $this->maxAttempts) {
            try {
                $dsn = $this->buildDsn($config);
                $options = $this->getOptions($config);

                $pdo = new PDO(
                    $dsn,
                    $config['username'] ?? null,
                    $config['password'] ?? null,
                    $options
                );

                // Post-connection commands
                $this->afterConnection($pdo, $config);

                return $pdo;
            } catch (PDOException $e) {
                $attempts++;

                if ($attempts >= $this->maxAttempts) {
                    throw new ConnectionException(
                        "Failed to connect to database after {$this->maxAttempts} attempts: " . $e->getMessage(),
                        (int) $e->getCode(),
                        $e
                    );
                }

                usleep(100000 * $attempts); // Exponential backoff
            }
        }

        throw new ConnectionException("Failed to establish database connection");
    }

    /**
     * Build DSN string
     */
    private function buildDsn(array $config): string
    {
        $driver = $config['driver'] ?? $this->driver;

        return match ($driver) {
            'mysql' => $this->buildMySqlDsn($config),
            'pgsql' => $this->buildPgSqlDsn($config),
            'sqlite' => $this->buildSqliteDsn($config),
            default => throw new InvalidArgumentException("Unsupported driver: {$driver}")
        };
    }

    /**
     * Build MySQL DSN
     */
    private function buildMySqlDsn(array $config): string
    {
        $defaults = self::DRIVER_DEFAULTS['mysql'];
        $port = $config['port'] ?? $defaults['port'];
        $charset = $config['charset'] ?? $defaults['charset'];

        $dsn = "mysql:host={$config['host']};port={$port};dbname={$config['database']};charset={$charset}";

        if (isset($config['unix_socket'])) {
            $dsn = "mysql:unix_socket={$config['unix_socket']};dbname={$config['database']};charset={$charset}";
        }

        return $dsn;
    }

    /**
     * Build PostgreSQL DSN
     */
    private function buildPgSqlDsn(array $config): string
    {
        $defaults = self::DRIVER_DEFAULTS['pgsql'];
        $port = $config['port'] ?? $defaults['port'];

        $dsn = "pgsql:host={$config['host']};port={$port};dbname={$config['database']}";

        if (isset($config['schema'])) {
            $dsn .= ";options='--search_path={$config['schema']}'";
        }

        return $dsn;
    }

    /**
     * Build SQLite DSN
     */
    private function buildSqliteDsn(array $config): string
    {
        $database = $config['database'] ?? ':memory:';
        return "sqlite:{$database}";
    }

    /**
     * Get PDO options
     */
    private function getOptions(array $config): array
    {
        $driver = $config['driver'] ?? $this->driver;
        $defaults = self::DRIVER_DEFAULTS[$driver]['options'] ?? [];
        $custom = $config['options'] ?? [];

        return array_replace(self::SECURE_PDO_OPTIONS, $defaults, $custom);
    }

    /**
     * After connection hook
     */
    private function afterConnection(PDO $pdo, array $config): void
    {
        $driver = $config['driver'] ?? $this->driver;

        if ($driver === 'mysql' && isset($config['timezone'])) {
            $pdo->exec("SET time_zone = '{$config['timezone']}'");
        }

        if ($driver === 'mysql' && isset($config['modes'])) {
            $modes = implode(',', (array) $config['modes']);
            $pdo->exec("SET SESSION sql_mode = '{$modes}'");
        }
    }

    /**
     * Reconnect
     */
    public function reconnect(): void
    {
        $this->disconnect();
        $this->pdo = null;
        $this->readPdo = null;
        $this->writePdo = null;
    }

    /**
     * Disconnect
     */
    public function disconnect(): void
    {
        $this->pdo = null;
        $this->readPdo = null;
        $this->writePdo = null;
    }

    /**
     * Check if records have been modified
     */
    public function recordsHaveBeenModified(): void
    {
        if ($this->readWriteSplit && $this->stickyReads) {
            $this->recordsModified = true;
        }
    }

    /**
     * Check if should use write connection for reads
     */
    public function shouldUseWriteConnection(): bool
    {
        return $this->readWriteSplit && 
               ($this->recordsModified || $this->inTransaction());
    }

    /**
     * Check if in transaction
     */
    public function inTransaction(): bool
    {
        return $this->getPdo()->inTransaction();
    }

    /**
     * Get driver name
     */
    public function getDriver(): string
    {
        return $this->driver;
    }

    /**
     * Get database name
     */
    public function getDatabaseName(): string
    {
        return $this->config['database'] ?? '';
    }

    /**
     * Get last insert ID
     */
    public function lastInsertId(?string $name = null): string
    {
        return $this->getPdo()->lastInsertId($name);
    }

    /**
     * Connection pooling - Get or create connection
     */
    public static function pool(string $name, array $config): self
    {
        if (!isset(self::$pool[$name])) {
            self::$pool[$name] = new self($config);
        }

        return self::$pool[$name];
    }

    /**
     * Get connection from pool
     */
    public static function get(string $name = 'default'): self
    {
        if (!isset(self::$pool[$name])) {
            throw new ConnectionException("Connection '{$name}' not found in pool");
        }

        return self::$pool[$name];
    }

    /**
     * Remove connection from pool
     */
    public static function removeFromPool(string $name): void
    {
        if (isset(self::$pool[$name])) {
            self::$pool[$name]->disconnect();
            unset(self::$pool[$name]);
        }
    }

    /**
     * Clear connection pool
     */
    public static function clearPool(): void
    {
        foreach (self::$pool as $connection) {
            $connection->disconnect();
        }
        self::$pool = [];
    }
}
