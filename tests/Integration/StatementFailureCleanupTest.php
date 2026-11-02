<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;

/** @return array{string, Connection, Connection} */
function dblayerStatementCleanupConnections(bool $cache): array
{
    $path = tempnam(sys_get_temp_dir(), 'dblayer-statement-cleanup-');
    if ($path === false) {
        throw new RuntimeException('Unable to allocate the statement cleanup database.');
    }
    $config = ConnectionConfig::fromArray([
        'driver' => 'sqlite',
        'database' => $path,
        'statement_cache_enabled' => $cache,
        'options' => [PDO::ATTR_TIMEOUT => 0],
    ]);
    $connection = new Connection($config);
    $connection->statement('CREATE TABLE cleanup_probe (id INTEGER PRIMARY KEY, value INTEGER)');
    $connection->insert('INSERT INTO cleanup_probe (id, value) VALUES (1, 1)');

    return [$path, $connection, new Connection($config)];
}

it('resets failed statements before a query retry and reports fresh affected row counts', function (
    bool $cache,
    string $ignoreArguments,
    string $operation,
): void {
    $previous = ini_set('zend.exception_ignore_args', $ignoreArguments);
    [$path, $connection, $blocker] = dblayerStatementCleanupConnections($cache);
    $failures = [];
    $sql = $operation === 'delete'
        ? 'DELETE FROM cleanup_probe WHERE id = ?'
        : 'UPDATE cleanup_probe SET value = 2 WHERE id = ? AND value = 1';

    $mutate = static fn(): int => $operation === 'delete'
        ? $connection->delete($sql, [1])
        : $connection->update($sql, [1]);

    try {
        $blocker->begin();
        $blocker->update('UPDATE cleanup_probe SET value = value WHERE id = 1');
        $first = $connection->withQueryRetryPolicy(
            static function (Throwable $failure, int $attempt) use ($blocker, &$failures): bool {
                $failures[] = $failure;
                if ($attempt === 2) {
                    $blocker->rollBack();
                }

                return $attempt <= 2;
            },
            $mutate,
        );

        expect($failures)->toHaveCount(2)
            ->and($first)->toBe(1)
            ->and($mutate())->toBe(0)
            ->and((int) $connection->scalar('SELECT COUNT(*) FROM cleanup_probe WHERE value = 1'))->toBe(0);
    } finally {
        $connection->disconnect();
        $blocker->disconnect();
        unlink($path);
        ini_set('zend.exception_ignore_args', $previous);
    }
})->with([false, true])->with(['0', '1'])->with(['delete', 'update']);

it('resets failed statements before transaction rollback and retry commit', function (
    bool $cache,
    string $ignoreArguments,
): void {
    $previous = ini_set('zend.exception_ignore_args', $ignoreArguments);
    [$path, $connection, $blocker] = dblayerStatementCleanupConnections($cache);
    $attempts = 0;

    try {
        $result = $connection->transaction(static function (Connection $current) use ($blocker, &$attempts): int {
            $attempts++;
            if ($attempts === 1) {
                $blocker->begin();
                $blocker->update('UPDATE cleanup_probe SET value = value WHERE id = 1');
                try {
                    return $current->update('UPDATE cleanup_probe SET value = 2 WHERE id = 1');
                } finally {
                    $blocker->rollBack();
                }
            }

            return $current->update('UPDATE cleanup_probe SET value = 2 WHERE id = 1');
        }, 2);

        $stats = $connection->transactionStats();
        expect($attempts)->toBe(2)
            ->and($result)->toBe(1)
            ->and($stats['committed'])->toBe(1)
            ->and($stats['rolled_back'])->toBe(1)
            ->and((int) $connection->scalar('SELECT value FROM cleanup_probe WHERE id = 1'))->toBe(2);
    } finally {
        $connection->disconnect();
        $blocker->disconnect();
        unlink($path);
        ini_set('zend.exception_ignore_args', $previous);
    }
})->with([false, true])->with(['0', '1']);
