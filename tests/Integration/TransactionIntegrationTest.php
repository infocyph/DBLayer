<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;
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
