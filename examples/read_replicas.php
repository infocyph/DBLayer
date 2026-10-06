<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;

require __DIR__ . '/../vendor/autoload.php';

$writeLine = static function (string $message): void {
    fwrite(STDOUT, $message . PHP_EOL);
};

DB::purge();

$database = tempnam(sys_get_temp_dir(), 'dblayer-replicas-');
if ($database === false) {
    throw new RuntimeException('Unable to create the SQLite demonstration file.');
}

// All handles share a file here. This demonstrates routing, not replication.
// Independent :memory: handles would contain independent databases.
DB::addConnection([
    'driver' => 'sqlite',
    'database' => $database,
    'read_strategy' => 'round_robin',
    'read' => [
        ['database' => $database],
        ['database' => $database],
    ],
]);

try {
    $connection = DB::connection();
    // WAL is an explicit initialization choice; it still allows one writer.
    $connection->statement('pragma journal_mode = WAL');
    $connection->statement('create table items (id integer primary key)');
    $connection->table('items')->insert(['id' => 1]);

    $writeLine('First read row count: ' . $connection->table('items')->count());
    $writeLine('First replica index: ' . ($connection->getReadReplicaInfo()['selected_index'] ?? -1));

    // Healthy read handles stay selected until reconnect, not until next query.
    $connection->reconnect(false);
    $writeLine('Second read row count: ' . $connection->table('items')->count());

    $info = $connection->getReadReplicaInfo();
    $writeLine('Second replica index: ' . ($info['selected_index'] ?? -1));
    $writeLine('Strategy: ' . ($info['strategy'] ?? 'unknown'));
} finally {
    DB::purge();
    foreach ([$database, $database . '-wal', $database . '-shm'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
}
