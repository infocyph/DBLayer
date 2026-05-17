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
use Throwable;

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
     * Build an optional SQL comment prefix for observability context.
     */
    private function applyQueryComment(string $sql): string
    {
        if (!$this->config->shouldUseQueryComments()) {
            return $sql;
        }

        $context = $this->normalizeCommentContext($this->queryCommentContext);

        if ($context === []) {
            return $sql;
        }

        $parts = [];

        foreach ($context as $key => $value) {
            $normalized = $this->normalizeCommentValue($value);

            if ($normalized === '') {
                continue;
            }

            $parts[] = $key . '=' . $normalized;
        }

        if ($parts === []) {
            return $sql;
        }

        $payload = implode(' ', $parts);
        $maxLength = $this->config->getQueryCommentMaxLength();

        if (strlen($payload) > $maxLength) {
            $payload = substr($payload, 0, $maxLength);
        }

        return '/* ' . $payload . ' */ ' . $sql;
    }

    /**
     * Apply best-effort read-only transaction mode for current transaction.
     */
    private function applyReadOnlyTransactionMode(): void
    {
        $driver = strtolower($this->config->getDriver());

        if ($driver === 'sqlite') {
            // SQLite has no transaction-scoped read-only switch; best effort is no-op.
            return;
        }

        $pdo = $this->getPdo();

        try {
            if ($driver === 'pgsql') {
                $pdo->exec('set transaction read only');

                return;
            }

            if ($driver === 'mysql' || $driver === 'mariadb') {
                $pdo->exec('set transaction read only');

                return;
            }

        } catch (PDOException) {
            // Best effort only.
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
     * Clear statement cache for all read/write buckets.
     */
    private function clearStatementCache(): void
    {
        $this->clearStatementCacheBucket(true);
        $this->clearStatementCacheBucket(false);
    }

    /**
     * Clear statement cache for one bucket.
     */
    private function clearStatementCacheBucket(bool $isWrite): void
    {
        if ($isWrite) {
            $this->statementCache['write'] = [];
            $this->statementCacheLru['write'] = [];
            $this->statementCachePdoIds['write'] = null;

            return;
        }

        $this->statementCache['read'] = [];
        $this->statementCacheLru['read'] = [];
        $this->statementCachePdoIds['read'] = null;
    }

    /**
     * Establish write database connection via the driver.
     */
    private function connect(): void
    {
        $this->dispatchBeforeConnect(true);

        try {
            $config = $this->resolveWriteConnectionConfig();
            $pdo = $this->driver->createPdo($config, false);
            $this->pdo = $pdo;
            $this->applyServerSideTimeoutToPdo($pdo);
            $this->syncStatementCachePdoBucket(true, $pdo);
            $this->dispatchAfterConnect(true);
        } catch (PDOException $e) {
            $exception = ConnectionException::connectionFailed(
                $this->config->getDriver(),
                $e->getMessage(),
            );
            $this->dispatchConnectionFailure(true, 1, $exception);

            throw $exception;
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
            $this->clearStatementCacheBucket(false);
            $this->readReplicaIndex = null;
            $this->readReplicaLatenciesMs = [];

            return;
        }

        $this->dispatchBeforeConnect(false);

        try {
            [$index, $pdo] = $this->resolveReadReplicaPdo($readConfigs);
            $this->readReplicaIndex = $index;
            $this->readPdo = $pdo;
            $this->applyServerSideTimeoutToPdo($pdo);
            $this->syncStatementCachePdoBucket(false, $pdo);
            ReadReplicaSessionPolicy::apply(
                $this->config->getDriver(),
                $this->readPdo,
                $this->config->shouldEnforceReadSessionReadOnly(),
            );
            $this->dispatchAfterConnect(false);
        } catch (PDOException|ConnectionException $e) {
            // Silent fallback to write connection; readPdo stays null.
            $this->dispatchConnectionFailure(false, 1, $e);
            $this->readPdo = null;
            $this->clearStatementCacheBucket(false);
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
     * Trigger lifecycle hooks after connect.
     */
    private function dispatchAfterConnect(bool $isWrite): void
    {
        foreach ($this->lifecycleHooks['afterConnect'] as $hook) {
            try {
                $hook($this, $isWrite);
            } catch (Throwable) {
                // Hooks should never interrupt connection lifecycle.
            }
        }
    }

    /**
     * Trigger lifecycle hooks after reconnect.
     */
    private function dispatchAfterReconnect(bool $isWrite, int $attempt): void
    {
        foreach ($this->lifecycleHooks['afterReconnect'] as $hook) {
            try {
                $hook($this, $isWrite, $attempt);
            } catch (Throwable) {
                // Hooks should never interrupt connection lifecycle.
            }
        }
    }

    /**
     * Trigger lifecycle hooks before connect.
     */
    private function dispatchBeforeConnect(bool $isWrite): void
    {
        foreach ($this->lifecycleHooks['beforeConnect'] as $hook) {
            try {
                $hook($this, $isWrite);
            } catch (Throwable) {
                // Hooks should never interrupt connection lifecycle.
            }
        }
    }

    /**
     * Trigger lifecycle hooks before reconnect.
     */
    private function dispatchBeforeReconnect(bool $isWrite, int $attempt): void
    {
        foreach ($this->lifecycleHooks['beforeReconnect'] as $hook) {
            try {
                $hook($this, $isWrite, $attempt);
            } catch (Throwable) {
                // Hooks should never interrupt connection lifecycle.
            }
        }
    }

    /**
     * Trigger lifecycle hooks for connection failures.
     */
    private function dispatchConnectionFailure(bool $isWrite, int $attempt, Throwable $error): void
    {
        foreach ($this->lifecycleHooks['onConnectionFailure'] as $hook) {
            try {
                $hook($this, $isWrite, $attempt, $error);
            } catch (Throwable) {
                // Hooks should never interrupt connection lifecycle.
            }
        }
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
     * Evict least-recently-used statements beyond configured max size.
     */
    private function evictStatementCacheIfNeeded(bool $isWrite, int $maxSize): void
    {
        $bucket = $isWrite ? 'write' : 'read';

        while (\count($this->statementCacheLru[$bucket]) > $maxSize) {
            $oldest = array_shift($this->statementCacheLru[$bucket]);

            if (!is_string($oldest)) {
                continue;
            }

            unset($this->statementCache[$bucket][$oldest]);
        }
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
     * Determine if any bindings contain stream/resources.
     *
     * @param array<int|string,mixed> $bindings
     */
    private function hasResourceBindings(array $bindings): bool
    {
        return array_any($bindings, fn($value) => is_resource($value));
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
            [
                'INSERT',
                'UPDATE',
                'DELETE',
                'CREATE',
                'ALTER',
                'DROP',
                'TRUNCATE',
                'REPLACE',
                'MERGE',
                'CALL',
                'GRANT',
                'REVOKE',
                'ANALYZE',
                'VACUUM',
                'PRAGMA',
                'SET',
                'LOCK',
                'UNLOCK',
            ],
            true,
        );
    }

    /**
     * Mark a read replica as unavailable for a cooldown period.
     */
    private function markReadReplicaFailure(int $index): void
    {
        $cooldownSeconds = $this->config->getReadHealthCooldown();

        if ($this->leastLatencyReplicaIndex === $index) {
            $this->leastLatencyReplicaIndex = null;
            $this->leastLatencyResolvedAt = null;
        }

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
     * Normalize query comment context values to safe key/value pairs.
     *
     * @param array<string,mixed> $context
     * @return array<string,string>
     */
    private function normalizeCommentContext(array $context): array
    {
        $normalized = [];

        foreach ($context as $key => $value) {
            $cleanKey = preg_replace('/[^a-z0-9_.:-]/i', '_', trim((string) $key));

            if (!is_string($cleanKey) || $cleanKey === '') {
                continue;
            }

            $cleanValue = $this->normalizeCommentValue($value);

            if ($cleanValue === '') {
                continue;
            }

            $normalized[$cleanKey] = $cleanValue;
        }

        return $normalized;
    }

    /**
     * Normalize one query comment scalar value.
     */
    private function normalizeCommentValue(mixed $value): string
    {
        if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        } elseif ($value === null) {
            $value = 'null';
        } elseif (is_scalar($value)) {
            $value = (string) $value;
        } elseif ($value instanceof \Stringable) {
            $value = (string) $value;
        } else {
            return '';
        }

        $clean = preg_replace('/[^a-z0-9_.:@\\/-]/i', '_', trim($value));

        return is_string($clean) ? $clean : '';
    }

    /**
     * Prepare statement with optional statement cache lookup.
     *
     * @param array<int|string,mixed> $bindings
     */
    private function prepareStatementForExecution(
        PDO $pdo,
        string $sql,
        array $bindings,
        bool $isWrite,
        ?string $cacheFingerprintSql = null,
    ): PDOStatement {
        $maxSize = $this->config->statementCacheSize();

        if (
            !$this->config->shouldUseStatementCache()
            || $maxSize <= 0
            || $this->getTransactionManager()->level($this) > 0
            || $this->hasResourceBindings($bindings)
        ) {
            return $pdo->prepare($sql);
        }

        $this->syncStatementCachePdoBucket($isWrite, $pdo);
        $stableSql = $cacheFingerprintSql ?? $this->stripLeadingCommentPrefix($sql);
        $fingerprint = sha1((string) $stableSql);
        $bucket = $isWrite ? 'write' : 'read';
        $cached = $this->statementCache[$bucket][$fingerprint] ?? null;

        if ($cached instanceof PDOStatement) {
            $cached->closeCursor();
            $this->touchStatementCacheEntry($isWrite, $fingerprint);

            return $cached;
        }

        $statement = $pdo->prepare($sql);
        $this->statementCache[$bucket][$fingerprint] = $statement;
        $this->touchStatementCacheEntry($isWrite, $fingerprint);
        $this->evictStatementCacheIfNeeded($isWrite, $maxSize);

        return $statement;
    }

    /**
     * Probe a single replica and capture latency for health-based selection.
     *
     * @param array<string,mixed> $readConfig
     * @return array{latency_ms:float,pdo:PDO}|null
     */
    private function probeLeastLatencyReplica(int $index, array $readConfig): ?array
    {
        try {
            $probeStart = microtime(true);
            $pdo = $this->createReadReplicaPdo($readConfig);
            $pdo->query('SELECT 1');
            $latencyMs = (microtime(true) - $probeStart) * 1_000.0;

            unset($this->readReplicaUnavailableUntil[$index]);

            return ['latency_ms' => $latencyMs, 'pdo' => $pdo];
        } catch (PDOException|ConnectionException) {
            $this->markReadReplicaFailure($index);

            return null;
        }
    }

    /**
     * Probe replica indexes and return the fastest healthy PDO.
     *
     * @param list<int> $indexes
     * @param list<array<string,mixed>> $readConfigs
     * @param array<int,float> $latencies
     * @return array{0:int|null,1:PDO|null}
     */
    private function probeLeastLatencyReplicaIndexes(array $indexes, array $readConfigs, array &$latencies): array
    {
        $bestIndex = null;
        $bestPdo = null;
        $bestLatency = \INF;

        foreach ($indexes as $index) {
            $probe = $this->probeLeastLatencyReplica($index, $readConfigs[$index]);
            if ($probe === null) {
                continue;
            }

            $latencies[$index] = round($probe['latency_ms'], 4);

            if ($probe['latency_ms'] < $bestLatency) {
                $bestLatency = $probe['latency_ms'];
                $bestIndex = $index;
                $bestPdo = $probe['pdo'];
            }
        }

        return [$bestIndex, $bestPdo];
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
     * Register one connection lifecycle hook.
     */
    private function registerLifecycleHook(string $name, callable $hook): void
    {
        if (!isset($this->lifecycleHooks[$name])) {
            return;
        }

        $this->lifecycleHooks[$name][] = $hook;
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
     * Resolve least-latency replica from cached winner within TTL.
     *
     * @param list<array<string,mixed>> $readConfigs
     * @param list<int> $availableIndexes
     * @return array{0:int,1:PDO}|null
     */
    private function resolveLeastLatencyFromCachedReplica(array $readConfigs, array $availableIndexes): ?array
    {
        $cachedIndex = $this->leastLatencyReplicaIndex;
        $cachedAt = $this->leastLatencyResolvedAt;

        if ($cachedIndex === null || $cachedAt === null) {
            return null;
        }

        $ttl = $this->config->getLeastLatencyCacheTtl();
        if ($ttl <= 0 || (\time() - $cachedAt) > $ttl) {
            return null;
        }

        if (!\in_array($cachedIndex, $availableIndexes, true) || !isset($readConfigs[$cachedIndex])) {
            return null;
        }

        try {
            $pdo = $this->createReadReplicaPdo($readConfigs[$cachedIndex]);
            unset($this->readReplicaUnavailableUntil[$cachedIndex]);
            $this->readReplicaLatenciesMs = [];

            return [$cachedIndex, $pdo];
        } catch (PDOException|ConnectionException) {
            $this->markReadReplicaFailure($cachedIndex);

            return null;
        }
    }

    /**
     * Resolve the fastest healthy read replica by probing each replica.
     *
     * @param list<array<string,mixed>> $readConfigs
     * @return array{0:int,1:PDO}
     */
    private function resolveLeastLatencyReadReplica(array $readConfigs): array
    {
        $latencies = [];
        $indexes = $this->availableReadReplicaIndexes(\count($readConfigs));

        if ($indexes === []) {
            $indexes = \range(0, \count($readConfigs) - 1);
        }

        $cached = $this->resolveLeastLatencyFromCachedReplica($readConfigs, $indexes);
        if ($cached !== null) {
            return $cached;
        }

        $probeIndexes = $this->sampleLeastLatencyProbeIndexes($indexes);
        $fallbackProbeIndexes = \array_values(\array_diff($indexes, $probeIndexes));
        [$bestIndex, $bestPdo] = $this->probeLeastLatencyReplicaIndexes($probeIndexes, $readConfigs, $latencies);

        // If sampled probes all failed, try remaining replicas.
        if ($bestIndex === null || !$bestPdo instanceof PDO) {
            [$bestIndex, $bestPdo] = $this->probeLeastLatencyReplicaIndexes($fallbackProbeIndexes, $readConfigs, $latencies);
        }

        $this->readReplicaLatenciesMs = $latencies;

        if ($bestIndex === null || !$bestPdo instanceof PDO) {
            throw ConnectionException::connectionFailed(
                $this->config->getDriver(),
                'No healthy read replica available for least_latency strategy.',
            );
        }

        $this->leastLatencyReplicaIndex = $bestIndex;
        $this->leastLatencyResolvedAt = \time();

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
    private function runStatement(
        PDO $pdo,
        string $sql,
        array $bindings,
        bool $isWrite,
        ?string $cacheFingerprintSql = null,
    ): PDOStatement {
        $statement = $this->prepareStatementForExecution(
            $pdo,
            $sql,
            $bindings,
            $isWrite,
            $cacheFingerprintSql,
        );
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
     * @param list<int> $indexes
     * @return list<int>
     */
    private function sampleLeastLatencyProbeIndexes(array $indexes): array
    {
        $sampleSize = $this->config->getReadProbeSampleSize();
        $count = \count($indexes);

        if ($sampleSize <= 0 || $sampleSize >= $count) {
            return $indexes;
        }

        \shuffle($indexes);

        return \array_slice($indexes, 0, $sampleSize);
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
     * Decide whether a failed query attempt should be retried.
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

        if ($this->isConnectionError($e)) {
            // Backward-compatible default: a single reconnect retry.
            return $attempt < 2;
        }

        return DriverProfile::causedByRetryableTransactionError($this->config->getDriver(), $e);
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
     * Strip one leading SQL comment prefix used by DBLayer query-context injection.
     */
    private function stripLeadingCommentPrefix(string $sql): string
    {
        $trimmed = ltrim($sql);

        if (!str_starts_with($trimmed, '/*')) {
            return $sql;
        }

        $closing = strpos($trimmed, '*/');

        if ($closing === false) {
            return $sql;
        }

        return ltrim(substr($trimmed, $closing + 2));
    }

    /**
     * Synchronize server-side statement timeout for already-open PDO handles.
     */
    private function syncServerSideStatementTimeouts(): void
    {
        $this->applyServerSideTimeoutToPdo($this->pdo);
        $this->applyServerSideTimeoutToPdo($this->readPdo);
    }

    /**
     * Ensure statement cache bucket maps to the currently active PDO handle.
     */
    private function syncStatementCachePdoBucket(bool $isWrite, PDO $pdo): void
    {
        $bucket = $isWrite ? 'write' : 'read';
        $id = spl_object_id($pdo);
        $cachedId = $this->statementCachePdoIds[$bucket];

        if ($cachedId === $id) {
            return;
        }

        $this->clearStatementCacheBucket($isWrite);
        $this->statementCachePdoIds[$bucket] = $id;
    }

    /**
     * Update LRU order for one cached statement entry.
     */
    private function touchStatementCacheEntry(bool $isWrite, string $fingerprint): void
    {
        $bucket = $isWrite ? 'write' : 'read';
        $current = $this->statementCacheLru[$bucket];
        $filtered = [];

        foreach ($current as $entry) {
            if ($entry !== $fingerprint) {
                $filtered[] = $entry;
            }
        }

        $filtered[] = $fingerprint;
        $this->statementCacheLru[$bucket] = $filtered;
    }
}
