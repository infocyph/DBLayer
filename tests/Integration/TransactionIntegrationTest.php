<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Exceptions\ConnectionException;
use Infocyph\DBLayer\Exceptions\TransactionException;

it('commits successful transactions and rolls back failed ones', function (string $driver): void {
    dblayerAddConnectionForDriver($driver);
    $schemaDriver = dblayerConnectionDriver();
    $table = dblayerTable('payments');

    DB::statement(
        sprintf(
            'create table %s (
            %s,
            ref %s
        )',
            $table,
            dblayerAutoIncrementPrimaryKey($schemaDriver),
            dblayerStringType($schemaDriver),
        ),
    );

    DB::transaction(static function ($connection) use ($table): void {
        $connection->table($table)->insert(['ref' => 'committed']);
    });

    expect((int) DB::table($table)->count())->toBe(1);

    expect(static function () use ($table): mixed {
        return DB::transaction(static function ($connection) use ($table): void {
            $connection->table($table)->insert(['ref' => 'rolled-back']);
            throw new \RuntimeException('force rollback');
        });
    })->toThrow(TransactionException::class);

    expect((int) DB::table($table)->count())->toBe(1);
    expect(DB::table($table)->value('ref'))->toBe('committed');
})->with('dblayer_drivers');

it('supports nested transactions with savepoint rollbacks', function (string $driver): void {
    dblayerAddConnectionForDriver($driver);
    $schemaDriver = dblayerConnectionDriver();
    $table = dblayerTable('events');

    DB::statement(
        sprintf(
            'create table %s (
            %s,
            name %s
        )',
            $table,
            dblayerAutoIncrementPrimaryKey($schemaDriver),
            dblayerStringType($schemaDriver),
        ),
    );

    DB::beginTransaction();
    DB::table($table)->insert(['name' => 'outer']);

    DB::beginTransaction();
    DB::table($table)->insert(['name' => 'inner']);
    DB::rollBack();

    DB::commit();

    expect(DB::table($table)->pluck('name'))->toBe(['outer']);
})->with('dblayer_drivers');

it('supports read-only transaction callbacks', function (string $driver): void {
    dblayerAddConnectionForDriver($driver);

    $value = DB::readOnlyTransaction(
        static fn($connection): int => (int) $connection->scalar('select 1'),
    );

    expect($value)->toBe(1);
})->with('dblayer_drivers');

it('retries transaction callbacks after deadlock-like failures', function (string $driver): void {
    dblayerAddConnectionForDriver($driver);
    $schemaDriver = dblayerConnectionDriver();
    $table = dblayerTable('jobs');

    DB::statement(
        sprintf(
            'create table %s (
            %s,
            status %s
        )',
            $table,
            dblayerAutoIncrementPrimaryKey($schemaDriver),
            dblayerStringType($schemaDriver),
        ),
    );

    $attempts = 0;

    $result = DB::transaction(
        static function ($connection) use (&$attempts, $schemaDriver, $table): string {
            $attempts++;

            if ($attempts === 1) {
                throw new \RuntimeException(dblayerTransientDeadlockMessage($schemaDriver));
            }

            $connection->table($table)->insert(['status' => 'done']);

            return 'ok';
        },
        2,
    );

    expect($result)->toBe('ok');
    expect($attempts)->toBe(2);
    expect(DB::table($table)->pluck('status'))->toBe(['done']);
})->with('dblayer_drivers');

it('runs after-commit callbacks after a successful top-level transaction', function (string $driver): void {
    dblayerAddConnectionForDriver($driver);
    $schemaDriver = dblayerConnectionDriver();
    $table = dblayerTable('after_commit');
    $observed = [];

    DB::statement(
        sprintf(
            'create table %s (%s, ref %s)',
            $table,
            dblayerAutoIncrementPrimaryKey($schemaDriver),
            dblayerStringType($schemaDriver),
        ),
    );

    DB::transaction(static function ($connection) use (&$observed, $table): void {
        $connection->table($table)->insert(['ref' => 'committed']);
        DB::afterCommit(static function () use (&$observed, $table): void {
            $observed[] = (int) DB::table($table)->count();
        });

        expect($observed)->toBe([]);
    });

    expect($observed)->toBe([1]);
})->with('dblayer_drivers');

it('runs after-commit callbacks immediately outside a transaction', function (string $driver): void {
    dblayerAddConnectionForDriver($driver);
    $calls = 0;

    DB::afterCommit(static function () use (&$calls): void {
        $calls++;
    });

    expect($calls)->toBe(1);
})->with('dblayer_drivers');

it('discards after-commit callbacks when a transaction rolls back', function (string $driver): void {
    dblayerAddConnectionForDriver($driver);
    $calls = 0;

    expect(static function () use (&$calls): mixed {
        return DB::transaction(static function () use (&$calls): void {
            DB::afterCommit(static function () use (&$calls): void {
                $calls++;
            });

            throw new \RuntimeException('force rollback');
        });
    })->toThrow(TransactionException::class);

    expect($calls)->toBe(0);
})->with('dblayer_drivers');

it('promotes nested callbacks and discards callbacks from rolled back savepoints', function (string $driver): void {
    dblayerAddConnectionForDriver($driver);
    $callbacks = [];

    DB::beginTransaction();
    DB::afterCommit(static function () use (&$callbacks): void {
        $callbacks[] = 'outer-before';
    });

    DB::beginTransaction();
    DB::afterCommit(static function () use (&$callbacks): void {
        $callbacks[] = 'inner-committed';
    });
    DB::commit();

    DB::beginTransaction();
    DB::afterCommit(static function () use (&$callbacks): void {
        $callbacks[] = 'inner-rolled-back';
    });
    DB::rollBack();

    DB::afterCommit(static function () use (&$callbacks): void {
        $callbacks[] = 'outer-after';
    });

    expect($callbacks)->toBe([]);

    DB::commit();

    expect($callbacks)->toBe(['outer-before', 'inner-committed', 'outer-after']);
})->with('dblayer_drivers');

it('discards callbacks from failed transaction retry attempts', function (string $driver): void {
    dblayerAddConnectionForDriver($driver);
    $schemaDriver = dblayerConnectionDriver();
    $attempts = 0;
    $callbacks = [];

    DB::transaction(
        static function () use (&$attempts, &$callbacks, $schemaDriver): void {
            $attempts++;
            $attempt = $attempts;
            DB::afterCommit(static function () use (&$callbacks, $attempt): void {
                $callbacks[] = $attempt;
            });

            if ($attempt === 1) {
                throw new \RuntimeException(dblayerTransientDeadlockMessage($schemaDriver));
            }
        },
        2,
    );

    expect($callbacks)->toBe([2]);
})->with('dblayer_drivers');

it('keeps committed data when an after-commit callback fails', function (string $driver): void {
    dblayerAddConnectionForDriver($driver);
    $schemaDriver = dblayerConnectionDriver();
    $table = dblayerTable('after_commit_failure');

    DB::statement(
        sprintf(
            'create table %s (%s, ref %s)',
            $table,
            dblayerAutoIncrementPrimaryKey($schemaDriver),
            dblayerStringType($schemaDriver),
        ),
    );

    expect(static function () use ($table): mixed {
        return DB::transaction(static function ($connection) use ($table): void {
            $connection->table($table)->insert(['ref' => 'committed']);
            DB::afterCommit(static function (): void {
                throw new \RuntimeException('after-commit failure');
            });
        });
    })->toThrow(\RuntimeException::class, 'after-commit failure');

    expect((int) DB::table($table)->count())->toBe(1);
})->with('dblayer_drivers');

it('preserves managed state when native commit or rollback fails', function (string $operation): void {
    dblayerAddConnectionForDriver('sqlite');
    DB::beginTransaction();
    $connection = DB::connection();

    try {
        if ($operation === 'commit') {
            $connection->getPdo()->rollBack();
            expect(fn() => DB::commit())->toThrow(PDOException::class);
        } else {
            $connection->getPdo()->commit();
            expect(fn() => DB::rollBack())->toThrow(PDOException::class);
        }

        $stats = DB::transactionStats();
        expect(DB::transactionLevel())->toBe(1)
            ->and($stats['current_level'])->toBe(1)
            ->and($stats['in_transaction'])->toBeTrue();
    } finally {
        $connection->disconnect();
    }
})->with(['commit', 'rollback']);

it('preserves nesting when releasing a savepoint fails', function (): void {
    dblayerAddConnectionForDriver('sqlite');
    DB::beginTransaction();
    DB::beginTransaction();
    $connection = DB::connection();

    try {
        DB::statement('RELEASE SAVEPOINT trans_1');
        expect(fn() => DB::commit())->toThrow(ConnectionException::class)
            ->and(DB::transactionLevel())->toBe(2)
            ->and(DB::transactionStats()['current_level'])->toBe(2);
    } finally {
        $connection->disconnect();
    }
});

it('keeps transaction counters non-negative across no-op and completed operations', function (): void {
    dblayerAddConnectionForDriver('sqlite');
    DB::commit();
    DB::rollBack();
    DB::beginTransaction();
    DB::commit();
    DB::beginTransaction();
    DB::rollBack();

    $stats = DB::transactionStats();
    foreach (['total', 'committed', 'rolled_back', 'deadlocks', 'current_level', 'savepoints'] as $key) {
        expect($stats[$key])->toBeGreaterThanOrEqual(0);
    }

    expect($stats['total'])->toBe(2)
        ->and($stats['committed'])->toBe(1)
        ->and($stats['rolled_back'])->toBe(1)
        ->and($stats['current_level'])->toBe(0);
});
