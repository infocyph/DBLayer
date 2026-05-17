<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Events\DatabaseEvents\QueryExecuted;
use Infocyph\DBLayer\Events\DatabaseEvents\QueryFailed;
use Infocyph\DBLayer\Events\Events;
use Infocyph\DBLayer\Exceptions\ConnectionException;
use Infocyph\DBLayer\Exceptions\QueryException;
use Infocyph\DBLayer\Exceptions\SecurityException;
use Infocyph\DBLayer\Security\QueryValidator;
use Infocyph\DBLayer\Security\Security;
use Infocyph\DBLayer\Security\SecurityMode;
use Psr\Log\AbstractLogger;

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
            unset($page);
            $pages[] = array_column($rows, 'uuid');

            return true;
        },
        'uuid',
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

it('keeps statement cache disabled by default with conservative size', function (): void {
    $config = ConnectionConfig::fromArray([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]);

    expect($config->shouldUseStatementCache())->toBeFalse();
    expect($config->statementCacheSize())->toBe(64);
});

it('recursively redacts sensitive values in safe config export', function (): void {
    $config = ConnectionConfig::fromArray([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'password' => 'root-secret',
        'PASSWORD' => 'root-secret-uppercased',
        'token' => 'root-token',
        'read' => [
            [
                'database' => ':memory:',
                'password' => 'read-secret',
                'options' => [
                    'ssl_key' => '/tmp/read.key',
                    'SSL_CERT' => '/tmp/read.crt',
                ],
            ],
        ],
        'write' => [
            [
                'database' => ':memory:',
                'private_key' => 'write-private',
                'Secret' => 'write-secret',
            ],
        ],
        'options' => [
            'ssl_ca' => '/tmp/ca.pem',
            'TLS_KEY' => '/tmp/tls.key',
            'nested' => [
                'passphrase' => 'nested-secret',
                'TOKEN' => 'nested-token',
            ],
        ],
        'security' => [
            'rate_limit_key' => 'safe-visible',
        ],
    ]);

    $safe = $config->toSafeArray();

    expect(data_get($safe, 'password'))->toBe('[redacted]');
    expect(data_get($safe, 'PASSWORD'))->toBe('[redacted]');
    expect(data_get($safe, 'token'))->toBe('[redacted]');
    expect(data_get($safe, 'read.0.password'))->toBe('[redacted]');
    expect(data_get($safe, 'read.0.options.ssl_key'))->toBe('[redacted]');
    expect(data_get($safe, 'read.0.options.SSL_CERT'))->toBe('[redacted]');
    expect(data_get($safe, 'write.0.private_key'))->toBe('[redacted]');
    expect(data_get($safe, 'write.0.Secret'))->toBe('[redacted]');
    expect(data_get($safe, 'options.ssl_ca'))->toBe('[redacted]');
    expect(data_get($safe, 'options.TLS_KEY'))->toBe('[redacted]');
    expect(data_get($safe, 'options.nested.passphrase'))->toBe('[redacted]');
    expect(data_get($safe, 'options.nested.TOKEN'))->toBe('[redacted]');
    expect(data_get($safe, 'security.rate_limit_key'))->toBe('safe-visible');
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
    $listener = static function (): void {};

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

it('reuses cached least-latency replica within ttl and re-probes after ttl expiry', function (): void {
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'read_strategy' => 'least_latency',
        'read_latency_ttl' => 30,
        'read' => [
            ['database' => ':memory:'],
            ['database' => ':memory:'],
        ],
    ], 'regression_least_latency_ttl_cached');

    $cachedConnection = DB::connection('regression_least_latency_ttl_cached');
    $cachedConnection->select('select 1');
    $first = $cachedConnection->getReadReplicaInfo();
    $cachedConnection->select('select 1');
    $second = $cachedConnection->getReadReplicaInfo();

    expect($first['selected_index'])->toBeInt();
    expect($first['latencies_ms'])->toHaveCount(2);
    expect($second['selected_index'])->toBe($first['selected_index']);
    expect($second['latencies_ms'])->toBe($first['latencies_ms']);

    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'read_strategy' => 'least_latency',
        'read_latency_ttl' => 0,
        'read' => [
            ['database' => ':memory:'],
            ['database' => ':memory:'],
        ],
    ], 'regression_least_latency_ttl_expired');

    $reprobeConnection = DB::connection('regression_least_latency_ttl_expired');
    $reprobeConnection->select('select 1');
    $reprobeConnection->select('select 1');
    $reprobed = $reprobeConnection->getReadReplicaInfo();

    expect($reprobed['latencies_ms'])->toHaveCount(2);
});

it('respects least-latency probe sample size when healthy replicas are found', function (): void {
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'read_strategy' => 'least_latency',
        'read_latency_ttl' => 0,
        'read_probe_sample_size' => 1,
        'read' => [
            ['database' => ':memory:'],
            ['database' => ':memory:'],
            ['database' => ':memory:'],
        ],
    ], 'regression_least_latency_sample_size');

    $connection = DB::connection('regression_least_latency_sample_size');
    $connection->select('select 1');

    $info = $connection->getReadReplicaInfo();

    expect($info['latencies_ms'])->toHaveCount(1);
});

it('evicts failed cached least-latency replica and marks cooldown', function (): void {
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'read_strategy' => 'least_latency',
        'read_latency_ttl' => 30,
        'read_health_cooldown' => 30,
        'read' => [
            ['database' => ':memory:'],
            ['database' => ':memory:'],
        ],
    ], 'regression_least_latency_cached_failure');

    $connection = DB::connection('regression_least_latency_cached_failure');
    $connection->select('select 1');

    $reflection = new \ReflectionClass($connection);
    $markFailure = $reflection->getMethod('markReadReplicaFailure');
    $cachedIndex = $reflection->getProperty('leastLatencyReplicaIndex');
    $cachedAt = $reflection->getProperty('leastLatencyResolvedAt');
    $unavailableUntil = $reflection->getProperty('readReplicaUnavailableUntil');

    // Simulate a cached winner that subsequently failed and is now suppressed.
    $cachedIndex->setValue($connection, 1);
    $cachedAt->setValue($connection, time());
    $markFailure->invoke($connection, 1);
    $connection->reconnect(false);

    $connection->select('select 1');
    $info = $connection->getReadReplicaInfo();

    /** @var array<int,int> $suppressed */
    $suppressed = $unavailableUntil->getValue($connection);
    expect($info['selected_index'])->toBe(0);
    expect($suppressed)->toHaveKey(1);
    expect($cachedIndex->getValue($connection))->toBe(0);
});

it('suppresses failed replicas during health cooldown windows', function (): void {
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'read_strategy' => 'least_latency',
        'read_health_cooldown' => 30,
        'read' => [
            ['database' => ':memory:'],
            ['database' => ':memory:'],
        ],
    ], 'regression_least_latency_cooldown');

    $connection = DB::connection('regression_least_latency_cooldown');
    $reflection = new \ReflectionClass($connection);
    $markFailure = $reflection->getMethod('markReadReplicaFailure');
    $available = $reflection->getMethod('availableReadReplicaIndexes');
    $unavailableUntil = $reflection->getProperty('readReplicaUnavailableUntil');

    $markFailure->invoke($connection, 1);

    /** @var list<int> $duringCooldown */
    $duringCooldown = $available->invoke($connection, 2);
    expect($duringCooldown)->toBe([0]);

    $unavailableUntil->setValue($connection, [1 => time() - 1]);

    /** @var list<int> $afterCooldown */
    $afterCooldown = $available->invoke($connection, 2);
    expect($afterCooldown)->toBe([0, 1]);
});

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

it('supports optional prepared statement caching with bounded lru size', function (): void {
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'statement_cache_enabled' => true,
        'statement_cache_size' => 1,
    ], 'regression_statement_cache');

    $connection = DB::connection('regression_statement_cache');

    $connection->select('select 1 as one');
    $connection->select('select 2 as two');

    $reflection = new \ReflectionClass($connection);
    $cacheProperty = $reflection->getProperty('statementCache');

    /** @var array{read:array<string,\PDOStatement>,write:array<string,\PDOStatement>} $cache */
    $cache = $cacheProperty->getValue($connection);
    expect($cache['read'])->toHaveCount(1);

    $connection->transaction(static function ($conn): void {
        $conn->select('select 3 as three');
    });

    /** @var array{read:array<string,\PDOStatement>,write:array<string,\PDOStatement>} $cacheAfter */
    $cacheAfter = $cacheProperty->getValue($connection);
    expect($cacheAfter['read'])->toHaveCount(1);
});

it('uses stable statement-cache keys when query comments change dynamically', function (): void {
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'statement_cache_enabled' => true,
        'statement_cache_size' => 8,
        'query_comment_enabled' => true,
        'query_comment_context' => [
            'app' => 'regression',
        ],
    ], 'regression_statement_cache_comments');

    $connection = DB::connection('regression_statement_cache_comments');
    DB::enableQueryLog();

    $connection->setQueryCommentContext([
        'app' => 'regression',
        'trace' => 'first',
    ]);
    $connection->select('select 1 as one');

    $connection->setQueryCommentContext([
        'app' => 'regression',
        'trace' => 'second',
    ]);
    $connection->select('select 1 as one');

    $reflection = new \ReflectionClass($connection);
    $cacheProperty = $reflection->getProperty('statementCache');

    /** @var array{read:array<string,\PDOStatement>,write:array<string,\PDOStatement>} $cache */
    $cache = $cacheProperty->getValue($connection);
    $queryLog = DB::getQueryLog();

    expect($cache['read'])->toHaveCount(1);
    expect($queryLog)->toHaveCount(2);
    expect((string) ($queryLog[0]['query'] ?? ''))->toStartWith('/* ');
    expect((string) ($queryLog[1]['query'] ?? ''))->toStartWith('/* ');

    DB::disableQueryLog();
});

it('applies prepared statement cache lifecycle rules safely', function (): void {
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'statement_cache_enabled' => true,
        'statement_cache_size' => 2,
    ], 'regression_statement_cache_lifecycle');

    $connection = DB::connection('regression_statement_cache_lifecycle');
    $reflection = new \ReflectionClass($connection);
    $cacheProperty = $reflection->getProperty('statementCache');

    $connection->select('select 1 as one');

    /** @var array{read:array<string,\PDOStatement>,write:array<string,\PDOStatement>} $cacheAfterFirst */
    $cacheAfterFirst = $cacheProperty->getValue($connection);
    $firstStatement = reset($cacheAfterFirst['read']);
    expect($cacheAfterFirst['read'])->toHaveCount(1);
    expect($firstStatement)->toBeInstanceOf(\PDOStatement::class);

    $connection->select('select 1 as one');

    /** @var array{read:array<string,\PDOStatement>,write:array<string,\PDOStatement>} $cacheAfterSecond */
    $cacheAfterSecond = $cacheProperty->getValue($connection);
    $secondStatement = reset($cacheAfterSecond['read']);
    expect($cacheAfterSecond['read'])->toHaveCount(1);
    expect(spl_object_id($secondStatement))->toBe(spl_object_id($firstStatement));

    $connection->transaction(static function (Connection $conn): void {
        $conn->select('select 2 as two');
    });

    /** @var array{read:array<string,\PDOStatement>,write:array<string,\PDOStatement>} $cacheAfterTransaction */
    $cacheAfterTransaction = $cacheProperty->getValue($connection);
    expect($cacheAfterTransaction['read'])->toHaveCount(1);

    $table = dblayerTable('statement_cache_lifecycle_items');
    $connection->statement(sprintf('create table %s (payload blob)', $table));

    $stream = fopen('php://temp', 'rb+');
    expect($stream)->not->toBeFalse();
    fwrite($stream, 'payload');
    rewind($stream);
    $connection->statement(sprintf('insert into %s (payload) values (?)', $table), [$stream]);
    fclose($stream);

    /** @var array{read:array<string,\PDOStatement>,write:array<string,\PDOStatement>} $cacheAfterResourceBinding */
    $cacheAfterResourceBinding = $cacheProperty->getValue($connection);
    expect($cacheAfterResourceBinding['write'])->toHaveCount(1);

    $connection->reconnect(false);

    /** @var array{read:array<string,\PDOStatement>,write:array<string,\PDOStatement>} $cacheAfterReadReconnect */
    $cacheAfterReadReconnect = $cacheProperty->getValue($connection);
    expect($cacheAfterReadReconnect['read'])->toHaveCount(0);

    $connection->select('select 3 as three');
    $connection->select('select 4 as four');
    $connection->select('select 5 as five');

    /** @var array{read:array<string,\PDOStatement>,write:array<string,\PDOStatement>} $cacheAfterEviction */
    $cacheAfterEviction = $cacheProperty->getValue($connection);
    expect($cacheAfterEviction['read'])->toHaveCount(2);

    $connection->disconnect();

    /** @var array{read:array<string,\PDOStatement>,write:array<string,\PDOStatement>} $cacheAfterDisconnect */
    $cacheAfterDisconnect = $cacheProperty->getValue($connection);
    expect($cacheAfterDisconnect['read'])->toHaveCount(0);
    expect($cacheAfterDisconnect['write'])->toHaveCount(0);
});

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

it('routes expanded write and control keywords away from read classification', function (): void {
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ], 'regression_write_keyword_routing');

    $connection = DB::connection('regression_write_keyword_routing');

    $sqlStatements = [
        'MERGE INTO users USING tmp ON users.id = tmp.id WHEN MATCHED THEN UPDATE SET id = tmp.id',
        'CALL do_work()',
        'GRANT SELECT ON users TO app',
        'REVOKE SELECT ON users FROM app',
        'ANALYZE users',
        'VACUUM',
        'PRAGMA journal_mode = WAL',
        'SET search_path = public',
        'LOCK TABLE users',
        'UNLOCK TABLES',
        'WITH c AS (SELECT 1) UPDATE users SET id = 1',
        'WITH c AS (SELECT 1) DELETE FROM users',
    ];

    $connection->pretend(static function (Connection $conn) use ($sqlStatements): void {
        foreach ($sqlStatements as $sql) {
            $conn->execute($sql);
        }

        $conn->execute('select 1');
    });

    $stats = $connection->getStats();

    expect($stats['writes'])->toBe(count($sqlStatements));
    expect($stats['reads'])->toBe(1);
});

it('adds sanitized query comments when configured', function (string $driver): void {
    $connectionName = 'regression_query_comment_' . $driver;
    dblayerAddConnectionForDriver($driver, $connectionName, [
        'query_comment_enabled' => true,
        'query_comment_max_length' => 24,
        'query_comment_context' => [
            'app' => 'api-service',
            'route' => 'merchant/list',
            'trace' => 'abc*/--123',
        ],
    ]);

    $connection = DB::connection($connectionName);
    $logged = $connection->pretend(static function (Connection $conn): void {
        $conn->select('select 1 as ok');
    });

    $sql = (string) ($logged[0]['sql'] ?? '');
    expect($sql)->toStartWith('/* ');
    expect($sql)->toContain('app=api-service');

    preg_match('#/\*\s*(.*?)\s*\*/#', $sql, $matches);
    $commentPayload = (string) ($matches[1] ?? '');

    expect(strlen($commentPayload))->toBeLessThanOrEqual(32);
    expect($commentPayload)->not->toContain('*');
})->with('dblayer_drivers');

it('sanitizes and bounds query comment context against delimiter and control characters', function (): void {
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'query_comment_enabled' => true,
        'query_comment_max_length' => 48,
        'query_comment_context' => [
            'trace' => "abc*/\nnext;\"quoted\" -- drop table users; select * from secrets",
            'unicode' => "merchant-\u{1F680}",
            'path' => '../tenant/alpha',
            'arrayValue' => ['x' => 'y'],
            'objectValue' => (object) ['a' => 'b'],
        ],
    ], 'regression_query_comment_safety');

    $connection = DB::connection('regression_query_comment_safety');
    $logged = $connection->pretend(static function (Connection $conn): void {
        $conn->select('select 1');
    });

    $sql = (string) ($logged[0]['sql'] ?? '');
    expect($sql)->toStartWith('/* ');

    preg_match('#/\*\s*(.*?)\s*\*/#', $sql, $matches);
    $commentPayload = (string) ($matches[1] ?? '');

    expect(strlen($commentPayload))->toBeLessThanOrEqual(48);
    expect($commentPayload)->not->toContain('*/');
    expect($commentPayload)->not->toContain("\n");
    expect($commentPayload)->not->toContain(';');
    expect($commentPayload)->not->toContain('"');
    expect($commentPayload)->not->toContain(' drop table ');
    expect($commentPayload)->not->toContain('arrayValue=');
    expect($commentPayload)->not->toContain('objectValue=');
});

it('invokes connection lifecycle hooks around connect and reconnect', function (): void {
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ], 'regression_lifecycle_hooks');

    $connection = DB::connection('regression_lifecycle_hooks');

    $beforeConnect = 0;
    $afterConnect = 0;
    $beforeReconnect = 0;
    $afterReconnect = 0;

    $connection
        ->beforeConnect(static function () use (&$beforeConnect): void {
            $beforeConnect++;
        })
        ->afterConnect(static function () use (&$afterConnect): void {
            $afterConnect++;
        })
        ->beforeReconnect(static function () use (&$beforeReconnect): void {
            $beforeReconnect++;
        })
        ->afterReconnect(static function () use (&$afterReconnect): void {
            $afterReconnect++;
        });

    $connection->select('select 1');
    $connection->reconnect(true);

    expect($beforeConnect)->toBeGreaterThanOrEqual(2);
    expect($afterConnect)->toBeGreaterThanOrEqual(2);
    expect($beforeReconnect)->toBe(1);
    expect($afterReconnect)->toBe(1);
});

it('enforces read-only sqlite read replica sessions', function (): void {
    $databaseFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . 'dblayer-readonly-read-'
        . bin2hex(random_bytes(8))
        . '.sqlite';

    DB::addConnection([
        'driver' => 'sqlite',
        'database' => $databaseFile,
        'sticky' => false,
        'read' => [
            ['database' => $databaseFile],
        ],
    ], 'regression_sqlite_readonly_read');

    $connection = DB::connection('regression_sqlite_readonly_read');
    $table = dblayerTable('readonly_items');

    $readPdo = $connection->getReadPdo();
    $writePdo = $connection->getPdo();

    expect(spl_object_id($readPdo))->not->toBe(spl_object_id($writePdo));

    $connection->statement(sprintf('create table %s (id integer primary key autoincrement)', $table));
    $connection->statement(sprintf('insert into %s (id) values (1)', $table));

    $count = (int) ($readPdo->query(sprintf('select count(*) from %s', $table))->fetchColumn() ?: 0);
    expect($count)->toBe(1);

    expect(static fn(): int|false => $readPdo->exec(sprintf('insert into %s (id) values (2)', $table)))
        ->toThrow(\PDOException::class);

    $connection->disconnect();
    unset($readPdo, $writePdo, $connection);

    if (is_file($databaseFile)) {
        expect(unlink($databaseFile))->toBeTrue();
    }
});

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
        expect(unlink($databaseFile))->toBeTrue();
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

it('streams rows through facade helpers', function (string $driver): void {
    $connection = 'regression_stream_' . $driver;
    dblayerAddConnectionForDriver($driver, $connection);
    $table = dblayerTable('stream_rows');

    DB::statement(sprintf('create table %s (value integer)', $table), [], $connection);
    DB::statement(sprintf('insert into %s (value) values (1), (2), (3)', $table), [], $connection);

    $values = [];
    foreach (DB::stream(sprintf('select value from %s order by value asc', $table), [], $connection) as $row) {
        $values[] = (int) ($row['value'] ?? 0);
    }

    $yielded = [];
    foreach (DB::yieldRows(sprintf('select value from %s order by value asc', $table), [], $connection) as $row) {
        $yielded[] = (int) ($row['value'] ?? 0);
    }

    expect($values)->toBe([1, 2, 3]);
    expect($yielded)->toBe([1, 2, 3]);
})->with('dblayer_drivers');

it('closes stream cursors on early break and loop exceptions', function (): void {
    $connectionName = 'regression_stream_cursor_cleanup';
    dblayerAddConnectionForDriver('sqlite', $connectionName);
    $table = dblayerTable('stream_cleanup_rows');

    DB::statement(sprintf('create table %s (value integer)', $table), [], $connectionName);
    DB::statement(sprintf('insert into %s (value) values (1), (2), (3)', $table), [], $connectionName);

    foreach (DB::stream(sprintf('select value from %s order by value asc', $table), [], $connectionName) as $row) {
        unset($row);

        break;
    }

    // Should remain operable after early stream termination.
    DB::statement(sprintf('insert into %s (value) values (4)', $table), [], $connectionName);

    try {
        foreach (DB::yieldRows(sprintf('select value from %s order by value asc', $table), [], $connectionName) as $row) {
            if ((int) ($row['value'] ?? 0) === 2) {
                throw new RuntimeException('stop');
            }
        }
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('stop');
    }

    $count = (int) DB::scalar(sprintf('select count(*) from %s', $table), [], $connectionName);
    expect($count)->toBe(4);
});

it('streams larger result sets end-to-end without buffering errors', function (): void {
    $connectionName = 'regression_stream_large';
    dblayerAddConnectionForDriver('sqlite', $connectionName);
    $table = dblayerTable('stream_large_rows');

    DB::statement(sprintf('create table %s (value integer)', $table), [], $connectionName);

    for ($value = 1; $value <= 512; $value++) {
        DB::statement(sprintf('insert into %s (value) values (?)', $table), [$value], $connectionName);
    }

    $count = 0;
    foreach (DB::stream(sprintf('select value from %s order by value asc', $table), [], $connectionName) as $row) {
        $count++;
        expect((int) ($row['value'] ?? 0))->toBeGreaterThan(0);
    }

    expect($count)->toBe(512);
});

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

    expect(fn(): mixed => DB::withQueryCancellation(
        static fn(): bool => true,
        static fn(): mixed => DB::select('select 1', [], $connectionName),
        $connectionName,
    ))->toThrow(ConnectionException::class);

    expect(fn(): mixed => DB::withQueryDeadline(
        0.0,
        static fn(): mixed => DB::select('select 1', [], $connectionName),
        $connectionName,
    ))->toThrow(ConnectionException::class);
})->with('dblayer_drivers');

it('enforces configured per-connection query rate limits', function (string $driver): void {
    $connectionName = 'regression_rate_limit_' . $driver;
    $rateKey = 'rate:' . bin2hex(random_bytes(8));

    dblayerAddConnectionForDriver($driver, $connectionName, [
        'security' => [
            // Use minute-level limit to avoid second-boundary flakes in CI.
            'queries_per_second' => 0,
            'queries_per_minute' => 1,
            'rate_limit_key' => $rateKey,
        ],
    ]);

    DB::select('select 1', [], $connectionName);

    $blocked = false;

    for ($attempt = 0; $attempt < 3; $attempt++) {
        try {
            DB::select('select 1', [], $connectionName);
        } catch (SecurityException) {
            $blocked = true;

            break;
        }
    }

    expect($blocked)->toBeTrue();

    Security::resetRateLimit($rateKey);
})->with('dblayer_drivers');

it('rejects unsafe operators in where clauses early', function (string $driver): void {
    $connectionName = 'regression_invalid_operator_' . $driver;
    dblayerAddConnectionForDriver($driver, $connectionName);

    expect(static function () use ($connectionName): void {
        DB::table('users', $connectionName)->where('id', '; drop table users; --', 1);
    })->toThrow(QueryException::class);
})->with('dblayer_drivers');

it('does not execute queries while in pretend mode', function (string $driver): void {
    $connectionName = 'regression_pretend_' . $driver;
    dblayerAddConnectionForDriver($driver, $connectionName);
    $schemaDriver = dblayerConnectionDriver($connectionName);
    $table = dblayerTable('pretend_items');

    DB::statement(
        sprintf('create table %s (%s, name %s)', $table, dblayerAutoIncrementPrimaryKey($schemaDriver), dblayerStringType($schemaDriver)),
        [],
        $connectionName,
    );

    $connection = DB::connection($connectionName);
    $logged = $connection->pretend(static function (Connection $conn) use ($table): void {
        $conn->statement(sprintf("insert into %s (name) values ('ghost')", $table));
        $conn->select(sprintf('select * from %s', $table));
    });

    expect($logged)->toHaveCount(2);
    expect((int) DB::scalar(sprintf('select count(*) from %s', $table), [], $connectionName))->toBe(0);
})->with('dblayer_drivers');

it('emits success lifecycle events in pretend mode without mutating data', function (): void {
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ], 'regression_pretend_lifecycle');

    DB::statement('create table pretend_lifecycle_items (id integer, name text)', [], 'regression_pretend_lifecycle');

    $sequence = [];

    $onExecuting = static function () use (&$sequence): void {
        $sequence[] = 'executing';
    };
    $onExecuted = static function () use (&$sequence): void {
        $sequence[] = 'executed';
    };
    $onFailed = static function () use (&$sequence): void {
        $sequence[] = 'failed';
    };

    Events::listen('db.query.executing', $onExecuting);
    Events::listen('db.query.executed', $onExecuted);
    Events::listen('db.query.failed', $onFailed);

    $connection = DB::connection('regression_pretend_lifecycle');
    $logged = $connection->pretend(static function (Connection $conn): void {
        $conn->statement("insert into pretend_lifecycle_items (id, name) values (1, 'ghost')");
    });

    expect($logged)->toHaveCount(1);
    expect($sequence)->toBe(['executing', 'executed']);
    expect((int) DB::scalar('select count(*) from pretend_lifecycle_items', [], 'regression_pretend_lifecycle'))->toBe(0);

    Events::forget('db.query.executing', $onExecuting);
    Events::forget('db.query.executed', $onExecuted);
    Events::forget('db.query.failed', $onFailed);
});

it('redacts query bindings in logger output by default', function (string $driver): void {
    $connectionName = 'regression_logger_redaction_' . $driver;
    dblayerAddConnectionForDriver($driver, $connectionName);
    $schemaDriver = dblayerConnectionDriver($connectionName);
    $table = dblayerTable('logger_items');

    DB::statement(
        sprintf('create table %s (%s, name %s)', $table, dblayerAutoIncrementPrimaryKey($schemaDriver), dblayerStringType($schemaDriver)),
        [],
        $connectionName,
    );
    DB::table($table, $connectionName)->insert(['name' => 'seed']);

    $logFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . 'dblayer-log-redaction-'
        . bin2hex(random_bytes(8))
        . '.log';

    DB::enableLogger($logFile);
    DB::table($table, $connectionName)
        ->whereRaw('name = ?', ['secret-token'])
        ->limit(1)
        ->get();

    $contents = is_file($logFile) ? (string) file_get_contents($logFile) : '';

    expect($contents)->toContain('[redacted:string:12]');
    expect($contents)->not->toContain('secret-token');

    DB::disableLogger();
    if (is_file($logFile)) {
        unlink($logFile);
    }
})->with('dblayer_drivers');

it('logs query failures with sanitized context and without raw binding leakage', function (): void {
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ], 'regression_failure_logging_sanitized');

    $records = new \ArrayObject();
    $psrLogger = new class($records) extends AbstractLogger {
        public function __construct(private \ArrayObject $records) {}

        public function log($level, \Stringable|string $message, array $context = []): void
        {
            $this->records->append([
                'level' => (string) $level,
                'message' => (string) $message,
                'context' => $context,
            ]);
        }
    };

    DB::enableLogger(null, $psrLogger);

    expect(static fn(): array => DB::select(
        'select * from missing_failure_table where token = ?',
        ['super-secret-token'],
        'regression_failure_logging_sanitized',
    ))->toThrow(ConnectionException::class);

    $lastRecord = $records[(int) ($records->count() - 1)] ?? null;
    $serializedContext = json_encode(is_array($lastRecord) ? ($lastRecord['context'] ?? []) : []) ?: '';

    expect(is_array($lastRecord))->toBeTrue();
    expect(($lastRecord['level'] ?? null) === 'error' || ($lastRecord['level'] ?? null) === 'ERROR')->toBeTrue();
    expect((string) ($lastRecord['message'] ?? ''))->toContain('fingerprint');
    expect($serializedContext)->not->toContain('super-secret-token');

    DB::disableLogger();
    DB::setPsrLogger(null);
});

it('stores failed-query telemetry with redacted sql and binding context by default', function (): void {
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ], 'regression_failure_telemetry_sanitized');

    DB::enableTelemetry();

    expect(static fn(): array => DB::select(
        'select * from missing_telemetry_table where token = ?',
        ['secret-failure-token'],
        'regression_failure_telemetry_sanitized',
    ))->toThrow(ConnectionException::class);

    $snapshot = DB::telemetry();
    /** @var list<array<string,mixed>> $queries */
    $queries = is_array($snapshot['queries'] ?? null) ? $snapshot['queries'] : [];
    $failed = end($queries);

    expect(is_array($failed))->toBeTrue();
    expect($failed['success'] ?? null)->toBeFalse();
    expect($failed['sql'] ?? null)->toBe('[redacted]');
    expect($failed['error'] ?? null)->toBe('[redacted]');
    expect($failed['statement'] ?? null)->toBe('SELECT');
    expect($failed['bindings_count'] ?? null)->toBe(1);
    expect($failed['bindings_redacted'] ?? null)->toBeTrue();

    DB::disableTelemetry();
});

it('forwards logger entries to configured PSR-3 backend', function (string $driver): void {
    $connectionName = 'regression_logger_psr3_' . $driver;
    dblayerAddConnectionForDriver($driver, $connectionName);
    $schemaDriver = dblayerConnectionDriver($connectionName);
    $table = dblayerTable('logger_psr_items');

    DB::statement(
        sprintf('create table %s (%s, name %s)', $table, dblayerAutoIncrementPrimaryKey($schemaDriver), dblayerStringType($schemaDriver)),
        [],
        $connectionName,
    );
    DB::table($table, $connectionName)->insert(['name' => 'seed']);

    $records = new \ArrayObject();
    $psrLogger = new class($records) extends AbstractLogger {
        public function __construct(private \ArrayObject $records) {}

        public function log($level, \Stringable|string $message, array $context = []): void
        {
            $this->records->append([
                'level' => (string) $level,
                'message' => (string) $message,
                'context' => $context,
            ]);
        }
    };

    DB::enableLogger(null, $psrLogger);
    DB::table($table, $connectionName)
        ->whereRaw('name = ?', ['secret-token'])
        ->limit(1)
        ->get();

    $lastRecord = $records[(int) ($records->count() - 1)] ?? null;
    $serializedContext = json_encode(is_array($lastRecord) ? ($lastRecord['context'] ?? []) : []) ?: '';

    expect($records->count())->toBeGreaterThan(0);
    expect(is_array($lastRecord))->toBeTrue();
    expect($lastRecord['level'] ?? null)->toBe('info');
    expect($serializedContext)->toContain('[redacted:string:12]');
    expect($serializedContext)->not->toContain('secret-token');

    DB::setPsrLogger(null);
    DB::disableLogger();
})->with('dblayer_drivers');

it('supports configuring psr logger backend via facade helper', function (string $driver): void {
    $connectionName = 'regression_logger_set_psr_' . $driver;
    dblayerAddConnectionForDriver($driver, $connectionName);
    $schemaDriver = dblayerConnectionDriver($connectionName);
    $table = dblayerTable('logger_set_psr_items');

    DB::statement(
        sprintf('create table %s (%s, name %s)', $table, dblayerAutoIncrementPrimaryKey($schemaDriver), dblayerStringType($schemaDriver)),
        [],
        $connectionName,
    );
    DB::table($table, $connectionName)->insert(['name' => 'seed']);

    $records = new \ArrayObject();
    $psrLogger = new class($records) extends AbstractLogger {
        public function __construct(private \ArrayObject $records) {}

        public function log($level, \Stringable|string $message, array $context = []): void
        {
            $this->records->append([
                'level' => (string) $level,
                'message' => (string) $message,
                'context' => $context,
            ]);
        }
    };

    $logFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . 'dblayer-log-psr-helper-'
        . bin2hex(random_bytes(8))
        . '.log';

    DB::enableLogger($logFile);
    DB::setPsrLogger($psrLogger);
    DB::table($table, $connectionName)->limit(1)->get();

    expect($records->count())->toBeGreaterThan(0);

    DB::setPsrLogger(null);
    DB::disableLogger();

    if (is_file($logFile)) {
        unlink($logFile);
    }
})->with('dblayer_drivers');

it('does not write when logger target is a symlink', function (string $driver): void {
    $connectionName = 'regression_logger_symlink_' . $driver;
    dblayerAddConnectionForDriver($driver, $connectionName);
    $schemaDriver = dblayerConnectionDriver($connectionName);
    $table = dblayerTable('logger_symlink_items');

    DB::statement(
        sprintf('create table %s (%s, name %s)', $table, dblayerAutoIncrementPrimaryKey($schemaDriver), dblayerStringType($schemaDriver)),
        [],
        $connectionName,
    );
    DB::table($table, $connectionName)->insert(['name' => 'seed']);

    $baseDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . 'dblayer-log-symlink-'
        . bin2hex(random_bytes(6));
    $realLog = $baseDir . DIRECTORY_SEPARATOR . 'real.log';
    $linkLog = $baseDir . DIRECTORY_SEPARATOR . 'link.log';

    if ((! is_dir($baseDir)) && (! mkdir($baseDir, 0o700, true))) {
        test()->markTestSkipped('Unable to create temporary directory for symlink logger test.');

        return;
    }

    file_put_contents($realLog, '');

    $linked = false;

    if (function_exists('symlink')) {
        set_error_handler(static fn(): bool => true);

        try {
            $linked = symlink($realLog, $linkLog);
        } finally {
            restore_error_handler();
        }
    }

    if (! $linked) {
        if (is_file($realLog)) {
            unlink($realLog);
        }
        if (is_dir($baseDir)) {
            rmdir($baseDir);
        }
        test()->markTestSkipped('Symlink creation is not available in this environment.');

        return;
    }

    DB::enableLogger($linkLog);
    DB::table($table, $connectionName)
        ->whereRaw('name = ?', ['secret-token'])
        ->limit(1)
        ->get();
    DB::disableLogger();

    $contents = is_file($realLog) ? (string) file_get_contents($realLog) : '';

    expect($contents)->toBe('');

    if (is_link($linkLog)) {
        unlink($linkLog);
    }
    if (is_file($realLog)) {
        unlink($realLog);
    }
    if (is_dir($baseDir)) {
        rmdir($baseDir);
    }
})->with('dblayer_drivers');

it('caps telemetry and profiler buffers to configured limits', function (string $driver): void {
    $connectionName = 'regression_buffer_caps_' . $driver;
    dblayerAddConnectionForDriver($driver, $connectionName);
    $schemaDriver = dblayerConnectionDriver($connectionName);
    $table = dblayerTable('buffer_items');

    DB::statement(
        sprintf('create table %s (%s, name %s)', $table, dblayerAutoIncrementPrimaryKey($schemaDriver), dblayerStringType($schemaDriver)),
        [],
        $connectionName,
    );
    DB::table($table, $connectionName)->insert(['name' => 'probe']);

    DB::setTelemetryBufferLimits(2, 2);
    DB::setProfilerMaxProfiles(2);
    DB::enableTelemetry();
    DB::enableProfiler();

    DB::table($table, $connectionName)->select('name')->limit(1)->get();
    DB::table($table, $connectionName)->select('name')->limit(1)->get();
    DB::table($table, $connectionName)->select('name')->limit(1)->get();

    $snapshot = DB::telemetry();
    expect((int) ($snapshot['summary']['query_count'] ?? 0))->toBe(2);
    expect(DB::profiler()->profiles())->toHaveCount(2);

    DB::disableTelemetry();
    DB::disableProfiler();
})->with('dblayer_drivers');

it('supports resource bindings via bindParam for LOB values', function (string $driver): void {
    $connectionName = 'regression_lob_bindings_' . $driver;
    dblayerAddConnectionForDriver($driver, $connectionName);
    $schemaDriver = dblayerConnectionDriver($connectionName);
    $table = dblayerTable('lob_items');

    $payloadType = match ($schemaDriver) {
        'mysql' => 'blob',
        'pgsql' => 'bytea',
        default => 'blob',
    };

    DB::statement(
        sprintf('create table %s (%s, payload %s)', $table, dblayerAutoIncrementPrimaryKey($schemaDriver), $payloadType),
        [],
        $connectionName,
    );

    $stream = fopen('php://temp', 'rb+');
    expect($stream)->not->toBeFalse();
    fwrite($stream, 'blob-payload');
    rewind($stream);

    DB::statement(
        sprintf('insert into %s (payload) values (?)', $table),
        [$stream],
        $connectionName,
    );

    $row = DB::selectOne(sprintf('select payload from %s', $table), [], $connectionName);
    $payload = $row['payload'] ?? null;

    if (is_resource($payload)) {
        $payload = stream_get_contents($payload);
    }

    if (is_string($payload) && str_starts_with($payload, '\\x')) {
        $decoded = hex2bin(substr($payload, 2));
        $payload = $decoded === false ? $payload : $decoded;
    }

    expect($payload)->toBe('blob-payload');

    fclose($stream);
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

it('does not duplicate facade query tracking when lifecycle events are enabled', function (): void {
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ], 'regression_no_duplicate_facade_tracking');

    $records = new \ArrayObject();
    $psrLogger = new class($records) extends AbstractLogger {
        public function __construct(private \ArrayObject $records) {}

        public function log($level, \Stringable|string $message, array $context = []): void
        {
            $this->records->append([
                'level' => (string) $level,
                'message' => (string) $message,
                'context' => $context,
            ]);
        }
    };

    DB::enableQueryLog();
    DB::enableProfiler();
    DB::enableLogger(null, $psrLogger);
    DB::enableTelemetry();
    $listenerCalls = 0;
    DB::listen(static function () use (&$listenerCalls): void {
        $listenerCalls++;
    });

    DB::select('select 1', [], 'regression_no_duplicate_facade_tracking');

    expect(DB::getQueryLog())->toHaveCount(1);
    expect(DB::profiler()->profiles())->toHaveCount(1);
    expect($records->count())->toBe(1);
    expect((int) (DB::telemetry()['summary']['query_count'] ?? 0))->toBe(1);
    expect($listenerCalls)->toBe(1);

    DB::disableTelemetry();
    DB::disableLogger();
    DB::disableProfiler();
    DB::disableQueryLog();
});

it('emits explicit query lifecycle events for success and failure paths', function (): void {
    Events::forgetAll();

    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ], 'regression_query_lifecycle_events');

    $sequence = [];
    $failedEvents = [];

    Events::listen('db.query.executing', static function () use (&$sequence): void {
        $sequence[] = 'executing';
    });
    Events::listen('db.query.executed', static function () use (&$sequence): void {
        $sequence[] = 'executed';
    });
    Events::listen('db.query.failed', static function (QueryFailed $event) use (&$sequence, &$failedEvents): void {
        $sequence[] = 'failed';
        $failedEvents[] = $event;
    });

    DB::select('select 1', [], 'regression_query_lifecycle_events');

    expect(static fn(): array => DB::select('select * from missing_table', [], 'regression_query_lifecycle_events'))
        ->toThrow(ConnectionException::class);

    expect($sequence)->toBe(['executing', 'executed', 'executing', 'failed']);
    expect($failedEvents)->toHaveCount(1);
    expect($failedEvents[0]->statement)->toBe('SELECT');
    expect($failedEvents[0]->attempts)->toBe(1);
});

it('emits one final success event after retry recovery and no duplicate success on exhaustion', function (): void {
    Events::forgetAll();

    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ], 'regression_retry_lifecycle_events');

    $connection = DB::connection('regression_retry_lifecycle_events');
    $table = dblayerTable('retry_events_items');

    $executed = 0;
    $failed = [];
    $executing = 0;

    Events::listen('db.query.executing', static function () use (&$executing): void {
        $executing++;
    });
    Events::listen('db.query.executed', static function () use (&$executed): void {
        $executed++;
    });
    Events::listen('db.query.failed', static function (QueryFailed $event) use (&$failed): void {
        $failed[] = $event;
    });

    $connection->withQueryRetryPolicy(
        static function (\Throwable $error, int $attempt) use ($connection, $table): bool {
            unset($error);

            if ($attempt !== 1) {
                return false;
            }

            $connection->getPdo()->exec(sprintf('create table %s (id integer)', $table));

            return true;
        },
        static function () use ($connection, $table): void {
            $connection->statement(sprintf('insert into %s (id) values (1)', $table));
        },
    );

    expect($executing)->toBe(1);
    expect($executed)->toBe(1);
    expect($failed)->toHaveCount(0);

    $executed = 0;
    $failed = [];
    $executing = 0;

    expect(static function () use ($connection): void {
        $connection->withQueryRetryPolicy(
            static fn(): bool => true,
            static function () use ($connection): void {
                $connection->statement('select * from');
            },
        );
    })->toThrow(ConnectionException::class);

    expect($executing)->toBe(1);
    expect($executed)->toBe(0);
    expect($failed)->toHaveCount(1);
    expect($failed[0]->attempts)->toBe(5);
});

it('suppresses query lifecycle events inside withoutQueryEvents wrapper', function (): void {
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ], 'regression_without_query_events');

    $connection = DB::connection('regression_without_query_events');
    $sequence = [];

    $onExecuting = static function () use (&$sequence): void {
        $sequence[] = 'executing';
    };
    $onExecuted = static function () use (&$sequence): void {
        $sequence[] = 'executed';
    };
    $onFailed = static function () use (&$sequence): void {
        $sequence[] = 'failed';
    };

    Events::listen('db.query.executing', $onExecuting);
    Events::listen('db.query.executed', $onExecuted);
    Events::listen('db.query.failed', $onFailed);

    $connection->withoutQueryEvents(static function () use ($connection): void {
        $connection->select('select 1');
    });

    expect($sequence)->toBe([]);

    Events::forget('db.query.executing', $onExecuting);
    Events::forget('db.query.executed', $onExecuted);
    Events::forget('db.query.failed', $onFailed);
});

it('does not flag legitimate unions and still catches injected union payloads', function (): void {
    $validator = new QueryValidator();

    expect(fn() => $validator->detectSqlInjection('select id from t1 union select id from t2'))
        ->not->toThrow(SecurityException::class);

    expect(fn() => $validator->detectSqlInjection(
        'select * from users where name = "x" or 1=1 union select password from admins',
    ))->toThrow(SecurityException::class);
});

it('blocks turning global security mode off unless explicitly allowed', function (): void {
    Security::allowInsecureMode(false);

    expect(static fn() => Security::setMode(SecurityMode::OFF))
        ->toThrow(SecurityException::class);

    Security::allowInsecureMode(true);
    Security::setMode(SecurityMode::OFF);
    expect(Security::getMode())->toBe(SecurityMode::OFF);

    Security::setMode(SecurityMode::NORMAL);
    Security::allowInsecureMode(false);
});

it('enforces strict identifier policy by default for safe builder APIs', function (string $driver): void {
    $connectionName = 'regression_strict_identifiers_' . $driver;
    dblayerAddConnectionForDriver($driver, $connectionName);

    expect(static function () use ($connectionName): void {
        DB::table('users', $connectionName)->select('id as injected');
    })->toThrow(QueryException::class);
})->with('dblayer_drivers');

it('blocks raw SQL fragments when deny policy is enabled', function (string $driver): void {
    $connectionName = 'regression_raw_policy_deny_' . $driver;
    dblayerAddConnectionForDriver($driver, $connectionName, [
        'security' => [
            'raw_sql_policy' => 'deny',
        ],
    ]);

    expect(static function () use ($connectionName): void {
        DB::table('users', $connectionName)->whereRaw('id = 1');
    })->toThrow(QueryException::class);
})->with('dblayer_drivers');

it('supports allowlist raw SQL policy for explicit fragments only', function (string $driver): void {
    $connectionName = 'regression_raw_policy_allowlist_' . $driver;
    dblayerAddConnectionForDriver($driver, $connectionName, [
        'security' => [
            'raw_sql_policy' => 'allowlist',
            'raw_sql_allowlist' => [
                '/^id\\s*=\\s*\\?$/i',
                'count(*)',
            ],
        ],
    ]);

    expect(static function () use ($connectionName): void {
        DB::table('users', $connectionName)->whereRaw('id = ?', [1]);
        DB::table('users', $connectionName)->selectRaw('count(*) as c');
    })->not->toThrow(QueryException::class);

    expect(static function () use ($connectionName): void {
        DB::table('users', $connectionName)->whereRaw('name = ?', ['alice']);
    })->toThrow(QueryException::class);
})->with('dblayer_drivers');

it('rejects raw SQL allowlist bypass payloads that mutate a safe fragment', function (string $driver): void {
    $connectionName = 'regression_raw_policy_allowlist_mutation_' . $driver;
    dblayerAddConnectionForDriver($driver, $connectionName, [
        'security' => [
            'raw_sql_policy' => 'allowlist',
            'raw_sql_allowlist' => [
                '/^id\\s*=\\s*\\?$/i',
            ],
        ],
    ]);

    expect(static function () use ($connectionName): void {
        DB::table('users', $connectionName)->whereRaw('id = ?/**/or/**/1=1', [1]);
    })->toThrow(QueryException::class);

    expect(static function () use ($connectionName): void {
        DB::table('users', $connectionName)->whereRaw('id = ? --', [1]);
    })->toThrow(QueryException::class);
})->with('dblayer_drivers');

it('rejects malicious strict-identifier payloads in table and column selectors', function (string $driver): void {
    $connectionName = 'regression_strict_identifiers_mutation_' . $driver;
    dblayerAddConnectionForDriver($driver, $connectionName);

    expect(static function () use ($connectionName): void {
        DB::table('users;drop_table', $connectionName)->select('*');
    })->toThrow(QueryException::class);

    expect(static function () use ($connectionName): void {
        DB::table('users', $connectionName)->select("name\nfrom users");
    })->toThrow(QueryException::class);
})->with('dblayer_drivers');

it('blocks binding values exceeding configured byte-size limits', function (): void {
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'security' => [
            'max_param_bytes' => 8,
        ],
    ], 'regression_security_binding_size_limit');

    expect(static function (): array {
        return DB::select('select ?', ['123456789'], 'regression_security_binding_size_limit');
    })->toThrow(SecurityException::class);
});

it('blocks queries exceeding configured parameter-count limits', function (): void {
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'security' => [
            'max_params' => 2,
        ],
    ], 'regression_security_param_count_limit');

    expect(static function (): array {
        return DB::select('select ?, ?, ?', [1, 2, 3], 'regression_security_param_count_limit');
    })->toThrow(SecurityException::class);
});

it('supports external distributed rate limit callback configuration', function (string $driver): void {
    $connectionName = 'regression_distributed_rate_limit_' . $driver;

    dblayerAddConnectionForDriver($driver, $connectionName, [
        'security' => [
            'queries_per_second' => 1,
            'queries_per_minute' => 0,
            'rate_limit_key' => 'distributed:' . $driver,
            'rate_limit_callback' => static function (string $identifier, array $limits): bool {
                static $attempts = 0;
                $attempts++;

                expect($identifier)->toContain('distributed:');
                expect($limits['queries_per_second'])->toBe(1);

                return $attempts < 2;
            },
        ],
    ]);

    DB::select('select 1', [], $connectionName);

    expect(static fn(): array => DB::select('select 1', [], $connectionName))
        ->toThrow(SecurityException::class);
})->with('dblayer_drivers');

it('enforces rate limits across mutated query text for the same limiter key', function (string $driver): void {
    $connectionName = 'regression_rate_limit_mutation_' . $driver;
    $rateKey = 'mutation-rate:' . bin2hex(random_bytes(8));

    dblayerAddConnectionForDriver($driver, $connectionName, [
        'security' => [
            'queries_per_second' => 0,
            'queries_per_minute' => 1,
            'rate_limit_key' => $rateKey,
        ],
    ]);

    DB::select('select 1', [], $connectionName);

    expect(static function () use ($connectionName): array {
        return DB::select('select 2 /* mutated */', [], $connectionName);
    })->toThrow(SecurityException::class);

    Security::resetRateLimit($rateKey);
})->with('dblayer_drivers');

it('requires TLS policy when explicitly requested for mysql and pgsql configs', function (): void {
    expect(static fn(): ConnectionConfig => ConnectionConfig::fromArray([
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'app',
        'username' => 'root',
        'password' => '',
        'security' => [
            'require_tls' => true,
        ],
    ]))->toThrow(ConnectionException::class);

    expect(static fn(): ConnectionConfig => ConnectionConfig::fromArray([
        'driver' => 'pgsql',
        'host' => '127.0.0.1',
        'port' => 5432,
        'database' => 'app',
        'username' => 'postgres',
        'password' => '',
        'security' => [
            'require_tls' => true,
        ],
    ]))->toThrow(ConnectionException::class);
});

it('blocks security.enabled=false unless allow_insecure is enabled', function (): void {
    expect(static fn(): ConnectionConfig => ConnectionConfig::fromArray([
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'app',
        'username' => 'root',
        'password' => '',
        'security' => [
            'enabled' => false,
        ],
    ]))->toThrow(ConnectionException::class);

    expect(static fn(): ConnectionConfig => ConnectionConfig::fromArray([
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'app',
        'username' => 'root',
        'password' => '',
        'security' => [
            'enabled' => false,
            'allow_insecure' => true,
        ],
    ]))->not->toThrow(ConnectionException::class);
});

it('validates raw SQL policy configuration values', function (): void {
    expect(static fn(): ConnectionConfig => ConnectionConfig::fromArray([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'security' => [
            'raw_sql_policy' => 'invalid',
        ],
    ]))->toThrow(ConnectionException::class);

    expect(static fn(): ConnectionConfig => ConnectionConfig::fromArray([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'security' => [
            'raw_sql_policy' => 'allowlist',
            'raw_sql_allowlist' => [],
        ],
    ]))->toThrow(ConnectionException::class);
});

it('applies facade security policy to existing and future connections', function (string $driver): void {
    $existingConnection = 'regression_security_defaults_existing_' . $driver;
    $futureConnection = 'regression_security_defaults_future_' . $driver;

    dblayerAddConnectionForDriver($driver, $existingConnection);

    DB::setSecurityDefaults(['queries_per_second' => 1]);

    DB::select('select 1', [], $existingConnection);

    expect(static fn(): array => DB::select('select 1', [], $existingConnection))
        ->toThrow(SecurityException::class);

    Security::clearAllRateLimits();

    dblayerAddConnectionForDriver($driver, $futureConnection, [
        'security' => [
            'queries_per_second' => 0,
        ],
    ]);

    DB::select('select 1', [], $futureConnection);
    expect(static fn(): array => DB::select('select 1', [], $futureConnection))
        ->toThrow(SecurityException::class);
})->with('dblayer_drivers');

it('enables hardened defaults through facade helper', function (): void {
    DB::hardenProduction();

    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ], 'hardened_sqlite');

    $security = DB::connection('hardened_sqlite')->getConfig()->securityConfig();

    expect($security['enabled'] ?? null)->toBeTrue();
    expect($security['strict_identifiers'] ?? null)->toBeTrue();
    expect($security['require_tls'] ?? null)->toBeTrue();
});
