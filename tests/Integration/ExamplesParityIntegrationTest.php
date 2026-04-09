<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Events\Events;
use Infocyph\DBLayer\Exceptions\SecurityException;
use Infocyph\DBLayer\Query\QueryBuilder;
use Infocyph\DBLayer\Security\QueryValidator;

it('matches bootstrap example connection setup flow', function (): void {
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'security' => [
            'enabled' => true,
            'max_sql_length' => 8000,
            'max_params' => 500,
            'max_param_bytes' => 4096,
        ],
    ], 'mysql_main');

    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ], 'pgsql_reporting');

    DB::setDefaultConnection('mysql_main');

    expect(DB::getDefaultConnection())->toBe('mysql_main');
    expect(DB::connection()->getDriverName())->toBe('sqlite');
    expect(DB::connection('pgsql_reporting')->getDriverName())->toBe('sqlite');
});

it('matches chunking example flow', function (): void {
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]);

    DB::statement(
        'create table audit_logs (
            id integer primary key autoincrement,
            action text,
            created_at text
        )',
    );

    $today = date('Y-m-d H:i:s');

    foreach (range(1, 5) as $index) {
        DB::table('audit_logs')->insert([
            'action' => 'event-' . $index,
            'created_at' => $today,
        ]);
    }

    $pages = [];
    DB::table('audit_logs')
        ->orderBy('id')
        ->chunk(2, static function (array $rows, int $page) use (&$pages): bool {
            $pages[$page] = array_column($rows, 'id');

            return true;
        });

    expect($pages)->toBe([
        1 => [1, 2],
        2 => [3, 4],
        3 => [5],
    ]);

    $chunkByIdPages = [];
    DB::table('audit_logs')
        ->where('created_at', '>=', date('Y-m-d 00:00:00'))
        ->chunkById(
            2,
            static function (array $rows, int $page) use (&$chunkByIdPages): bool {
                $chunkByIdPages[$page] = array_column($rows, 'id');

                return true;
            },
            'id',
        );

    expect($chunkByIdPages)->toBe([
        1 => [1, 2],
        2 => [3, 4],
        3 => [5],
    ]);
});

it('matches filtering example flow', function (): void {
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]);

    DB::statement(
        'create table users (
            id integer primary key autoincrement,
            name text,
            email text,
            role text,
            active integer,
            deleted_at text
        )',
    );

    DB::table('users')->insert([
        ['name' => 'Alice', 'email' => 'alice@example.com', 'role' => 'admin', 'active' => 1, 'deleted_at' => null],
        ['name' => 'Alina', 'email' => 'alina@example.com', 'role' => 'admin', 'active' => 0, 'deleted_at' => null],
        ['name' => 'Bob', 'email' => 'bob@example.com', 'role' => 'editor', 'active' => 1, 'deleted_at' => null],
        ['name' => 'Alicia', 'email' => 'alicia@example.com', 'role' => 'admin', 'active' => 1, 'deleted_at' => '2024-01-01 00:00:00'],
    ]);

    $filters = [
        'search' => 'ali',
        'activeOnly' => true,
        'role' => 'admin',
    ];

    $query = DB::table('users')
        ->select(['id', 'name', 'email', 'role', 'active'])
        ->whereNull('deleted_at');

    $query = $query->when(
        $filters['activeOnly'],
        static function (QueryBuilder $q): QueryBuilder {
            return $q->where('active', '=', 1);
        },
    );

    $query = $query->when(
        $filters['role'] !== null,
        static function (QueryBuilder $q) use ($filters): QueryBuilder {
            return $q->where('role', '=', $filters['role']);
        },
    );

    $query = $query->when(
        isset($filters['search']) && $filters['search'] !== '',
        static function (QueryBuilder $q) use ($filters): QueryBuilder {
            $term = '%' . $filters['search'] . '%';

            return $q->where(static function (QueryBuilder $inner) use ($term): QueryBuilder {
                return $inner
                    ->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term);
            });
        },
    );

    $rows = $query
        ->orderBy('id', 'desc')
        ->forPage(page: 1, perPage: 20)
        ->get();

    expect(array_column($rows, 'email'))->toBe(['alice@example.com']);
});

it('matches multi connections example flow', function (): void {
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ], 'mysql_main');

    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ], 'pgsql_reporting');

    DB::statement(
        'create table users (id integer primary key, email text)',
        [],
        'mysql_main',
    );

    DB::statement(
        'create table user_events (
            id integer primary key,
            user_id integer,
            event text,
            occurred_at text
        )',
        [],
        'pgsql_reporting',
    );

    DB::table('users', 'mysql_main')->insert([
        'id' => 1,
        'email' => 'hasan@example.com',
    ]);

    DB::table('user_events', 'pgsql_reporting')->insert([
        ['id' => 1, 'user_id' => 1, 'event' => 'signup', 'occurred_at' => '2025-01-01 00:00:00'],
        ['id' => 2, 'user_id' => 1, 'event' => 'login', 'occurred_at' => '2025-01-02 00:00:00'],
    ]);

    /** @var Connection $mysql */
    $mysql = DB::connection('mysql_main');

    $user = $mysql->table('users')
        ->where('email', '=', 'hasan@example.com')
        ->first();

    expect($user)->not->toBeNull();

    $events = DB::table('user_events', 'pgsql_reporting')
        ->where('user_id', '=', $user['id'])
        ->orderBy('occurred_at', 'desc')
        ->limit(50)
        ->get();

    expect(array_column($events, 'event'))->toBe(['login', 'signup']);
});

it('matches transactions example flow', function (): void {
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ], 'mysql_main');

    DB::setDefaultConnection('mysql_main');

    DB::statement(
        'create table orders (
            id integer primary key autoincrement,
            user_id integer,
            status text,
            total integer,
            created_at text
        )',
    );
    DB::statement(
        'create table order_items (
            id integer primary key autoincrement,
            order_id integer,
            sku text,
            qty integer,
            unit_price integer
        )',
    );
    DB::statement(
        'create table users (
            id integer primary key,
            last_order_at text
        )',
    );
    DB::statement(
        'create table wallets (
            user_id integer primary key,
            balance integer
        )',
    );
    DB::statement(
        'create table wallet_movements (
            id integer primary key autoincrement,
            user_id integer,
            amount integer,
            reason text,
            created_at text
        )',
    );

    DB::table('users')->insert([
        'id' => 42,
        'last_order_at' => null,
    ]);
    DB::table('wallets')->insert([
        'user_id' => 42,
        'balance' => 5000,
    ]);

    $orderId = DB::transaction(
        static function (Connection $conn): string {
            $orderId = $conn->table('orders')->insertGetId([
                'user_id' => 42,
                'status' => 'pending',
                'total' => 1999,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            $conn->table('order_items')->insert([
                'order_id' => $orderId,
                'sku' => 'SKU-123',
                'qty' => 1,
                'unit_price' => 1999,
            ]);

            $conn->table('users')
                ->where('id', '=', 42)
                ->update(['last_order_at' => date('Y-m-d H:i:s')]);

            return $orderId;
        },
    );

    expect((int) $orderId)->toBeGreaterThan(0);
    expect((int) DB::table('order_items')->count())->toBe(1);

    $mysql = DB::connection('mysql_main');

    $walletResult = $mysql->transaction(
        static function (Connection $conn): bool {
            $currentBalance = (int) $conn->table('wallets')
                ->where('user_id', '=', 42)
                ->value('balance');

            $conn->table('wallets')
                ->where('user_id', '=', 42)
                ->update(['balance' => $currentBalance - 1999]);

            $conn->table('wallet_movements')->insert([
                'user_id' => 42,
                'amount' => -1999,
                'reason' => 'order_payment',
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            return true;
        },
    );

    expect($walletResult)->toBeTrue();
    expect((int) DB::table('wallets')->where('user_id', '=', 42)->value('balance'))->toBe(3001);
    expect((int) DB::table('wallet_movements')->count())->toBe(1);
});

it('matches read replicas example flow', function (): void {
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'read_strategy' => 'round_robin',
        'read' => [
            ['database' => ':memory:'],
            ['database' => ':memory:'],
        ],
    ], 'replicas');

    $connection = DB::connection('replicas');

    $connection->select('select 1');
    $first = $connection->getReadReplicaInfo()['selected_index'] ?? null;

    $connection->reconnect(false);
    $connection->select('select 1');

    $info = $connection->getReadReplicaInfo();
    $second = $info['selected_index'] ?? null;

    expect($info['strategy'] ?? null)->toBe('round_robin');
    expect($first)->not->toBeNull();
    expect($second)->not->toBeNull();
});

it('matches helpers and security example flow', function (): void {
    Events::forgetAll();

    $payload = new stdClass();
    data_set($payload, 'profile.name', 'Alice');

    expect(data_get($payload, 'profile.name', 'unknown'))->toBe('Alice');

    $dispatched = 0;
    $listener = static function () use (&$dispatched): void {
        $dispatched++;
    };

    Events::listen('custom.event', $listener);
    Events::dispatch('custom.event');
    Events::forget('custom.event', $listener);
    Events::dispatch('custom.event');

    expect($dispatched)->toBe(1);

    $validator = new QueryValidator();

    expect(static function () use ($validator): void {
        $validator->detectSqlInjection(
            'select * from users where name = "x" or 1=1 union select password from admins',
        );
    })->toThrow(SecurityException::class);
});

it('keeps examples and integration coverage in sync', function (): void {
    $exampleFiles = glob(__DIR__ . '/../../examples/*.php') ?: [];
    $exampleNames = array_map(
        static fn(string $path): string => basename($path),
        $exampleFiles,
    );
    sort($exampleNames);

    $coverageMap = [
        'bootstrap.php' => ['ExamplesParityIntegrationTest.php'],
        'chunking.php' => ['ExamplesParityIntegrationTest.php'],
        'crud.php' => ['CrudIntegrationTest.php', 'AdvancedQueryFeaturesIntegrationTest.php'],
        'cross_driver_matrix.php' => ['CrossDriverMatrixIntegrationTest.php'],
        'filtering.php' => ['ExamplesParityIntegrationTest.php'],
        'helpers_and_security.php' => ['ExamplesParityIntegrationTest.php'],
        'locking.php' => ['LockingIntegrationTest.php'],
        'multi_connections.php' => ['ExamplesParityIntegrationTest.php'],
        'observability.php' => ['ObservabilityIntegrationTest.php'],
        'read_replicas.php' => ['ExamplesParityIntegrationTest.php', 'ReplicaStrategiesIntegrationTest.php'],
        'restored_modules.php' => ['RestoredModulesIntegrationTest.php'],
        'transactions.php' => ['TransactionIntegrationTest.php'],
    ];

    $mappedExamples = array_keys($coverageMap);
    sort($mappedExamples);

    expect($mappedExamples)->toBe($exampleNames);

    $integrationFiles = glob(__DIR__ . '/*.php') ?: [];
    $integrationNames = array_map(
        static fn(string $path): string => basename($path),
        $integrationFiles,
    );
    sort($integrationNames);

    $mappedTests = [];
    foreach ($coverageMap as $tests) {
        foreach ($tests as $testFile) {
            $mappedTests[$testFile] = true;
        }
    }

    $mappedTestNames = array_keys($mappedTests);
    sort($mappedTestNames);

    expect($mappedTestNames)->toBe($integrationNames);
});
