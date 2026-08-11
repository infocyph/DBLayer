<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Connection\Concerns;

use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\DBLayer\Connection\ReadReplicaSessionPolicy;
use Infocyph\DBLayer\Connection\SqlStatementInspector;
use Infocyph\DBLayer\Events\Events;
use Infocyph\DBLayer\Exceptions\ConnectionException;
use Infocyph\DBLayer\Exceptions\SecurityException;
use Infocyph\DBLayer\Query\Core\SqlOrigin;
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
     * Disconnect write and read handles and reset request-scoped state.
     */
    public function disconnect(): void
    {
        if ($this->pdo?->inTransaction() === true) {
            try {
                $this->pdo->rollBack();
            } catch (Throwable) {
                // The handle is discarded below; transaction outcome is uncertain.
            }
        }

        $this->pdo = null;
        $this->readPdo = null;
        $this->clearStatementCache();
        $this->replicaSelector->reset();
        $this->recordsModified = false;
        $this->transactionManager = null;
        $this->resetRequestRuntimeState();
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
        $marker = 'dblayer ';
        $payloadBudget = max(0, $maxLength - strlen($marker));

        if (strlen($payload) > $payloadBudget) {
            $payload = substr($payload, 0, $payloadBudget);
        }

        return '/* ' . $marker . $payload . ' */ ' . $sql;
    }

    /**
     * Apply best-effort read-only transaction mode for current transaction.
     */
    private function applyReadOnlyTransactionMode(): void
    {
        $this->driver->applyReadOnlyTransaction($this->getPdo());
    }

    /**
     * Apply best-effort driver-native statement timeout for one PDO handle.
     */
    private function applyServerSideTimeoutToPdo(?PDO $pdo): void
    {
        if (!$pdo instanceof PDO) {
            return;
        }

        $this->driver->applyStatementTimeout($pdo, $this->queryTimeoutMs ?? 0);
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
            $this->replicaSelector->reset();

            return;
        }

        $this->dispatchBeforeConnect(false);

        try {
            [, $pdo] = $this->replicaSelector->resolve(
                $readConfigs,
                fn(array $readConfig): PDO => $this->createReadReplicaPdo($readConfig),
            );
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
            } catch (Throwable $error) {
                Events::reportDiagnostic('db.connection.after_connect', $error);
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
            } catch (Throwable $error) {
                Events::reportDiagnostic('db.connection.after_reconnect', $error);
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
            } catch (Throwable $error) {
                Events::reportDiagnostic('db.connection.before_connect', $error);
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
            } catch (Throwable $error) {
                Events::reportDiagnostic('db.connection.before_reconnect', $error);
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
            } catch (Throwable $hookError) {
                Events::reportDiagnostic('db.connection.failure_hook', $hookError);
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
     * Bind values and execute an already-prepared statement.
     *
     * @param array<int|string,mixed> $bindings
     */
    private function executePreparedStatement(PDOStatement $statement, array $bindings): PDOStatement
    {
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
     * Get the query executor for this connection.
     */
    private function getExecutor(): Executor
    {
        if ($this->executor === null) {
            $this->executor = new Executor($this);
        }

        return $this->executor;
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
     * Validate policy controls and apply the configured query comment.
     *
     * @param array<int|string,mixed> $bindings
     */
    private function prepareSqlForExecution(
        string $sql,
        array $bindings,
        SqlOrigin $origin = SqlOrigin::RAW,
    ): string {
        $securityConfig = $this->config->securityConfig();

        if ($this->securityChecks) {
            if ($origin === SqlOrigin::RAW) {
                Security::validateQuery($sql, $bindings, $securityConfig);
            } else {
                Security::validateGeneratedQuery($sql, $bindings, $securityConfig);
            }
        }

        $this->enforceRateLimitIfConfigured($securityConfig);

        return $this->applyQueryComment($sql);
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

        return $this->executePreparedStatement($statement, $bindings);
    }

    /**
     * Decide whether a failed query attempt should be retried.
     *
     * @param array<int|string,mixed> $bindings
     */
    private function shouldRetryQuery(
        PDOException $e,
        int $attempt,
        string $sql,
        array $bindings,
        bool $isWrite = false,
    ): bool {
        if ($attempt >= self::MAX_QUERY_RETRY_ATTEMPTS) {
            return false;
        }

        if ($this->managedTransactionLevel() > 0 || ($this->pdo?->inTransaction() ?? false)) {
            return false;
        }

        if ($this->queryRetryPolicy !== null) {
            return (bool) ($this->queryRetryPolicy)($e, $attempt, $sql, $bindings);
        }

        if ($isWrite) {
            return false;
        }

        if ($this->isConnectionError($e)) {
            // Backward-compatible default: a single reconnect retry.
            return $attempt < 2;
        }

        return false;
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
