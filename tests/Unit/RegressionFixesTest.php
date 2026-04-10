<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Events\DatabaseEvents\QueryExecuted;
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

it('applies distinct correctly', function (string $driver): void {
    $connection = 'regression_distinct_' . $driver;
    dblayerAddConnectionForDriver($driver, $connection);
    $schemaDriver = dblayerConnectionDriver($connection);
    $table = dblayerTable('users');

    DB::statement(
        sprintf('create table %s (%s, email %s)', $table, dblayerAutoIncrementPrimaryKey($schemaDriver), dblayerStringType($schemaDriver)),
        [],
        $connection,
    );
    DB::table($table, $connection)->insert([
        ['email' => 'a@example.com'],
        ['email' => 'a@example.com'],
    ]);

    $rows = DB::table($table, $connection)
        ->distinct()
        ->select('email')
        ->get();

    expect($rows)->toHaveCount(1);
    expect($rows[0]['email'])->toBe('a@example.com');
})->with('dblayer_drivers');

it('executes union queries correctly', function (string $driver): void {
    $connection = 'regression_union_' . $driver;
    dblayerAddConnectionForDriver($driver, $connection);
    $tableOne = dblayerTable('t1');
    $tableTwo = dblayerTable('t2');

    DB::statement(sprintf('create table %s (id integer)', $tableOne), [], $connection);
    DB::statement(sprintf('create table %s (id integer)', $tableTwo), [], $connection);
    DB::statement(sprintf('insert into %s (id) values (1)', $tableOne), [], $connection);
    DB::statement(sprintf('insert into %s (id) values (2)', $tableTwo), [], $connection);

    $rows = DB::table($tableOne, $connection)
        ->select('id')
        ->union(static function ($query) use ($tableTwo): void {
            $query->from($tableTwo)->select('id');
        })
        ->get();

    expect($rows)->toHaveCount(2);
    $ids = array_map('intval', array_column($rows, 'id'));
    sort($ids);
    expect($ids)->toBe([1, 2]);
})->with('dblayer_drivers');

it('tracks nested transactions for manual facade api', function (string $driver): void {
    $connection = 'regression_nested_tx_' . $driver;
    dblayerAddConnectionForDriver($driver, $connection);

    expect(DB::transactionLevel($connection))->toBe(0);

    DB::beginTransaction($connection);
    expect(DB::transactionLevel($connection))->toBe(1);

    DB::beginTransaction($connection);
    expect(DB::transactionLevel($connection))->toBe(2);

    DB::rollBack($connection);
    expect(DB::transactionLevel($connection))->toBe(1);

    DB::rollBack($connection);
    expect(DB::transactionLevel($connection))->toBe(0);
})->with('dblayer_drivers');

it('chunks by non-integer keys without repeating pages', function (string $driver): void {
    $connection = 'regression_chunk_' . $driver;
    dblayerAddConnectionForDriver($driver, $connection);
    $schemaDriver = dblayerConnectionDriver($connection);
    $table = dblayerTable('items');

    DB::statement(
        sprintf('create table %s (uuid %s primary key)', $table, dblayerStringType($schemaDriver, 36)),
        [],
        $connection,
    );
    DB::statement(sprintf("insert into %s (uuid) values ('a1'), ('b2'), ('c3')", $table), [], $connection);

    $pages = [];

    DB::table($table, $connection)->chunkById(
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
})->with('dblayer_drivers');

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

it('expands host-list read and write overrides and sticky flag in config', function (): void {
    $config = ConnectionConfig::fromArray([
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'database' => 'app',
        'username' => 'root',
        'read' => [
            'host' => ['10.0.0.1', '10.0.0.2'],
        ],
        'write' => [
            'host' => ['10.0.1.1', '10.0.1.2'],
        ],
        'sticky' => true,
    ]);

    expect($config->isSticky())->toBeTrue();
    expect($config->getReadConfigs())->toBe([
        ['host' => '10.0.0.1'],
        ['host' => '10.0.0.2'],
    ]);
    expect($config->getWriteConfigs())->toBe([
        ['host' => '10.0.1.1'],
        ['host' => '10.0.1.2'],
    ]);
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

it('supports round_robin read replica strategy across reconnects', function (string $driver): void {
    $baseConfig = dblayerRequireDriver($driver);

    if ($driver === 'sqlite') {
        $config = [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'read_strategy' => 'round_robin',
            'read' => [
                ['database' => ':memory:'],
                ['database' => ':memory:'],
            ],
        ];
    } else {
        $config = $baseConfig;
        $config['read_strategy'] = 'round_robin';
        $config['read'] = [$baseConfig, $baseConfig];
    }

    DB::addConnection($config, 'regression_round_robin');

    $connection = DB::connection('regression_round_robin');
    $connection->select('select 1');
    $first = $connection->getReadReplicaInfo()['selected_index'];

    $connection->reconnect(false);
    $connection->select('select 1');
    $second = $connection->getReadReplicaInfo()['selected_index'];

    expect([$first, $second])->toBe([0, 1]);
})->with('dblayer_drivers');

it('captures latency probes when least_latency read strategy is used', function (string $driver): void {
    $baseConfig = dblayerRequireDriver($driver);

    if ($driver === 'sqlite') {
        $config = [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'read_strategy' => 'least_latency',
            'read' => [
                ['database' => ':memory:'],
                ['database' => ':memory:'],
            ],
        ];
    } else {
        $config = $baseConfig;
        $config['read_strategy'] = 'least_latency';
        $config['read'] = [$baseConfig, $baseConfig];
    }

    DB::addConnection($config, 'regression_least_latency');

    $connection = DB::connection('regression_least_latency');
    $connection->select('select 1');

    $info = $connection->getReadReplicaInfo();

    expect($info['strategy'])->toBe('least_latency');
    expect($info['selected_index'])->toBeInt();
    expect($info['latencies_ms'])->toHaveCount(2);
})->with('dblayer_drivers');

it('routes subsequent reads to write pdo when sticky mode is enabled', function (string $driver): void {
    $baseConfig = dblayerRequireDriver($driver);

    if ($driver === 'sqlite') {
        $config = [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'sticky' => true,
            'read' => [
                ['database' => ':memory:'],
            ],
        ];
    } else {
        $config = $baseConfig;
        $config['sticky'] = true;
        $config['read'] = [$baseConfig];
    }

    DB::addConnection($config, 'regression_sticky_on');

    $connection = DB::connection('regression_sticky_on');

    $readPdoBefore = $connection->getReadPdo();
    $writePdo = $connection->getPdo();

    expect(spl_object_id($readPdoBefore))->not->toBe(spl_object_id($writePdo));

    $table = dblayerTable('sticky_items');
    $connection->statement(sprintf('create table %s (id integer)', $table));
    $connection->statement(sprintf('insert into %s (id) values (1)', $table));

    $readPdoAfter = $connection->getReadPdo();
    expect(spl_object_id($readPdoAfter))->toBe(spl_object_id($connection->getPdo()));
})->with('dblayer_drivers');

it('keeps reads on read pdo when sticky mode is disabled', function (string $driver): void {
    $baseConfig = dblayerRequireDriver($driver);

    if ($driver === 'sqlite') {
        $config = [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'sticky' => false,
            'read' => [
                ['database' => ':memory:'],
            ],
        ];
    } else {
        $config = $baseConfig;
        $config['sticky'] = false;
        $config['read'] = [$baseConfig];
    }

    DB::addConnection($config, 'regression_sticky_off');

    $connection = DB::connection('regression_sticky_off');

    $readPdoBefore = $connection->getReadPdo();
    $writePdo = $connection->getPdo();

    expect(spl_object_id($readPdoBefore))->not->toBe(spl_object_id($writePdo));

    $table = dblayerTable('sticky_items');
    $connection->statement(sprintf('create table %s (id integer)', $table));
    $connection->statement(sprintf('insert into %s (id) values (1)', $table));

    $readPdoAfter = $connection->getReadPdo();
    expect(spl_object_id($readPdoAfter))->not->toBe(spl_object_id($connection->getPdo()));
})->with('dblayer_drivers');

it('applies write override configuration for write pdo connection', function (): void {
    $databaseFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . 'dblayer-write-override-'
        . bin2hex(random_bytes(8))
        . '.sqlite';

    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'write' => [
            'database' => $databaseFile,
        ],
    ], 'regression_write_override');

    $connection = DB::connection('regression_write_override');
    $connection->statement('create table write_override_items (id integer)');
    $connection->statement('insert into write_override_items (id) values (1)');

    $pdo = new \PDO('sqlite:' . $databaseFile);
    $count = (int) $pdo->query('select count(*) from write_override_items')->fetchColumn();

    expect($count)->toBe(1);

    $connection->disconnect();
    unset($pdo, $connection);

    if (is_file($databaseFile)) {
        expect(@unlink($databaseFile))->toBeTrue();
    }
});

it('supports scalar and selectResultSets query helpers', function (string $driver): void {
    $connection = 'regression_scalar_' . $driver;
    dblayerAddConnectionForDriver($driver, $connection);
    $table = dblayerTable('numbers');

    DB::statement(sprintf('create table %s (value integer)', $table), [], $connection);
    DB::statement(sprintf('insert into %s (value) values (1), (2), (3)', $table), [], $connection);

    $sum = DB::scalar(sprintf('select sum(value) as total from %s', $table), [], $connection);
    expect((int) $sum)->toBe(6);

    $resultSets = DB::selectResultSets('select 1 as one', [], $connection);
    expect($resultSets)->toHaveCount(1);
    expect($resultSets[0][0]['one'] ?? null)->toBe(1);
})->with('dblayer_drivers');

it('fires cumulative query-time threshold callback once', function (string $driver): void {
    $connectionName = 'regression_query_time_' . $driver;
    dblayerAddConnectionForDriver($driver, $connectionName);

    $fired = 0;
    $lastSql = null;
    $lastConnection = null;

    DB::whenQueryingForLongerThan(0.0001, static function (Connection $connection, QueryExecuted $event) use (&$fired, &$lastSql, &$lastConnection): void {
        $fired++;
        $lastSql = $event->sql;
        $lastConnection = $connection;
    });

    DB::select('select 1', [], $connectionName);
    DB::select('select 1', [], $connectionName);

    expect($fired)->toBe(1);
    expect(strtolower((string) $lastSql))->toContain('select 1');
    expect($lastConnection)->toBeInstanceOf(Connection::class);
})->with('dblayer_drivers');

it('supports one-argument cumulative query-time callback signature', function (string $driver): void {
    $connectionName = 'regression_query_time_single_arg_' . $driver;
    dblayerAddConnectionForDriver($driver, $connectionName);

    $capturedEvent = null;

    DB::whenQueryingForLongerThan(0.0001, static function (QueryExecuted $event) use (&$capturedEvent): void {
        $capturedEvent = $event;
    });

    DB::select('select 1', [], $connectionName);

    expect($capturedEvent)->toBeInstanceOf(QueryExecuted::class);
})->with('dblayer_drivers');

it('supports query cancellation and deadline wrappers', function (string $driver): void {
    $connectionName = 'regression_query_controls_' . $driver;
    dblayerAddConnectionForDriver($driver, $connectionName);

    expect(fn (): mixed => DB::withQueryCancellation(
        static fn (): bool => true,
        static fn (): mixed => DB::select('select 1', [], $connectionName),
        $connectionName,
    ))->toThrow(ConnectionException::class);

    expect(fn (): mixed => DB::withQueryDeadline(
        0.0,
        static fn (): mixed => DB::select('select 1', [], $connectionName),
        $connectionName,
    ))->toThrow(ConnectionException::class);
})->with('dblayer_drivers');

it('collects and flushes telemetry payloads', function (string $driver): void {
    DB::enableTelemetry();

    $connectionName = 'regression_telemetry_' . $driver;
    dblayerAddConnectionForDriver($driver, $connectionName);
    $schemaDriver = dblayerConnectionDriver($connectionName);
    $table = dblayerTable('telemetry_items');

    DB::statement(
        sprintf('create table %s (%s, name %s)', $table, dblayerAutoIncrementPrimaryKey($schemaDriver), dblayerStringType($schemaDriver)),
        [],
        $connectionName,
    );
    DB::table($table, $connectionName)->insert(['name' => 'probe']);
    DB::table($table, $connectionName)->select('name')->limit(1)->get();
    DB::beginTransaction($connectionName);
    DB::rollBack($connectionName);

    $snapshot = DB::telemetry();
    expect($snapshot['summary']['query_count'])->toBeGreaterThan(0);
    expect($snapshot['summary']['transaction_event_count'])->toBeGreaterThan(0);

    $flushed = DB::flushTelemetry();
    expect($flushed['summary']['query_count'])->toBeGreaterThan(0);
    expect(DB::telemetry()['summary']['query_count'])->toBe(0);

    DB::disableTelemetry();
})->with('dblayer_drivers');

it('does not flag legitimate unions and still catches injected union payloads', function (): void {
    $validator = new QueryValidator();

    expect(fn () => $validator->detectSqlInjection('select id from t1 union select id from t2'))
        ->not->toThrow(SecurityException::class);

    expect(fn () => $validator->detectSqlInjection(
        'select * from users where name = "x" or 1=1 union select password from admins'
    ))->toThrow(SecurityException::class);
});
