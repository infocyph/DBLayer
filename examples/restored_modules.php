<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Query\QueryBuilder;

require __DIR__ . '/../vendor/autoload.php';

$writeLine = static function (string $message): void {
    fwrite(STDOUT, $message . PHP_EOL);
};

DB::purge();

DB::addConnection([
    'driver' => 'sqlite',
    'database' => ':memory:',
], 'default');

$removeDirectory = static function (string $directory): void {
    if (!is_dir($directory)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $entry) {
        $path = $entry->getPathname();
        if ($entry->isDir()) {
            if (is_dir($path)) {
                rmdir($path);
            }

            continue;
        }

        if (is_file($path)) {
            unlink($path);
        }
    }

    if (is_dir($directory)) {
        rmdir($directory);
    }
};

$cacheDir = '/tmp/dblayer-example-cache-' . bin2hex(random_bytes(6));
$cache = DB::useFileCache($cacheDir);
$cache->set('hello', 'world', 60);
$writeLine('Cache value: ' . (string) $cache->get('hello'));

DB::poolManager([
    'max_connections' => 2,
    'idle_timeout' => 60,
    'max_lifetime' => 3_600,
    'health_check_interval' => 1,
]);

DB::withPooledConnection(static function (Connection $connection): void {
    $rows = $connection->select('select 1 as ok');
    fwrite(STDOUT, 'Pooled query result: ' . (string) ($rows[0]['ok'] ?? '0') . PHP_EOL);
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
$writeLine('Repository count: ' . (string) $repository->all()->count());
$writeLine('Active count: ' . (string) $repository->count(static function (QueryBuilder $query): void {
    $query->where('active', '=', 1);
}));

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
$writeLine('Transactions committed: ' . (string) ($stats['committed'] ?? 0));
$writeLine('Transactions rolled back: ' . (string) ($stats['rolled_back'] ?? 0));
$writeLine('Profiled queries: ' . (string) count(DB::profiler()->profiles()));

DB::disableLogger();
DB::disableProfiler();

if (is_file($logFile)) {
    unlink($logFile);
}
$cache->clear();
$removeDirectory($cacheDir);

DB::purge();
