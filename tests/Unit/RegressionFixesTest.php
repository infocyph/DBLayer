<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Events\Events;
use Infocyph\DBLayer\Exceptions\ConnectionException;
use Infocyph\DBLayer\Exceptions\SecurityException;
use Infocyph\DBLayer\Security\QueryValidator;

beforeEach(function (): void {
    DB::purge();
});

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
    ], 'regression_distinct');

    DB::statement('create table users (id integer primary key autoincrement, email text)', [], 'regression_distinct');
    DB::statement('insert into users (email) values ("a@example.com")', [], 'regression_distinct');
    DB::statement('insert into users (email) values ("a@example.com")', [], 'regression_distinct');

    $rows = DB::table('users', 'regression_distinct')
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
    ], 'regression_union');

    DB::statement('create table t1 (id integer)', [], 'regression_union');
    DB::statement('create table t2 (id integer)', [], 'regression_union');
    DB::statement('insert into t1 (id) values (1)', [], 'regression_union');
    DB::statement('insert into t2 (id) values (2)', [], 'regression_union');

    $rows = DB::table('t1', 'regression_union')
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
    ], 'regression_nested_tx');

    expect(DB::transactionLevel('regression_nested_tx'))->toBe(0);

    DB::beginTransaction('regression_nested_tx');
    expect(DB::transactionLevel('regression_nested_tx'))->toBe(1);

    DB::beginTransaction('regression_nested_tx');
    expect(DB::transactionLevel('regression_nested_tx'))->toBe(2);

    DB::rollBack('regression_nested_tx');
    expect(DB::transactionLevel('regression_nested_tx'))->toBe(1);

    DB::rollBack('regression_nested_tx');
    expect(DB::transactionLevel('regression_nested_tx'))->toBe(0);
});

it('chunks by non-integer keys without repeating pages', function (): void {
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ], 'regression_chunk');

    DB::statement('create table items (uuid text primary key)', [], 'regression_chunk');
    DB::statement('insert into items (uuid) values ("a1"), ("b2"), ("c3")', [], 'regression_chunk');

    $pages = [];

    DB::table('items', 'regression_chunk')->chunkById(
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

it('supports associative read replica configuration', function (): void {
    $config = ConnectionConfig::fromArray([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'read' => ['database' => ':memory:'],
        'read_strategy' => 'round-robin',
    ]);

    expect($config->hasReadConfig())->toBeTrue();
    expect($config->getReadConfigs())->toBe([['database' => ':memory:']]);
    expect($config->getReadConfig())->toBe(['database' => ':memory:']);
    expect($config->getReadStrategy())->toBe('round_robin');
});

it('preserves null values for ArrayAccess lookups in data_get', function (): void {
    $payload = new \ArrayObject(['key' => null]);

    expect(data_get($payload, 'key', 'fallback'))->toBeNull();
});

it('preserves object shape when setting nested paths via data_set', function (): void {
    $target = new \stdClass();

    data_set($target, 'profile.name', 'Alice');

    expect($target)->toBeObject();
    expect($target->profile)->toBeArray();
    expect($target->profile['name'])->toBe('Alice');
});

it('removes empty event buckets when forgetting listeners', function (): void {
    $event = 'unit.event.' . bin2hex(random_bytes(8));
    $beforeCount = Events::getStats()['registered_events'];
    $listener = static function (): void {
    };

    Events::listen($event, $listener);
    Events::forget($event, $listener);

    expect(array_key_exists($event, Events::getEvents()))->toBeFalse();
    expect(Events::getStats()['registered_events'])->toBe($beforeCount);
});

it('supports round_robin read replica strategy across reconnects', function (): void {
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'read_strategy' => 'round_robin',
        'read' => [
            ['database' => ':memory:'],
            ['database' => ':memory:'],
        ],
    ], 'regression_round_robin');

    $connection = DB::connection('regression_round_robin');
    $connection->select('select 1');
    $first = $connection->getReadReplicaInfo()['selected_index'];

    $connection->reconnect(false);
    $connection->select('select 1');
    $second = $connection->getReadReplicaInfo()['selected_index'];

    expect([$first, $second])->toBe([0, 1]);
});

it('captures latency probes when least_latency read strategy is used', function (): void {
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'read_strategy' => 'least_latency',
        'read' => [
            ['database' => ':memory:'],
            ['database' => ':memory:'],
        ],
    ], 'regression_least_latency');

    $connection = DB::connection('regression_least_latency');
    $connection->select('select 1');

    $info = $connection->getReadReplicaInfo();

    expect($info['strategy'])->toBe('least_latency');
    expect($info['selected_index'])->toBeInt();
    expect($info['latencies_ms'])->toHaveCount(2);
});

it('supports query cancellation and deadline wrappers', function (): void {
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ], 'regression_query_controls');

    expect(fn (): mixed => DB::withQueryCancellation(
        static fn (): bool => true,
        static fn (): mixed => DB::select('select 1', [], 'regression_query_controls'),
        'regression_query_controls',
    ))->toThrow(ConnectionException::class);

    expect(fn (): mixed => DB::withQueryDeadline(
        0.0,
        static fn (): mixed => DB::select('select 1', [], 'regression_query_controls'),
        'regression_query_controls',
    ))->toThrow(ConnectionException::class);
});

it('collects and flushes telemetry payloads', function (): void {
    DB::enableTelemetry();

    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ], 'regression_telemetry');

    DB::table('sqlite_master', 'regression_telemetry')->select('name')->limit(1)->get();
    DB::beginTransaction('regression_telemetry');
    DB::rollBack('regression_telemetry');

    $snapshot = DB::telemetry();
    expect($snapshot['summary']['query_count'])->toBeGreaterThan(0);
    expect($snapshot['summary']['transaction_event_count'])->toBeGreaterThan(0);

    $flushed = DB::flushTelemetry();
    expect($flushed['summary']['query_count'])->toBeGreaterThan(0);
    expect(DB::telemetry()['summary']['query_count'])->toBe(0);

    DB::disableTelemetry();
});

it('does not flag legitimate unions and still catches injected union payloads', function (): void {
    $validator = new QueryValidator();

    expect(fn () => $validator->detectSqlInjection('select id from t1 union select id from t2'))
        ->not->toThrow(SecurityException::class);

    expect(fn () => $validator->detectSqlInjection(
        'select * from users where name = "x" or 1=1 union select password from admins'
    ))->toThrow(SecurityException::class);
});
