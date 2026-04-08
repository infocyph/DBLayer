<?php

// examples/transactions.php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Exceptions\TransactionException;

require __DIR__ . '/bootstrap.php';

// 6.1 Using the default connection via DB::transaction()
// __callStatic() forwards this to Connection::transaction().
try {
    $orderId = DB::transaction(
        static function (Connection $conn): string {
            // Create order
            $orderId = $conn->table('orders')->insertGetId([
                'user_id' => 42,
                'status' => 'pending',
                'total' => 1999,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            // Create order items
            $conn->table('order_items')->insert([
                'order_id' => $orderId,
                'sku' => 'SKU-123',
                'qty' => 1,
                'unit_price' => 1999,
            ]);

            // Update user’s last_order_at
            $conn
              ->table('users')
              ->where('id', '=', 42)
              ->update([
                  'last_order_at' => date('Y-m-d H:i:s'),
              ]);

            // Whatever you return from the closure is returned by transaction()
            return $orderId;
        },
    );

    echo "Order created with ID: {$orderId}\n";
} catch (TransactionException $e) {
    // All queries inside the closure are rolled back on exception
    echo "Failed to create order: {$e->getMessage()}\n";
}

// 6.2 Explicit connection & nested transaction work on that one connection only
$mysql = DB::connection('mysql_main');

try {
    $result = $mysql->transaction(
        static function (Connection $conn): bool {
            $currentBalance = (int) $conn
              ->table('wallets')
              ->where('user_id', '=', 42)
              ->value('balance');

            // debit wallet
            $conn
              ->table('wallets')
              ->where('user_id', '=', 42)
              ->update([
                  'balance' => $currentBalance - 1999,
              ]);

            // log movement
            $conn->table('wallet_movements')->insert([
                'user_id' => 42,
                'amount' => -1999,
                'reason' => 'order_payment',
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            return true;
        },
    );

    echo "Wallet transaction result: " . ($result ? 'OK' : 'NOOP') . "\n";
} catch (\Throwable $e) {
    echo "Wallet transaction failed: {$e->getMessage()}\n";
}
