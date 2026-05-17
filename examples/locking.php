<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;

require __DIR__ . '/../vendor/autoload.php';

$writeLine = static function (string $message): void {
    fwrite(STDOUT, $message . PHP_EOL);
};

DB::purge();

// SQLite
DB::addConnection([
    'driver' => 'sqlite',
    'database' => ':memory:',
], 'sqlite_conn');

// MySQL grammar demo (connection is not opened while generating SQL)
DB::addConnection([
    'driver' => 'mysql',
    'host' => '127.0.0.1',
    'database' => 'app_db',
    'username' => 'app_user',
    'password' => 'secret',
], 'mysql_conn');

// PostgreSQL grammar demo (connection is not opened while generating SQL)
DB::addConnection([
    'driver' => 'pgsql',
    'host' => '127.0.0.1',
    'database' => 'reporting_db',
    'username' => 'report_user',
    'password' => 'secret',
], 'pgsql_conn');

$writeLine('SQLite lockForUpdate SQL:');
$writeLine(DB::table('users', 'sqlite_conn')->lockForUpdate()->toSql());

$writeLine('MySQL lockForUpdate SQL:');
$writeLine(DB::table('users', 'mysql_conn')->lockForUpdate()->toSql());

$writeLine('PostgreSQL sharedLock SQL:');
$writeLine(DB::table('users', 'pgsql_conn')->sharedLock()->toSql());
