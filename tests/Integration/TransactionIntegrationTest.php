<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Exceptions\TransactionException;

it('commits successful transactions and rolls back failed ones', function (string $driver): void {
    dblayerAddConnectionForDriver($driver);

    DB::statement(
        sprintf(
            'create table payments (
            %s,
            ref %s
        )',
            dblayerAutoIncrementPrimaryKey($driver),
            dblayerStringType($driver),
        ),
    );

    DB::transaction(static function ($connection): void {
        $connection->table('payments')->insert(['ref' => 'committed']);
    });

    expect((int) DB::table('payments')->count())->toBe(1);

    expect(static function (): mixed {
        return DB::transaction(static function ($connection): void {
            $connection->table('payments')->insert(['ref' => 'rolled-back']);
            throw new \RuntimeException('force rollback');
        });
    })->toThrow(TransactionException::class);

    expect((int) DB::table('payments')->count())->toBe(1);
    expect(DB::table('payments')->value('ref'))->toBe('committed');
})->with('dblayer_drivers');

it('supports nested transactions with savepoint rollbacks', function (string $driver): void {
    dblayerAddConnectionForDriver($driver);

    DB::statement(
        sprintf(
            'create table events (
            %s,
            name %s
        )',
            dblayerAutoIncrementPrimaryKey($driver),
            dblayerStringType($driver),
        ),
    );

    DB::beginTransaction();
    DB::table('events')->insert(['name' => 'outer']);

    DB::beginTransaction();
    DB::table('events')->insert(['name' => 'inner']);
    DB::rollBack();

    DB::commit();

    expect(DB::table('events')->pluck('name'))->toBe(['outer']);
})->with('dblayer_drivers');

it('retries transaction callbacks after deadlock-like failures', function (string $driver): void {
    dblayerAddConnectionForDriver($driver);

    DB::statement(
        sprintf(
            'create table jobs (
            %s,
            status %s
        )',
            dblayerAutoIncrementPrimaryKey($driver),
            dblayerStringType($driver),
        ),
    );

    $attempts = 0;

    $result = DB::transaction(
        static function ($connection) use (&$attempts, $driver): string {
            $attempts++;

            if ($attempts === 1) {
                $connection->table('jobs')->insert(['status' => 'transient']);
                throw new \RuntimeException(dblayerTransientDeadlockMessage($driver));
            }

            $connection->table('jobs')->insert(['status' => 'done']);

            return 'ok';
        },
        2,
    );

    expect($result)->toBe('ok');
    expect($attempts)->toBe(2);
    expect(DB::table('jobs')->pluck('status'))->toBe(['done']);
})->with('dblayer_drivers');
