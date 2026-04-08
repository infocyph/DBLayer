<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;

require __DIR__ . '/../vendor/autoload.php';

DB::purge();

DB::addConnection([
    'driver' => 'sqlite',
    'database' => ':memory:',
    'read_strategy' => 'round_robin',
    'read' => [
        ['database' => ':memory:'],
        ['database' => ':memory:'],
    ],
]);

$connection = DB::connection();

$connection->select('select 1');
echo 'First replica index: ' . ($connection->getReadReplicaInfo()['selected_index'] ?? -1) . PHP_EOL;

$connection->reconnect(false);
$connection->select('select 1');

$info = $connection->getReadReplicaInfo();
echo 'Second replica index: ' . ($info['selected_index'] ?? -1) . PHP_EOL;
echo 'Strategy: ' . ($info['strategy'] ?? 'unknown') . PHP_EOL;
