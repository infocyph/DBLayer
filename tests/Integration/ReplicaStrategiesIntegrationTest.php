<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;

it('fails over to a healthy replica when weighted target is unhealthy', function (string $driver): void {
    $config = dblayerRequireDriver($driver);

    if ($driver === 'sqlite') {
        $connectionConfig = [
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
        ];
    } else {
        $healthyReplica = $config;
        $healthyReplica['weight'] = 1;

        $unhealthyReplica = $config;
        $unhealthyReplica['host'] = '127.0.0.2';
        $unhealthyReplica['port'] = $driver === 'mysql' ? 65000 : 65001;
        $unhealthyReplica['weight'] = 1000;

        $connectionConfig = $config;
        $connectionConfig['read_strategy'] = 'weighted';
        $connectionConfig['read_health_cooldown'] = 10;
        $connectionConfig['read'] = [$healthyReplica, $unhealthyReplica];
    }

    DB::addConnection($connectionConfig, 'replica_weighted_failover');

    $connection = DB::connection('replica_weighted_failover');
    $rows = $connection->select('select 1 as ok');

    expect($rows)->toHaveCount(1);
    expect((int) ($rows[0]['ok'] ?? 0))->toBe(1);

    $info = $connection->getReadReplicaInfo();
    expect($info['strategy'] ?? null)->toBe('weighted');
    expect($info['selected_index'] ?? null)->toBe(0);
})->with('dblayer_drivers');

it('routes reads to PHPForge physical replicas after primary writes replicate', function (): void {
    $replicationConnections = dblayerReplicationConnections();

    expect($replicationConnections)->toBeArray()
        ->and(array_keys($replicationConnections))
        ->each->toBeIn(['mysql', 'mariadb', 'pgsql', 'mssql']);

    foreach ($replicationConnections as $driver => $topology) {
        $primaryName = 'replication_primary_' . $driver;
        $replicaName = 'replication_replica_' . $driver;
        $splitName = 'replication_split_' . $driver;
        $table = dblayerTable('replication_probe');
        $marker = bin2hex(random_bytes(8));
        $tableCreated = false;

        DB::addConnection($topology['primary'], $primaryName);

        try {
            DB::statement(
                sprintf('create table %s (id integer primary key, marker %s not null)', $table, dblayerStringType($driver, 32)),
                [],
                $primaryName,
            );
            $tableCreated = true;
            DB::table($table, $primaryName)->insert(['id' => 1, 'marker' => $marker]);

            DB::addConnection($topology['replica'], $replicaName);
            $replicatedMarker = null;
            $deadline = microtime(true) + 15;
            do {
                try {
                    $replicatedMarker = DB::connection($replicaName)->scalar(
                        sprintf('select marker from %s where id = 1', $table),
                    );
                } catch (Throwable) {
                    $replicatedMarker = null;
                }

                if ($replicatedMarker !== $marker) {
                    usleep(100_000);
                }
            } while ($replicatedMarker !== $marker && microtime(true) < $deadline);

            expect($replicatedMarker)->toBe($marker);

            $splitConfig = $topology['primary'];
            $splitConfig['read'] = [$topology['replica']];
            $splitConfig['write'] = [$topology['primary']];
            DB::addConnection($splitConfig, $splitName);

            $splitConnection = DB::connection($splitName);
            expect($splitConnection->scalar(sprintf('select marker from %s where id = 1', $table)))
                ->toBe($marker)
                ->and($splitConnection->getReadReplicaInfo()['selected_index'] ?? null)
                ->toBe(0)
                ->and(spl_object_id($splitConnection->getReadPdo()))
                ->not->toBe(spl_object_id($splitConnection->getPdo()));
        } finally {
            if ($tableCreated) {
                dblayerDropTable($table, $primaryName);
            }
        }
    }
});
