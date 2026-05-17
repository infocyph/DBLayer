<?php

// examples/crud.php

declare(strict_types=1);

use Infocyph\DBLayer\DB;

require __DIR__ . '/bootstrap.php';

$writeLine = static function (string $message): void {
    fwrite(STDOUT, $message . PHP_EOL);
};

$writeDump = static function (mixed $value): void {
    $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    fwrite(STDOUT, ($encoded === false ? '[unserializable]' : $encoded) . PHP_EOL);
};

// CREATE: insert a new user and get its ID
$userId = DB::table('users')->insertGetId([
    'name' => 'Hasan',
    'email' => 'hasan@example.com',
    'active' => 1,
    'created_at' => date('Y-m-d H:i:s'),
]);

$writeLine("Inserted user ID: {$userId}");

// READ: fetch a single row
$user = DB::table('users')
  ->where('id', '=', $userId)
  ->first();

$writeDump($user);

// READ: list active users with basic where/order/limit
$activeUsers = DB::table('users')
  ->where('active', '=', 1)
  ->orderBy('id', 'desc')
  ->limit(10)
  ->get();

$writeLine('Active users (top 10):');
$writeDump($activeUsers);

// UPDATE: change email & mark as inactive
$updatedCount = DB::table('users')
  ->where('id', '=', $userId)
  ->update([
      'email' => 'hasan+archived@example.com',
      'active' => 0,
      'updated_at' => date('Y-m-d H:i:s'),
  ]);

$writeLine("Updated rows: {$updatedCount}");

// UPSERT: insert-or-update by unique key
DB::table('users')->upsert([
    'email' => 'hasan@example.com',
    'name' => 'Hasan Updated',
    'active' => 1,
    'updated_at' => date('Y-m-d H:i:s'),
], ['email'], ['name', 'active', 'updated_at']);

// INSERT IGNORE: duplicate unique key is ignored (if driver supports it)
$ignored = DB::table('users')->insertIgnore([
    'name' => 'Duplicate Hasan',
    'email' => 'hasan@example.com',
    'active' => 1,
    'created_at' => date('Y-m-d H:i:s'),
]);

$writeLine('Insert ignore result: ' . ($ignored ? 'inserted' : 'ignored'));

// DELETE: delete that user
$deletedCount = DB::table('users')
  ->where('id', '=', $userId)
  ->delete();

$writeLine("Deleted rows: {$deletedCount}");

// TRUNCATE: clear table
DB::table('users')->truncate();
$writeLine('Users table truncated.');
