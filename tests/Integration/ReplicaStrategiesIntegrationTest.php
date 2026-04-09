<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;

it('fails over to a healthy replica when weighted target is unhealthy', function (): void {
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'read_strategy' => 'weighted',
        'read_health_cooldown' => 10,
        'read' => [
            [
                'database' => ':memory:',
                'weight' => 1,
            ],
            [
                'database' => '/path/that/does/not/exist/read-replica.sqlite',
                'weight' => 1000,
            ],
        ],
    ], 'replica_weighted_failover');

    $connection = DB::connection('replica_weighted_failover');
    $rows = $connection->select('select 1 as ok');

    expect($rows)->toHaveCount(1);
    expect((int) ($rows[0]['ok'] ?? 0))->toBe(1);

    $info = $connection->getReadReplicaInfo();
    expect($info['strategy'] ?? null)->toBe('weighted');
    expect($info['selected_index'] ?? null)->toBe(0);
});
