<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Events\DatabaseEvents\QueryExecuted;
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
        expect(@unlink($databaseFile))->toBeTrue();
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
        @unlink($logFile);
    }
})->with('dblayer_drivers');

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
        @unlink($logFile);
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

    if ((! is_dir($baseDir)) && (! @mkdir($baseDir, 0o700, true))) {
        test()->markTestSkipped('Unable to create temporary directory for symlink logger test.');

        return;
    }

    file_put_contents($realLog, '');

    if (! function_exists('symlink') || ! @symlink($realLog, $linkLog)) {
        @unlink($realLog);
        @rmdir($baseDir);
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
        @unlink($linkLog);
    }
    if (is_file($realLog)) {
        @unlink($realLog);
    }
    @rmdir($baseDir);
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
