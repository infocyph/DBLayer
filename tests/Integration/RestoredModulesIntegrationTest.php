<?php

declare(strict_types=1);

use Infocyph\DBLayer\Cache\Strategies\FileStrategy;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Query\QueryBuilder;

it('uses file cache strategy through DB facade', function (string $driver): void {
    dblayerAddConnectionForDriver($driver);

    $cacheDir = '/tmp/dblayer-file-cache-' . bin2hex(random_bytes(6));

    $cache = DB::useFileCache($cacheDir);
    $stored = $cache->put('greeting', 'hello', 30);

    expect($stored)->toBeTrue();
    expect($cache->get('greeting'))->toBe('hello');
    expect($cache->getStrategy())->toBeInstanceOf(FileStrategy::class);
    expect(is_dir($cacheDir))->toBeTrue();

    $cache->flush();
    @rmdir($cacheDir);
})->with('dblayer_drivers');

it('reuses pooled connections via pool manager helpers', function (string $driver): void {
    dblayerAddConnectionForDriver($driver, 'pooled');

    $poolManager = DB::poolManager([
        'max_connections' => 2,
        'idle_timeout' => 60,
        'max_lifetime' => 3_600,
        'health_check_interval' => 1,
    ]);

    $firstId = DB::withPooledConnection(
        static function (Connection $connection): int {
            $rows = $connection->select('select 1 as ok');

            expect($rows[0]['ok'])->toBe(1);

            return spl_object_id($connection);
        },
        'pooled',
    );

    $secondId = DB::withPooledConnection(
        static fn(Connection $connection): int => spl_object_id($connection),
        'pooled',
    );

    $stats = $poolManager->getPool()->getStats();

    expect($firstId)->toBe($secondId);
    expect($stats['created'])->toBeGreaterThanOrEqual(1);
    expect($stats['reused'])->toBeGreaterThanOrEqual(1);
})->with('dblayer_drivers');

it('records query logs and profiles through logger and profiler services', function (string $driver): void {
    dblayerAddConnectionForDriver($driver);
    $schemaDriver = dblayerConnectionDriver();
    $table = dblayerTable('observability_items');

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

    DB::table($table)->insert([
        'name' => 'alpha',
    ]);

    $logFile = '/tmp/dblayer-log-' . bin2hex(random_bytes(6)) . '.log';

    DB::enableLogger($logFile);
    DB::enableProfiler();

    DB::table($table)->select('name')->get();

    $profiles = DB::profiler()->profiles();
    $logContents = is_file($logFile) ? (string) file_get_contents($logFile) : '';

    expect($profiles)->toHaveCount(1);
    expect(strtolower((string) $profiles[0]['sql']))->toContain('select');
    expect($logContents)->toContain('QUERY');
    expect($logContents)->toContain('"sql"');

    DB::disableLogger();
    DB::disableProfiler();
    @unlink($logFile);
})->with('dblayer_drivers');

it('builds table repositories with normalized names and result processing', function (string $driver): void {
    dblayerAddConnectionForDriver($driver);
    $schemaDriver = dblayerConnectionDriver();
    $table = dblayerTable('user_profiles');

    DB::statement(
        sprintf(
            'create table %s (
            %s,
            name %s,
            active integer
        )',
            $table,
            dblayerAutoIncrementPrimaryKey($schemaDriver),
            dblayerStringType($schemaDriver),
        ),
    );

    DB::table($table)->insert([
        ['name' => 'Alice', 'active' => 1],
        ['name' => 'Bob', 'active' => 0],
    ]);

    $repository = DB::repository($table);

    expect($repository->all()->count())->toBe(2);
    expect($repository->find(1)['name'] ?? null)->toBe('Alice');
    expect($repository->pluck('name'))->toBe(['Alice', 'Bob']);
    expect($repository->pluck('name', 'id'))->toBe([1 => 'Alice', 2 => 'Bob']);
    expect($repository->value('name', static function (QueryBuilder $query): void {
        $query->where('id', '=', 2);
    }))->toBe('Bob');
})->with('dblayer_drivers');

it('tracks transaction statistics through transaction manager wiring', function (string $driver): void {
    dblayerAddConnectionForDriver($driver);
    $schemaDriver = dblayerConnectionDriver();
    $table = dblayerTable('tx_items');

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

    DB::transaction(static function (Connection $connection) use ($table): void {
        $connection->table($table)->insert(['name' => 'committed']);
    });

    try {
        DB::transaction(static function (Connection $connection) use ($table): void {
            $connection->table($table)->insert(['name' => 'rolled-back']);
            throw new RuntimeException('rollback');
        });
    } catch (Throwable) {
        // Expected rollback path.
    }

    $stats = DB::transactionStats();

    expect($stats)->toBeArray();
    expect($stats['total'] ?? 0)->toBeGreaterThanOrEqual(2);
    expect($stats['committed'] ?? 0)->toBeGreaterThanOrEqual(1);
    expect($stats['rolled_back'] ?? 0)->toBeGreaterThanOrEqual(1);
    expect((int) DB::table($table)->count())->toBe(1);
})->with('dblayer_drivers');
