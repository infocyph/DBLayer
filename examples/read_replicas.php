<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;

require __DIR__ . '/../vendor/autoload.php';

$writeLine = static function (string $message): void {
    fwrite(STDOUT, $message . PHP_EOL);
};

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
$writeLine('First replica index: ' . ($connection->getReadReplicaInfo()['selected_index'] ?? -1));

$connection->reconnect(false);
$connection->select('select 1');

$info = $connection->getReadReplicaInfo();
$writeLine('Second replica index: ' . ($info['selected_index'] ?? -1));
$writeLine('Strategy: ' . ($info['strategy'] ?? 'unknown'));
