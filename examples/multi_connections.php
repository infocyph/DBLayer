<?php

// examples/multi_connections.php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\DB;

require __DIR__ . '/bootstrap.php';

$writeLine = static function (string $message): void {
    fwrite(STDOUT, $message . PHP_EOL);
};

$writeDump = static function (mixed $value): void {
    $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    fwrite(STDOUT, ($encoded === false ? '[unserializable]' : $encoded) . PHP_EOL);
};

// Explicit connection instances if you prefer
/** @var Connection $mysql */
$mysql = DB::connection('mysql_main');

/** @var Connection $pgsql */
$pgsql = DB::connection('pgsql_reporting');

// Read from MySQL
$user = $mysql->table('users')
  ->where('email', '=', 'hasan@example.com')
  ->first();

if ($user === null) {
    $writeLine('User not found.');

    return;
}

// Read from PostgreSQL using the connection name directly
$userEvents = DB::table('user_events', 'pgsql_reporting')
  ->where('user_id', '=', $user['id'])
  ->orderBy('occurred_at', 'desc')
  ->limit(50)
  ->get();

$writeLine('User:');
$writeDump($user);

$writeLine('Last 50 events (from reporting DB):');
$writeDump($userEvents);
