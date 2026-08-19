<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Transaction;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Driver\Support\DriverProfile;
use Infocyph\DBLayer\Events\DatabaseEvents\TransactionBeginning;
use Infocyph\DBLayer\Events\DatabaseEvents\TransactionCommitted;
use Infocyph\DBLayer\Events\DatabaseEvents\TransactionRolledBack;
use Infocyph\DBLayer\Events\Events;
use Infocyph\DBLayer\Exceptions\TransactionException;
use PDO;
use Throwable;

final class Transaction
{
    private const int BASE_BACKOFF_US = 100_000;
    private const int MAX_ATTEMPTS = 3;
    /** @var array<int,list<callable():void>> */ private array $afterCommitCallbacks = [];
    private int $level = 0;
    private ?float $startedAt = null;
    /**
     * @var array{
     *     total:int,
     *     committed:int,
     *     rolled_back:int,
     *     deadlocks:int,
     *     in_transaction:bool,
     *     current_level:int,
     *     savepoints:int,
     *     elapsed_time:float
     * }
     */
    private array $stats = ['total' => 0, 'committed' => 0, 'rolled_back' => 0, 'deadlocks' => 0, 'in_transaction' => false, 'current_level' => 0, 'savepoints' => 0, 'elapsed_time' => 0.0];

    public function __construct(private readonly Connection $connection) {}

    public function afterCommit(callable $callback): void
    {
        if ($this->level === 0) { $callback(); return; }
        $this->afterCommitCallbacks[$this->level][] = $callback;
    }

    public function begin(): void
    {
        if ($this->level === 0) {
            $this->beginTopLevel(); $this->stats['total']++; $this->stats['in_transaction'] = true; $this->startedAt = microtime(true);
            Events::dispatch('db.transaction.beginning', [new TransactionBeginning($this->connection)]);
        } else { $this->createSavepoint($this->level); $this->stats['savepoints']++; }
        $this->level++; $this->stats['current_level'] = $this->level;
    }

    public function commit(): void
    {
        if ($this->level === 0) { return; }
        if ($this->level > 1) {
            $completedLevel = $this->level; $targetLevel = $completedLevel - 1; $this->releaseSavepoint($targetLevel);
            $this->level = $targetLevel; $this->stats['current_level'] = $this->level; $this->promoteAfterCommitCallbacks($completedLevel, $targetLevel); return;
        }
        if (!$this->connection->commitNativeTransaction()) { throw TransactionException::commitFailed($this->nativeErrorMessage($this->connection->getPdo(), 'The PDO driver returned false.')); }
        $durationMs = $this->transactionDurationMs(); $this->stats['committed']++; $this->level = 0; $this->stats['current_level'] = 0; $this->finishTopLevel();
        $callbacks = $this->drainAfterCommitCallbacks(); Events::dispatch('db.transaction.committed', [new TransactionCommitted($this->connection, $durationMs)]); $this->runAfterCommitCallbacks($callbacks);
    }

    public function execute(callable $callback, int $attempts = 1): mixed
    {
        $attempts = max(1, min($attempts, self::MAX_ATTEMPTS)); $attempt = 0;
        beginning: $attempt++;
        try { $this->begin(); }
        catch (Throwable $e) {
            if ($attempt < $attempts && $this->causedByRetryableTransactionError($e)) { $this->stats['deadlocks']++; $this->backoff($attempt); goto beginning; }
            throw TransactionException::failed($e);
        }
        try { $result = $callback($this->connection); $this->commit(); return $result; }
        catch (Throwable $e) {
            if (!$this->inTransaction()) { throw $e; }
            try { $this->rollBack(); } catch (Throwable $rollbackFailure) { $this->connection->disconnect(); throw TransactionException::rollbackAlsoFailed($e, $rollbackFailure); }
            if ($attempt < $attempts && $this->causedByRetryableTransactionError($e)) { $this->stats['deadlocks']++; $this->backoff($attempt); goto beginning; }
            throw TransactionException::failed($e);
        }
    }

    public function getConnection(): Connection { return $this->connection; }
    /**
     * @return array{
     *     total:int,
     *     committed:int,
     *     rolled_back:int,
     *     deadlocks:int,
     *     in_transaction:bool,
     *     current_level:int,
     *     savepoints:int,
     *     elapsed_time:float
     * }
     */
    public function getStats(): array { return $this->stats; }
    public function inTransaction(): bool { return $this->level > 0; }
    public function level(): int { return $this->level; }
    public function resetStats(): void
    {
        $this->stats = ['total' => 0, 'committed' => 0, 'rolled_back' => 0, 'deadlocks' => 0, 'in_transaction' => $this->level > 0, 'current_level' => $this->level, 'savepoints' => 0, 'elapsed_time' => 0.0];
        $this->startedAt = $this->level > 0 ? microtime(true) : null; if ($this->level === 0) { $this->afterCommitCallbacks = []; }
    }

    public function rollBack(): void
    {
        if ($this->level === 0) { return; }
        if ($this->level > 1) {
            $completedLevel = $this->level; $targetLevel = $completedLevel - 1; $this->rollbackToSavepoint($targetLevel); $this->discardAfterCommitCallbacks($completedLevel);
            $this->level = $targetLevel; $this->stats['current_level'] = $this->level; return;
        }
        if (!$this->connection->rollBackNativeTransaction()) { throw TransactionException::rollBackFailed($this->nativeErrorMessage($this->connection->getPdo(), 'The PDO driver returned false.')); }
        $durationMs = $this->transactionDurationMs(); $this->discardAfterCommitCallbacks(1); $this->stats['rolled_back']++; $this->level = 0; $this->stats['current_level'] = 0; $this->finishTopLevel();
        Events::dispatch('db.transaction.rolled_back', [new TransactionRolledBack($this->connection, $durationMs)]);
    }

    private function backoff(int $attempt): void { usleep(self::BASE_BACKOFF_US * max(1, $attempt)); }
    private function beginTopLevel(): void
    {
        if (!$this->connection->beginNativeTransaction()) { throw TransactionException::beginFailed($this->nativeErrorMessage($this->connection->getPdo(), 'The PDO driver returned false.')); }
    }
    private function causedByRetryableTransactionError(Throwable $e): bool { return DriverProfile::causedByRetryableTransactionError($this->connection->getDriverName(), $e); }
    private function createSavepoint(int $level): void
    {
        if (!$this->connection->getCapabilities()->supportsSavepoints) { throw TransactionException::failed(new \LogicException('Nested transactions require driver savepoint support.')); }
        $savepoint = 'trans_' . $level; $this->connection->statement(DriverProfile::createSavepointSql($this->connection->getDriverName(), $savepoint));
    }
    private function discardAfterCommitCallbacks(int $fromLevel): void { foreach (array_keys($this->afterCommitCallbacks) as $level) { if ($level >= $fromLevel) { unset($this->afterCommitCallbacks[$level]); } } }
    /** @return list<callable():void> */
    private function drainAfterCommitCallbacks(): array
    {
        if ($this->afterCommitCallbacks === []) { return []; }
        ksort($this->afterCommitCallbacks); $callbacks = array_merge(...array_values($this->afterCommitCallbacks)); $this->afterCommitCallbacks = []; return $callbacks;
    }
    private function finishTopLevel(): void { if ($this->startedAt !== null) { $this->stats['elapsed_time'] += microtime(true) - $this->startedAt; } $this->startedAt = null; $this->stats['in_transaction'] = false; }
    private function nativeErrorMessage(PDO $pdo, string $fallback): string { $message = $pdo->errorInfo()[2] ?? null; return is_string($message) && $message !== '' ? $message : $fallback; }
    private function promoteAfterCommitCallbacks(int $fromLevel, int $toLevel): void
    {
        $callbacks = $this->afterCommitCallbacks[$fromLevel] ?? []; unset($this->afterCommitCallbacks[$fromLevel]);
        if ($callbacks !== []) { $this->afterCommitCallbacks[$toLevel] = [...($this->afterCommitCallbacks[$toLevel] ?? []), ...$callbacks]; }
    }
    private function releaseSavepoint(int $level): void
    {
        if (!$this->connection->getCapabilities()->supportsSavepoints) { throw TransactionException::failed(new \LogicException('Nested transactions require driver savepoint support.')); }
        $sql = DriverProfile::releaseSavepointSql($this->connection->getDriverName(), 'trans_' . $level); if ($sql !== null) { $this->connection->statement($sql); }
    }
    private function rollbackToSavepoint(int $level): void
    {
        if (!$this->connection->getCapabilities()->supportsSavepoints) { throw TransactionException::failed(new \LogicException('Nested transactions require driver savepoint support.')); }
        $this->connection->statement(DriverProfile::rollbackToSavepointSql($this->connection->getDriverName(), 'trans_' . $level));
    }
    /** @param list<callable():void> $callbacks */
    private function runAfterCommitCallbacks(array $callbacks): void
    {
        $firstFailure = null; foreach ($callbacks as $callback) { try { $callback(); } catch (Throwable $exception) { $firstFailure ??= $exception; } }
        if ($firstFailure instanceof Throwable) { throw $firstFailure; }
    }
    private function transactionDurationMs(): float { return $this->startedAt === null ? 0.0 : (microtime(true) - $this->startedAt) * 1_000.0; }
}
