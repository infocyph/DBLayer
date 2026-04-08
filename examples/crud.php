<?php

// examples/crud.php

declare(strict_types=1);

use Infocyph\DBLayer\DB;

require __DIR__ . '/bootstrap.php';

// CREATE: insert a new user and get its ID
$userId = DB::table('users')->insertGetId([
  'name'       => 'Hasan',
  'email'      => 'hasan@example.com',
  'active'     => 1,
  'created_at' => date('Y-m-d H:i:s'),
]);

echo "Inserted user ID: {$userId}\n";

// READ: fetch a single row
$user = DB::table('users')
  ->where('id', '=', $userId)
  ->first();

var_dump($user);

// READ: list active users with basic where/order/limit
$activeUsers = DB::table('users')
  ->where('active', '=', 1)
  ->orderBy('id', 'desc')
  ->limit(10)
  ->get();

echo "Active users (top 10):\n";
var_dump($activeUsers);

// UPDATE: change email & mark as inactive
$updatedCount = DB::table('users')
  ->where('id', '=', $userId)
  ->update([
    'email'      => 'hasan+archived@example.com',
    'active'     => 0,
    'updated_at' => date('Y-m-d H:i:s'),
  ]);

echo "Updated rows: {$updatedCount}\n";

// DELETE: delete that user
$deletedCount = DB::table('users')
  ->where('id', '=', $userId)
  ->delete();

echo "Deleted rows: {$deletedCount}\n";
