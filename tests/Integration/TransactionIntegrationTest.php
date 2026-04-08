<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Exceptions\TransactionException;

it('commits successful transactions and rolls back failed ones', function (): void {
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]);

    DB::statement(
        'create table payments (
            id integer primary key autoincrement,
            ref text
        )'
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
});

it('supports nested transactions with savepoint rollbacks', function (): void {
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]);

    DB::statement(
        'create table events (
            id integer primary key autoincrement,
            name text
        )'
    );

    DB::beginTransaction();
    DB::table('events')->insert(['name' => 'outer']);

    DB::beginTransaction();
    DB::table('events')->insert(['name' => 'inner']);
    DB::rollBack();

    DB::commit();

    expect(DB::table('events')->pluck('name'))->toBe(['outer']);
});

it('retries transaction callbacks after deadlock-like failures', function (): void {
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]);

    DB::statement(
        'create table jobs (
            id integer primary key autoincrement,
            status text
        )'
    );

    $attempts = 0;

    $result = DB::transaction(
        static function ($connection) use (&$attempts): string {
            $attempts++;

            if ($attempts === 1) {
                $connection->table('jobs')->insert(['status' => 'transient']);
                throw new \RuntimeException('database is locked');
            }

            $connection->table('jobs')->insert(['status' => 'done']);

            return 'ok';
        },
        2,
    );

    expect($result)->toBe('ok');
    expect($attempts)->toBe(2);
    expect(DB::table('jobs')->pluck('status'))->toBe(['done']);
});
