<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Events\Events;
use Infocyph\DBLayer\Exceptions\SecurityException;
use Infocyph\DBLayer\Query\QueryBuilder;
use Infocyph\DBLayer\Security\QueryValidator;

it('matches bootstrap example connection setup flow', function (string $driver): void {
    dblayerAddConnectionForDriver($driver, 'primary', [
        'security' => [
            'enabled' => true,
            'max_sql_length' => 8000,
            'max_params' => 500,
            'max_param_bytes' => 4096,
        ],
    ]);
    dblayerAddConnectionForDriver($driver, 'reporting');

    DB::setDefaultConnection('primary');
    $primaryDriver = dblayerConnectionDriver('primary');
    $reportingDriver = dblayerConnectionDriver('reporting');

    expect(DB::getDefaultConnection())->toBe('primary');
    expect(DB::connection()->getDriverName())->toBe($primaryDriver);
    expect(DB::connection('reporting')->getDriverName())->toBe($reportingDriver);
    expect($primaryDriver)->toBe($reportingDriver);
})->with('dblayer_drivers');

it('matches chunking example flow', function (string $driver): void {
    dblayerAddConnectionForDriver($driver);
    $schemaDriver = dblayerConnectionDriver();
    $auditTable = dblayerTable('audit_logs');

    DB::statement(sprintf(
        'create table %s (
            %s,
            action %s,
            created_at %s
        )',
        $auditTable,
        dblayerAutoIncrementPrimaryKey($schemaDriver),
        dblayerStringType($schemaDriver),
        dblayerDateTimeType($schemaDriver),
    ));

    $today = date('Y-m-d H:i:s');

    foreach (range(1, 5) as $index) {
        DB::table($auditTable)->insert([
            'action' => 'event-' . $index,
            'created_at' => $today,
        ]);
    }

    $pages = [];
    DB::table($auditTable)
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
    DB::table($auditTable)
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
})->with('dblayer_drivers');

it('matches filtering example flow', function (string $driver): void {
    dblayerAddConnectionForDriver($driver);
    $schemaDriver = dblayerConnectionDriver();
    $usersTable = dblayerTable('users');

    DB::statement(sprintf(
        'create table %s (
            %s,
            name %s,
            email %s,
            role %s,
            active integer,
            deleted_at %s
        )',
        $usersTable,
        dblayerAutoIncrementPrimaryKey($schemaDriver),
        dblayerStringType($schemaDriver),
        dblayerStringType($schemaDriver),
        dblayerStringType($schemaDriver),
        dblayerDateTimeType($schemaDriver),
    ));

    DB::table($usersTable)->insert([
        ['name' => 'alice', 'email' => 'alice@example.com', 'role' => 'admin', 'active' => 1, 'deleted_at' => null],
        ['name' => 'alina', 'email' => 'alina@example.com', 'role' => 'admin', 'active' => 0, 'deleted_at' => null],
        ['name' => 'bob', 'email' => 'bob@example.com', 'role' => 'editor', 'active' => 1, 'deleted_at' => null],
        ['name' => 'alicia', 'email' => 'alicia@example.com', 'role' => 'admin', 'active' => 1, 'deleted_at' => '2024-01-01 00:00:00'],
    ]);

    $filters = [
        'search' => 'ali',
        'activeOnly' => true,
        'role' => 'admin',
    ];

    $query = DB::table($usersTable)
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
})->with('dblayer_drivers');

it('matches multi connections example flow', function (string $driver): void {
    dblayerAddConnectionForDriver($driver, 'main');
    dblayerAddConnectionForDriver($driver, 'reporting');
    $mainDriver = dblayerConnectionDriver('main');
    $reportingDriver = dblayerConnectionDriver('reporting');
    $usersTable = dblayerTable('users');
    $eventsTable = dblayerTable('user_events');

    DB::statement(sprintf(
        'create table %s (%s, email %s)',
        $usersTable,
        dblayerAutoIncrementPrimaryKey($mainDriver),
        dblayerStringType($mainDriver),
    ), [], 'main');

    DB::statement(sprintf(
        'create table %s (
            %s,
            user_id integer,
            event %s,
            occurred_at %s
        )',
        $eventsTable,
        dblayerAutoIncrementPrimaryKey($reportingDriver),
        dblayerStringType($reportingDriver),
        dblayerDateTimeType($reportingDriver),
    ), [], 'reporting');

    DB::table($usersTable, 'main')->insert([
        'id' => 1,
        'email' => 'hasan@example.com',
    ]);

    DB::table($eventsTable, 'reporting')->insert([
        ['id' => 1, 'user_id' => 1, 'event' => 'signup', 'occurred_at' => '2025-01-01 00:00:00'],
        ['id' => 2, 'user_id' => 1, 'event' => 'login', 'occurred_at' => '2025-01-02 00:00:00'],
    ]);

    /** @var Connection $main */
    $main = DB::connection('main');

    $user = $main->table($usersTable)
        ->where('email', '=', 'hasan@example.com')
        ->first();

    expect($user)->not->toBeNull();

    $events = DB::table($eventsTable, 'reporting')
        ->where('user_id', '=', $user['id'])
        ->orderBy('occurred_at', 'desc')
        ->limit(50)
        ->get();

    expect(array_column($events, 'event'))->toBe(['login', 'signup']);
})->with('dblayer_drivers');

it('matches transactions example flow', function (string $driver): void {
    dblayerAddConnectionForDriver($driver, 'main');
    DB::setDefaultConnection('main');
    $schemaDriver = dblayerConnectionDriver('main');
    $ordersTable = dblayerTable('orders');
    $orderItemsTable = dblayerTable('order_items');
    $usersTable = dblayerTable('users');
    $walletsTable = dblayerTable('wallets');
    $walletMovementsTable = dblayerTable('wallet_movements');

    DB::statement(sprintf(
        'create table %s (
            %s,
            user_id integer,
            status %s,
            total integer,
            created_at %s
        )',
        $ordersTable,
        dblayerAutoIncrementPrimaryKey($schemaDriver),
        dblayerStringType($schemaDriver),
        dblayerDateTimeType($schemaDriver),
    ));
    DB::statement(sprintf(
        'create table %s (
            %s,
            order_id integer,
            sku %s,
            qty integer,
            unit_price integer
        )',
        $orderItemsTable,
        dblayerAutoIncrementPrimaryKey($schemaDriver),
        dblayerStringType($schemaDriver),
    ));
    DB::statement(sprintf(
        'create table %s (
            id integer primary key,
            last_order_at %s
        )',
        $usersTable,
        dblayerDateTimeType($schemaDriver),
    ));
    DB::statement(sprintf(
        'create table %s (
            user_id integer primary key,
            balance integer
        )',
        $walletsTable,
    ));
    DB::statement(sprintf(
        'create table %s (
            %s,
            user_id integer,
            amount integer,
            reason %s,
            created_at %s
        )',
        $walletMovementsTable,
        dblayerAutoIncrementPrimaryKey($schemaDriver),
        dblayerStringType($schemaDriver),
        dblayerDateTimeType($schemaDriver),
    ));

    DB::table($usersTable)->insert([
        'id' => 42,
        'last_order_at' => null,
    ]);
    DB::table($walletsTable)->insert([
        'user_id' => 42,
        'balance' => 5000,
    ]);

    $orderId = DB::transaction(
        static function (Connection $conn) use ($ordersTable, $orderItemsTable, $usersTable): string {
            $orderId = $conn->table($ordersTable)->insertGetId([
                'user_id' => 42,
                'status' => 'pending',
                'total' => 1999,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            $conn->table($orderItemsTable)->insert([
                'order_id' => $orderId,
                'sku' => 'SKU-123',
                'qty' => 1,
                'unit_price' => 1999,
            ]);

            $conn->table($usersTable)
                ->where('id', '=', 42)
                ->update(['last_order_at' => date('Y-m-d H:i:s')]);

            return $orderId;
        },
    );

    expect((int) $orderId)->toBeGreaterThan(0);
    expect((int) DB::table($orderItemsTable)->count())->toBe(1);

    $main = DB::connection('main');

    $walletResult = $main->transaction(
        static function (Connection $conn) use ($walletsTable, $walletMovementsTable): bool {
            $currentBalance = (int) $conn->table($walletsTable)
                ->where('user_id', '=', 42)
                ->value('balance');

            $conn->table($walletsTable)
                ->where('user_id', '=', 42)
                ->update(['balance' => $currentBalance - 1999]);

            $conn->table($walletMovementsTable)->insert([
                'user_id' => 42,
                'amount' => -1999,
                'reason' => 'order_payment',
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            return true;
        },
    );

    expect($walletResult)->toBeTrue();
    expect((int) DB::table($walletsTable)->where('user_id', '=', 42)->value('balance'))->toBe(3001);
    expect((int) DB::table($walletMovementsTable)->count())->toBe(1);
})->with('dblayer_drivers');

it('matches read replicas example flow', function (string $driver): void {
    $baseConfig = dblayerRequireDriver($driver);

    if ($driver === 'sqlite') {
        $connectionConfig = [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'read_strategy' => 'round_robin',
            'read' => [
                ['database' => ':memory:'],
                ['database' => ':memory:'],
            ],
        ];
    } else {
        $replicaA = $baseConfig;
        $replicaB = $baseConfig;

        $connectionConfig = $baseConfig;
        $connectionConfig['read_strategy'] = 'round_robin';
        $connectionConfig['read'] = [$replicaA, $replicaB];
    }

    DB::addConnection($connectionConfig, 'replicas');

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
})->with('dblayer_drivers');

it('matches helpers and security example flow', function (string $driver): void {
    dblayerAddConnectionForDriver($driver);

    $rows = DB::select('select 1 as ok');
    expect((int) ($rows[0]['ok'] ?? 0))->toBe(1);

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
})->with('dblayer_drivers');

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
        'read_replicas.php' => [
            'ExamplesParityIntegrationTest.php',
            'ReplicaStrategiesIntegrationTest.php',
            'PoolStickyReplicaStressIntegrationTest.php',
        ],
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
