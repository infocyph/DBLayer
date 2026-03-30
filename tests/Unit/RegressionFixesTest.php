<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Exceptions\ConnectionException;
use Infocyph\DBLayer\Exceptions\SecurityException;
use Infocyph\DBLayer\Security\QueryValidator;

it('creates exception instances without signature fatals', function (): void {
    expect(ConnectionException::invalidConfiguration('x'))
        ->toBeInstanceOf(ConnectionException::class);

    expect(SecurityException::invalidConfiguration('x'))
        ->toBeInstanceOf(SecurityException::class);
});

it('applies distinct correctly', function (): void {
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]);

    DB::statement('create table users (id integer primary key autoincrement, email text)');
    DB::statement('insert into users (email) values ("a@example.com")');
    DB::statement('insert into users (email) values ("a@example.com")');

    $rows = DB::table('users')
        ->distinct()
        ->select('email')
        ->get();

    expect($rows)->toHaveCount(1);
    expect($rows[0]['email'])->toBe('a@example.com');
});

it('executes union queries correctly', function (): void {
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]);

    DB::statement('create table t1 (id integer)');
    DB::statement('create table t2 (id integer)');
    DB::statement('insert into t1 (id) values (1)');
    DB::statement('insert into t2 (id) values (2)');

    $rows = DB::table('t1')
        ->select('id')
        ->union(function ($query): void {
            $query->from('t2')->select('id');
        })
        ->get();

    expect($rows)->toHaveCount(2);
    expect(array_column($rows, 'id'))->toBe([1, 2]);
});

it('tracks nested transactions for manual facade api', function (): void {
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]);

    expect(DB::transactionLevel())->toBe(0);

    DB::beginTransaction();
    expect(DB::transactionLevel())->toBe(1);

    DB::beginTransaction();
    expect(DB::transactionLevel())->toBe(2);

    DB::rollBack();
    expect(DB::transactionLevel())->toBe(1);

    DB::rollBack();
    expect(DB::transactionLevel())->toBe(0);
});

it('chunks by non-integer keys without repeating pages', function (): void {
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]);

    DB::statement('create table items (uuid text primary key)');
    DB::statement('insert into items (uuid) values ("a1"), ("b2"), ("c3")');

    $pages = [];

    DB::table('items')->chunkById(
        2,
        function (array $rows, int $page) use (&$pages): bool {
            $pages[] = array_column($rows, 'uuid');

            return true;
        },
        'uuid'
    );

    expect($pages)->toBe([
        ['a1', 'b2'],
        ['c3'],
    ]);
});

it('supports list-based read replica configuration', function (): void {
    $config = ConnectionConfig::fromArray([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'read' => [
            ['database' => ':memory:'],
            ['database' => ':memory:'],
        ],
    ]);

    expect($config->hasReadConfig())->toBeTrue();
    expect($config->getReadConfigs())->toHaveCount(2);
    expect($config->getReadConfig())->toBe(['database' => ':memory:']);
});

it('does not flag legitimate unions and still catches injected union payloads', function (): void {
    $validator = new QueryValidator();

    expect(fn () => $validator->detectSqlInjection('select id from t1 union select id from t2'))
        ->not->toThrow(SecurityException::class);

    expect(fn () => $validator->detectSqlInjection(
        'select * from users where name = "x" or 1=1 union select password from admins'
    ))->toThrow(SecurityException::class);
});
