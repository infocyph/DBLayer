<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Monitoring\DatabaseMonitor;

it('reports status through each available driver monitor', function (string $driver): void {
    $config = dblayerAddConnectionForDriver($driver);
    $monitor = DB::monitor();
    $status = $monitor->status();

    expect($monitor)->toBeInstanceOf(DatabaseMonitor::class)
        ->and($status['driver'])->toBe($driver)
        ->and($status['database'])->toBe((string) $config['database'])
        ->and($status['connected'])->toBeTrue()
        ->and($status['server'])->toBeArray();
})->with('dblayer_drivers');
