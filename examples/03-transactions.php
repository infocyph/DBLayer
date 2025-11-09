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

// Automatic transaction
try {
    DB::transaction(function() {
        DB::table('accounts')->where('id', 1)->decrement('balance', 100);
        DB::table('accounts')->where('id', 2)->increment('balance', 100);
        DB::table('transactions')->insert([
            'from_account' => 1,
            'to_account' => 2,
            'amount' => 100,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    });
    echo "Transaction completed successfully\n";
} catch (\Exception $e) {
    echo "Transaction failed: " . $e->getMessage() . "\n";
}

// Manual transaction control
DB::beginTransaction();
try {
    DB::table('users')->insert(['name' => 'Test User']);
    DB::table('profiles')->insert(['user_id' => DB::table('users')->max('id')]);
    DB::commit();
    echo "Manual transaction committed\n";
} catch (\Exception $e) {
    DB::rollBack();
    echo "Manual transaction rolled back\n";
}

// Transaction with retry on deadlock
DB::transaction(function() {
    // ... operations
}, $attempts = 3);
