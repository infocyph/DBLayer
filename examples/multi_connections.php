<?php

// examples/multi_connections.php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\DB;

require __DIR__ . '/bootstrap.php';

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
    echo "User not found.\n";
    exit;
}

// Read from PostgreSQL using the connection name directly
$userEvents = DB::table('user_events', 'pgsql_reporting')
  ->where('user_id', '=', $user['id'])
  ->orderBy('occurred_at', 'desc')
  ->limit(50)
  ->get();

echo "User:\n";
var_dump($user);

echo "Last 50 events (from reporting DB):\n";
var_dump($userEvents);
