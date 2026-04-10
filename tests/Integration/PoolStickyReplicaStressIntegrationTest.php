<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\DB;

it('handles pooled load with sticky reads and replica failover', function (string $driver): void {
    $connectionName = 'stress_pool_replica_' . $driver;
    $sqliteFile = null;

    if ($driver === 'sqlite') {
        $sqliteFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'dblayer-stress-'
            . bin2hex(random_bytes(8))
            . '.sqlite';

        $config = [
            'driver' => 'sqlite',
            'database' => $sqliteFile,
            'sticky' => true,
            'read_strategy' => 'weighted',
            'read' => [
                ['database' => $sqliteFile, 'weight' => 1],
            ],
            'write' => [
                ['database' => $sqliteFile],
            ],
        ];
    } else {
        $base = dblayerRequireDriver($driver);
        $invalidReplica = $base;
        $invalidReplica['host'] = '203.0.113.1';
        $invalidReplica['port'] = $driver === 'mysql' ? 3307 : 55432;

        $config = $base;
        $config['sticky'] = true;
        $config['read_strategy'] = 'weighted';
        $config['read'] = [
            array_merge($invalidReplica, ['weight' => 10]),
            array_merge($base, ['weight' => 1]),
        ];
        $config['write'] = [$base];
    }

    DB::addConnection($config, $connectionName);

    $poolManager = DB::poolManager([
        'max_connections' => 3,
        'idle_timeout' => 60,
        'max_lifetime' => 3_600,
        'health_check_interval' => 1,
    ]);

    $schemaDriver = dblayerConnectionDriver($connectionName);
    $table = dblayerTable('stress_items');

    DB::statement(
        sprintf(
            'create table %s (%s, marker %s)',
            $table,
            dblayerAutoIncrementPrimaryKey($schemaDriver),
            dblayerStringType($schemaDriver, 128),
        ),
        [],
        $connectionName,
    );

    DB::table($table, $connectionName)->insert(['marker' => 'seed']);

    $connectionIds = [];
    $previousCount = 1;

    for ($iteration = 1; $iteration <= 30; $iteration++) {
        $result = DB::withPooledConnection(
            static function (Connection $connection) use ($iteration, $table): array {
                $connection->select('select 1');

                if ($iteration % 4 === 0) {
                    $connection->table($table)->insert(['marker' => 'm' . $iteration]);
                }

                return [
                    'id' => spl_object_id($connection),
                    'count' => (int) $connection->scalar(sprintf('select count(*) from %s', $table)),
                ];
            },
            $connectionName,
        );

        $connectionIds[] = (int) ($result['id'] ?? 0);
        $currentCount = (int) ($result['count'] ?? 0);
        expect($currentCount)->toBeGreaterThanOrEqual($previousCount);
        $previousCount = $currentCount;
    }

    $stats = $poolManager->getPool()->getStats();

    expect(count(array_unique($connectionIds)))->toBeLessThanOrEqual(3);
    expect((int) ($stats['created'] ?? 0))->toBeGreaterThanOrEqual(1);
    expect((int) ($stats['reused'] ?? 0))->toBeGreaterThan(0);

    $connection = DB::connection($connectionName);
    $connection->disconnect();
    $writePdoBefore = $connection->getPdo();

    $connection->select('select 1');

    if ($driver !== 'sqlite') {
        $replicaInfo = $connection->getReadReplicaInfo();
        expect($replicaInfo['selected_index'] ?? null)->toBe(1);
    }

    $connection->table($table)->insert(['marker' => 'sticky-check']);

    $readPdoAfter = $connection->getReadPdo();

    expect(spl_object_id($readPdoAfter))->toBe(spl_object_id($connection->getPdo()));
    expect(spl_object_id($writePdoBefore))->toBe(spl_object_id($connection->getPdo()));

    $connection->disconnect();
    $poolManager->getPool()->closeAll();

    unset($sqliteFile);
})->with('dblayer_drivers');
