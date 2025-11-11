<?php

require '../vendor/autoload.php';

use Infocyph\DBLayer\DB;

DB::addConnection([
    'driver' => 'mysql',
    'host' => 'localhost',
    'database' => 'test_db',
    'username' => 'root',
    'password' => 'secret',
]);

// Complex WHERE clauses
$users = DB::table('users')
    ->where('active', true)
    ->where(function ($query) {
        $query->where('age', '>', 18)
              ->orWhere('verified', true);
    })
    ->whereIn('role', ['admin', 'moderator'])
    ->whereNotNull('email')
    ->get();

// Multiple ORDER BY
$posts = DB::table('posts')
    ->orderBy('featured', 'desc')
    ->orderBy('created_at', 'desc')
    ->limit(20)
    ->get();

// Pagination
$page = 1;
$perPage = 15;
$paginatedUsers = DB::table('users')
    ->forPage($page, $perPage)
    ->get();

// Chunking for large datasets
DB::table('users')->chunk(100, function ($users) {
    foreach ($users as $user) {
        // Process each user
        echo "Processing: {$user['name']}\n";
    }
});

// Cursor for memory-efficient iteration
foreach (DB::table('logs')->cursor() as $log) {
    // Only one row in memory at a time
    echo $log['message'] . "\n";
}

// Conditional queries
$status = 'active';
$users = DB::table('users')
    ->when($status, function ($query, $status) {
        return $query->where('status', $status);
    })
    ->get();

// Raw queries
$results = DB::select('SELECT * FROM users WHERE id = ?', [1]);
