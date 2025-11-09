<?php
require '../vendor/autoload.php';

use Infocyph\DBLayer\DB;

// Configure database connection
DB::addConnection([
    'driver' => 'mysql',
    'host' => 'localhost',
    'port' => 3306,
    'database' => 'test_db',
    'username' => 'root',
    'password' => 'secret',
]);

// SELECT queries
$users = DB::table('users')
    ->select('id', 'name', 'email')
    ->where('active', true)
    ->orderBy('created_at', 'desc')
    ->limit(10)
    ->get();

echo "Found " . $users->count() . " users\n";
foreach ($users as $user) {
    echo "- {$user['name']} ({$user['email']})\n";
}

// Single row
$user = DB::table('users')->where('id', 1)->first();
echo "\nFirst user: " . ($user['name'] ?? 'Not found') . "\n";

// INSERT
$id = DB::table('users')->insertGetId([
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'created_at' => date('Y-m-d H:i:s'),
]);
echo "\nInserted user with ID: {$id}\n";

// UPDATE
$affected = DB::table('users')
    ->where('id', $id)
    ->update(['name' => 'Jane Doe']);
echo "Updated {$affected} rows\n";

// DELETE
$deleted = DB::table('users')->where('id', $id)->delete();
echo "Deleted {$deleted} rows\n";

// Aggregates
$count = DB::table('users')->count();
$avgAge = DB::table('users')->avg('age');
echo "\nTotal users: {$count}\n";
echo "Average age: {$avgAge}\n";
