<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Connection\Concerns;

use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\DBLayer\Connection\ReadReplicaSessionPolicy;
use Infocyph\DBLayer\Connection\SqlStatementInspector;
use Infocyph\DBLayer\Driver\Support\DriverProfile;
use Infocyph\DBLayer\Exceptions\ConnectionException;
use Infocyph\DBLayer\Exceptions\SecurityException;
use Infocyph\DBLayer\Grammar\Grammar;
use Infocyph\DBLayer\Query\Executor;
use Infocyph\DBLayer\Security\Security;
use Infocyph\DBLayer\Transaction\TransactionManager;
use PDO;
use PDOException;
use PDOStatement;

trait ConnectionInternals
{
    /**
     * Apply MySQL/MariaDB statement timeout (best effort, version-dependent).
     */
    private function applyMySqlStatementTimeout(PDO $pdo, int $timeoutMs): void
    {
        $value = max(0, $timeoutMs);

        try {
            $pdo->exec('set session max_execution_time = ' . $value);

            return;
        } catch (PDOException) {
            // MariaDB fallback.
        }

        $seconds = max(0.0, $value / 1_000.0);
        $secondsLiteral = number_format($seconds, 3, '.', '');

        try {
            $pdo->exec('set session max_statement_time = ' . $secondsLiteral);
        } catch (PDOException) {
            // Ignore unsupported server variables.
        }
    }

    /**
     * Apply PostgreSQL statement timeout (milliseconds).
     */
    private function applyPgSqlStatementTimeout(PDO $pdo, int $timeoutMs): void
    {
        $value = max(0, $timeoutMs);

        try {
            $pdo->exec('set statement_timeout = ' . $value);
        } catch (PDOException) {
            // Ignore when not supported by server role/config.
        }
    }

    /**
     * Apply best-effort driver-native statement timeout for one PDO handle.
     */
    private function applyServerSideTimeoutToPdo(?PDO $pdo): void
    {
        if (!$pdo instanceof PDO) {
            return;
        }

        $timeoutMs = $this->queryTimeoutMs;
        $driver = $this->config->getDriver();

        if ($driver === 'mysql') {
            $this->applyMySqlStatementTimeout($pdo, $timeoutMs ?? 0);

            return;
        }

        if ($driver === 'pgsql') {
            $this->applyPgSqlStatementTimeout($pdo, $timeoutMs ?? 0);

            return;
        }

        if ($driver === 'sqlite') {
            $this->applySqliteBusyTimeout($pdo, $timeoutMs ?? 0);
        }
    }

    /**
     * Apply SQLite busy timeout (lock-wait timeout, best effort).
     */
    private function applySqliteBusyTimeout(PDO $pdo, int $timeoutMs): void
    {
        $value = max(0, $timeoutMs);

        try {
            $pdo->exec('pragma busy_timeout = ' . $value);
        } catch (PDOException) {
            // Ignore.
        }
    }

    /**
     * Fail fast when cooperative cancellation requests query abort.
     */
    private function assertNotCancelled(): void
    {
        if ($this->queryCancellationChecker === null) {
            return;
        }

        if (($this->queryCancellationChecker)()) {
            throw ConnectionException::queryCancelled();
        }
    }

    /**
     * Fail when active timeout/deadline budgets are exceeded.
     */
    private function assertWithinQueryBudget(float $startedAt): void
    {
        $deadlineAt = $this->resolveEffectiveDeadlineAt($startedAt);

        if ($deadlineAt === null) {
            return;
        }

        if (microtime(true) <= $deadlineAt) {
            return;
        }

        throw ConnectionException::queryTimeout(microtime(true) - $startedAt);
    }

    /**
     * Return healthy read-replica indexes based on cooldown windows.
     *
     * @return list<int>
     */
    private function availableReadReplicaIndexes(int $count): array
    {
        $now = \time();
        $available = [];

        for ($index = 0; $index < $count; $index++) {
            $retryAt = $this->readReplicaUnavailableUntil[$index] ?? null;

            if ($retryAt === null || $retryAt <= $now) {
                unset($this->readReplicaUnavailableUntil[$index]);
                $available[] = $index;
            }
        }

        return $available;
    }

    /**
     * Build probe order for read replicas respecting strategy and health suppression.
     *
     * @param list<array<string,mixed>> $readConfigs
     * @return list<int>
     */
    private function buildReadReplicaProbeOrder(array $readConfigs, string $strategy): array
    {
        $count = \count($readConfigs);
        if ($count <= 1) {
            return [0];
        }

        $available = $this->availableReadReplicaIndexes($count);
        $fallback = \array_values(\array_diff(\range(0, $count - 1), $available));
        $pool = $available !== [] ? $available : \range(0, $count - 1);

        $primary = match ($strategy) {
            'round_robin' => $this->nextRoundRobinIndexFrom($pool),
            'weighted' => $this->selectWeightedReadReplicaIndex($pool, $readConfigs),
            default => $pool[random_int(0, \count($pool) - 1)],
        };

        $rest = \array_values(\array_diff($pool, [$primary]));

        if (\count($rest) > 1) {
            \shuffle($rest);
        }

        if ($fallback !== []) {
            if (\count($fallback) > 1) {
                \shuffle($fallback);
            }

            $rest = \array_merge($rest, $fallback);
        }

        return \array_values(\array_unique(\array_merge([$primary], $rest)));
    }

    /**
     * Establish write database connection via the driver.
     */
    private function connect(): void
    {
        try {
            $config = $this->resolveWriteConnectionConfig();
            $this->pdo = $this->driver->createPdo($config, false);
            $this->applyServerSideTimeoutToPdo($this->pdo);
        } catch (PDOException $e) {
            throw ConnectionException::connectionFailed(
                $this->config->getDriver(),
                $e->getMessage(),
            );
        }
    }

    /**
     * Establish read replica connection via the driver.
     */
    private function connectRead(): void
    {
        $readConfigs = $this->config->getReadConfigs();

        if ($readConfigs === []) {
            $this->readPdo = null;
            $this->readReplicaIndex = null;
            $this->readReplicaLatenciesMs = [];

            return;
        }

        try {
            [$index, $pdo] = $this->resolveReadReplicaPdo($readConfigs);
            $this->readReplicaIndex = $index;
            $this->readPdo = $pdo;
            $this->applyServerSideTimeoutToPdo($this->readPdo);
            ReadReplicaSessionPolicy::apply($this->config->getDriver(), $this->readPdo);
        } catch (PDOException|ConnectionException) {
            // Silent fallback to write connection; readPdo stays null.
            $this->readPdo = null;
            $this->readReplicaIndex = null;
        }
    }

    /**
     * Create an inert statement handle used in pretend mode.
     */
    private function createPretendStatement(bool $isWrite): PDOStatement
    {
        $sql = $isWrite ? 'select 0 as affected' : 'select 1 where 0 = 1';
        $statement = self::pretendPdo()->prepare($sql);
        $statement->execute();

        return $statement;
    }

    /**
     * Build one read-replica PDO from an override config fragment.
     *
     * @param array<string,mixed> $readConfig
     */
    private function createReadReplicaPdo(array $readConfig): PDO
    {
        $merged = array_merge($this->config->toArray(), $readConfig);
        unset($merged['read'], $merged['write']);
        $config = ConnectionConfig::fromArray($merged);

        return $this->driver->createPdo($config, true);
    }

    /**
     * @param array{queries_per_second:int,queries_per_minute:int} $limits
     */
    private function enforceCustomRateLimiter(
        mixed $customLimiter,
        string $identifier,
        array $limits,
        int $perSecond,
        int $perMinute,
    ): bool {
        if (!is_callable($customLimiter)) {
            return false;
        }

        $allowed = $customLimiter($identifier, $limits);

        if ($allowed !== false) {
            return true;
        }

        $ttl = $perSecond > 0 ? 1 : 60;
        $max = $perSecond > 0 ? $perSecond : $perMinute;

        throw SecurityException::rateLimitExceeded($identifier, max(1, $max), $ttl);
    }

    /**
     * Apply configured query-rate limits when limits are enabled for this connection.
     *
     * @param array<string,mixed> $securityConfig
     */
    private function enforceRateLimitIfConfigured(array $securityConfig): void
    {
        $perSecond = $this->resolveRateLimitValue($securityConfig['queries_per_second'] ?? null);
        $perMinute = $this->resolveRateLimitValue($securityConfig['queries_per_minute'] ?? null);

        if ($perSecond <= 0 && $perMinute <= 0) {
            return;
        }

        $identifier = $this->resolveRateLimitIdentifier($securityConfig);
        $limits = [
            'queries_per_second' => max(0, $perSecond),
            'queries_per_minute' => max(0, $perMinute),
        ];

        $customLimiter = $securityConfig['rate_limit_callback'] ?? null;

        if ($this->enforceCustomRateLimiter($customLimiter, $identifier, $limits, $perSecond, $perMinute)) {
            return;
        }

        Security::checkRateLimit(
            $identifier,
            $limits,
        );
    }

    /**
     * Get the query executor for this connection (legacy).
     */
    private function getExecutor(): Executor
    {
        if ($this->executor === null) {
            $this->executor = new Executor($this, $this->getGrammar());
        }

        return $this->executor;
    }

    /**
     * Get the grammar instance for this connection (legacy).
     */
    private function getGrammar(): Grammar
    {
        if ($this->grammar !== null) {
            return $this->grammar;
        }

        $driverName = $this->config->getDriver();
        $grammar = DriverProfile::createGrammar($driverName);

        if ($this->tablePrefix !== '') {
            $grammar->setTablePrefix($this->tablePrefix);
        }

        $this->grammar = $grammar;

        return $this->grammar;
    }

    /**
     * Get PDO parameter type.
     */
    private function getParameterType(mixed $value): int
    {
        return match (true) {
            is_int($value) => PDO::PARAM_INT,
            is_bool($value) => PDO::PARAM_BOOL,
            $value === null => PDO::PARAM_NULL,
            is_resource($value) => PDO::PARAM_LOB,
            default => PDO::PARAM_STR,
        };
    }

    /**
     * Get transaction manager for this connection.
     */
    private function getTransactionManager(): TransactionManager
    {
        if ($this->transactionManager === null) {
            $this->transactionManager = new TransactionManager();
        }

        return $this->transactionManager;
    }

    /**
     * Decide which connection to reconnect based on the PDO instance.
     */
    private function handleReconnectForPdo(PDO $pdo): void
    {
        $isRead = ($this->readPdo !== null && $pdo === $this->readPdo);

        $this->reconnect(!$isRead);
    }

    /**
     * Classify PDOExceptions that look like connection errors.
     */
    private function isConnectionError(PDOException $e): bool
    {
        $info = $e->errorInfo;

        if (is_array($info) && isset($info[0]) && is_string($info[0])) {
            $sqlState = $info[0];

            if (str_starts_with($sqlState, '08')) {
                return true;
            }
        }

        $code = (string) $e->getCode();

        // MySQL connection-related errors.
        if (in_array($code, ['2002', '2006', '2013'], true)) {
            return true;
        }

        // PostgreSQL connection-related errors.
        if (in_array($code, ['7', '57P01', '57P02', '57P03'], true)) {
            return true;
        }

        return false;
    }

    /**
     * Determine if query is a write operation.
     */
    private function isWriteQuery(string $sql): bool
    {
        $firstWord = SqlStatementInspector::leadingStatementKeyword($sql);

        if ($firstWord === '') {
            return false;
        }

        return in_array(
            $firstWord,
            ['INSERT', 'UPDATE', 'DELETE', 'CREATE', 'ALTER', 'DROP', 'TRUNCATE', 'REPLACE'],
            true,
        );
    }

    /**
     * Mark a read replica as unavailable for a cooldown period.
     */
    private function markReadReplicaFailure(int $index): void
    {
        $cooldownSeconds = $this->config->getReadHealthCooldown();

        if ($cooldownSeconds <= 0) {
            return;
        }

        $this->readReplicaUnavailableUntil[$index] = \time() + $cooldownSeconds;
    }

    /**
     * Pick next round-robin replica from an explicit index pool.
     *
     * @param list<int> $indexes
     */
    private function nextRoundRobinIndexFrom(array $indexes): int
    {
        $count = \count($indexes);
        if ($count === 0) {
            return 0;
        }

        $slot = $this->readReplicaCursor % $count;
        $this->readReplicaCursor = ($slot + 1) % $count;

        return $indexes[$slot];
    }

    /**
     * Record a performance sample into HealthCheck, if attached.
     */
    private function recordPerformanceSample(float $durationMs, bool $success): void
    {
        if ($this->healthCheck !== null) {
            $this->healthCheck->recordSample($durationMs, $success);
        }
    }

    /**
     * Record a query for pretend mode if enabled.
     *
     * @param array<int|string,mixed> $bindings
     */
    private function recordPretend(string $sql, array $bindings): void
    {
        if ($this->queryRecorder !== null) {
            ($this->queryRecorder)($sql, $bindings);
        }
    }

    /**
     * Record query statistics based on read/write classification.
     */
    private function recordQuery(bool $isWrite): void
    {
        $this->stats['queries']++;

        if ($isWrite) {
            $this->stats['writes']++;
            $this->recordsModified = true;
        } else {
            $this->stats['reads']++;
        }
    }

    /**
     * Resolve the effective query deadline from absolute and relative budgets.
     */
    private function resolveEffectiveDeadlineAt(float $startedAt): ?float
    {
        $deadlineAt = $this->queryDeadlineAt;

        if ($this->queryTimeoutMs === null) {
            return $deadlineAt;
        }

        $relativeDeadline = $startedAt + ($this->queryTimeoutMs / 1_000.0);

        if ($deadlineAt === null) {
            return $relativeDeadline;
        }

        return min($deadlineAt, $relativeDeadline);
    }

    /**
     * Resolve the fastest healthy read replica by probing each replica.
     *
     * @param list<array<string,mixed>> $readConfigs
     * @return array{0:int,1:PDO}
     */
    private function resolveLeastLatencyReadReplica(array $readConfigs): array
    {
        $bestIndex = null;
        $bestPdo = null;
        $bestLatency = \INF;
        $latencies = [];
        $indexes = $this->availableReadReplicaIndexes(\count($readConfigs));

        if ($indexes === []) {
            $indexes = \range(0, \count($readConfigs) - 1);
        }

        foreach ($indexes as $index) {
            $readConfig = $readConfigs[$index];

            try {
                $probeStart = microtime(true);
                $pdo = $this->createReadReplicaPdo($readConfig);
                $pdo->query('SELECT 1');
                $latencyMs = (microtime(true) - $probeStart) * 1_000.0;

                $latencies[$index] = round($latencyMs, 4);
                unset($this->readReplicaUnavailableUntil[$index]);

                if ($latencyMs < $bestLatency) {
                    $bestLatency = $latencyMs;
                    $bestIndex = $index;
                    $bestPdo = $pdo;
                }
            } catch (PDOException|ConnectionException) {
                $this->markReadReplicaFailure($index);

                continue;
            }
        }

        $this->readReplicaLatenciesMs = $latencies;

        if ($bestIndex === null || !$bestPdo instanceof PDO) {
            throw ConnectionException::connectionFailed(
                $this->config->getDriver(),
                'No healthy read replica available for least_latency strategy.',
            );
        }

        return [$bestIndex, $bestPdo];
    }

    /**
     * Resolve logical rate-limit identifier for this connection.
     *
     * @param array<string,mixed> $securityConfig
     */
    private function resolveRateLimitIdentifier(array $securityConfig): string
    {
        $custom = $securityConfig['rate_limit_key'] ?? null;
        if (is_string($custom) && trim($custom) !== '') {
            return trim($custom);
        }

        $rawHost = $this->config->get('host');
        $host = is_string($rawHost) && $rawHost !== '' ? $rawHost : 'local';
        $database = $this->config->getDatabase();
        $pid = (string) (\getmypid() ?: '0');

        return strtolower(
            implode(':', [
                'dblayer',
                $this->config->getDriver(),
                $host,
                $database,
                $pid,
            ]),
        );
    }

    private function resolveRateLimitValue(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Resolve one read-replica PDO using configured read strategy.
     *
     * @param list<array<string,mixed>> $readConfigs
     * @return array{0:int,1:PDO}
     */
    private function resolveReadReplicaPdo(array $readConfigs): array
    {
        $strategy = $this->config->getReadStrategy();

        if ($strategy === 'least_latency') {
            return $this->resolveLeastLatencyReadReplica($readConfigs);
        }

        $this->readReplicaLatenciesMs = [];
        $probeOrder = $this->buildReadReplicaProbeOrder($readConfigs, $strategy);

        foreach ($probeOrder as $index) {
            try {
                $pdo = $this->createReadReplicaPdo($readConfigs[$index]);
                unset($this->readReplicaUnavailableUntil[$index]);

                return [$index, $pdo];
            } catch (PDOException|ConnectionException) {
                $this->markReadReplicaFailure($index);

                continue;
            }
        }

        throw ConnectionException::connectionFailed(
            $this->config->getDriver(),
            'No healthy read replica available.',
        );
    }

    /**
     * Resolve effective write connection config with optional write overrides.
     */
    private function resolveWriteConnectionConfig(): ConnectionConfig
    {
        $writeConfigs = $this->config->getWriteConfigs();

        if ($writeConfigs === []) {
            return $this->config;
        }

        $selected = $writeConfigs[random_int(0, \count($writeConfigs) - 1)];
        $merged = array_merge($this->config->toArray(), $selected);
        unset($merged['read'], $merged['write']);

        return ConnectionConfig::fromArray($merged);
    }

    /**
     * Execute a prepared statement on a given PDO instance.
     *
     * @param array<int|string,mixed> $bindings
     */
    private function runStatement(PDO $pdo, string $sql, array $bindings): PDOStatement
    {
        $statement = $pdo->prepare($sql);
        $resourceBindings = [];

        foreach ($bindings as $key => $value) {
            $parameter = is_int($key) ? $key + 1 : $key;

            if (is_resource($value)) {
                $resourceBindings[] = $value;
                $resourceIndex = \count($resourceBindings) - 1;
                $statement->bindParam($parameter, $resourceBindings[$resourceIndex], PDO::PARAM_LOB);

                continue;
            }

            $statement->bindValue($parameter, $value, $this->getParameterType($value));
        }

        $statement->execute();

        return $statement;
    }

    /**
     * Pick a replica index based on configured weights.
     *
     * @param list<int> $indexes
     * @param list<array<string,mixed>>|null $readConfigs
     */
    private function selectWeightedReadReplicaIndex(array $indexes, ?array $readConfigs = null): int
    {
        if ($indexes === []) {
            return 0;
        }

        $weights = [];
        $totalWeight = 0;

        foreach ($indexes as $index) {
            $weight = 1;

            if ($readConfigs !== null && isset($readConfigs[$index]['weight'])) {
                $rawWeight = $readConfigs[$index]['weight'];

                if (\is_numeric($rawWeight)) {
                    $weight = max(1, (int) $rawWeight);
                }
            }

            $weights[$index] = $weight;
            $totalWeight += $weight;
        }

        $ticket = random_int(1, $totalWeight);

        foreach ($weights as $index => $weight) {
            $ticket -= $weight;

            if ($ticket <= 0) {
                return (int) $index;
            }
        }

        return $indexes[\count($indexes) - 1];
    }

    /**
     * Decide whether a failed connection-level query attempt should be retried.
     *
     * @param array<int|string,mixed> $bindings
     */
    private function shouldRetryQuery(PDOException $e, int $attempt, string $sql, array $bindings): bool
    {
        if ($attempt >= self::MAX_QUERY_RETRY_ATTEMPTS) {
            return false;
        }

        if ($this->queryRetryPolicy !== null) {
            return (bool) ($this->queryRetryPolicy)($e, $attempt, $sql, $bindings);
        }

        // Backward-compatible default: a single reconnect retry.
        return $attempt < 2;
    }

    /**
     * Determine whether reads should use the write PDO.
     */
    private function shouldUseWritePdoForRead(): bool
    {
        if ($this->getTransactionManager()->level($this) > 0) {
            return true;
        }

        if (!$this->config->isSticky()) {
            return false;
        }

        return $this->recordsModified;
    }

    /**
     * Synchronize server-side statement timeout for already-open PDO handles.
     */
    private function syncServerSideStatementTimeouts(): void
    {
        $this->applyServerSideTimeoutToPdo($this->pdo);
        $this->applyServerSideTimeoutToPdo($this->readPdo);
    }
}
