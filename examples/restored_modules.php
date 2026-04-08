<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Query\QueryBuilder;

require __DIR__ . '/../vendor/autoload.php';

DB::purge();

DB::addConnection([
    'driver' => 'sqlite',
    'database' => ':memory:',
], 'default');

$cacheDir = '/tmp/dblayer-example-cache-' . bin2hex(random_bytes(6));
$cache = DB::useFileCache($cacheDir);
$cache->put('hello', 'world', 60);
echo 'Cache value: ' . (string) $cache->get('hello') . PHP_EOL;

DB::poolManager([
    'max_connections' => 2,
    'idle_timeout' => 60,
    'max_lifetime' => 3_600,
    'health_check_interval' => 1,
]);

DB::withPooledConnection(static function (Connection $connection): void {
    $rows = $connection->select('select 1 as ok');
    echo 'Pooled query result: ' . (string) ($rows[0]['ok'] ?? '0') . PHP_EOL;
});

$logFile = '/tmp/dblayer-example-log-' . bin2hex(random_bytes(6)) . '.log';
DB::enableLogger($logFile);
DB::enableProfiler();

DB::statement(
    'create table user_profiles (
        id integer primary key autoincrement,
        name text,
        active integer
    )',
);

DB::table('user_profiles')->insert([
    ['name' => 'Alice', 'active' => 1],
    ['name' => 'Bob', 'active' => 0],
]);

$repository = DB::repository('UserProfiles');
echo 'Repository count: ' . (string) $repository->all()->count() . PHP_EOL;
echo 'Active count: ' . (string) $repository->count(static function (QueryBuilder $query): void {
    $query->where('active', '=', 1);
}) . PHP_EOL;

DB::transaction(static function (Connection $connection): void {
    $connection->table('user_profiles')->insert(['name' => 'Committed', 'active' => 1]);
});

try {
    DB::transaction(static function (Connection $connection): void {
        $connection->table('user_profiles')->insert(['name' => 'RolledBack', 'active' => 0]);
        throw new RuntimeException('rollback demo');
    });
} catch (Throwable) {
    // Expected rollback path for demo.
}

$stats = DB::transactionStats();
echo 'Transactions committed: ' . (string) ($stats['committed'] ?? 0) . PHP_EOL;
echo 'Transactions rolled back: ' . (string) ($stats['rolled_back'] ?? 0) . PHP_EOL;
echo 'Profiled queries: ' . (string) count(DB::profiler()->profiles()) . PHP_EOL;

DB::disableLogger();
DB::disableProfiler();

@unlink($logFile);
$cache->flush();
@rmdir($cacheDir);

DB::purge();
