<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Monitoring\DatabaseMonitor;

beforeEach(function (): void {
    DB::purge();
});

afterEach(function (): void {
    DB::purge();
});

it('exposes database status only through the monitor surface', function (): void {
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]);

    $monitor = DB::monitor();

    expect($monitor)->toBeInstanceOf(DatabaseMonitor::class);
    expect($monitor->status())
        ->driver->toBe('sqlite')
        ->database->toBe(':memory:')
        ->toHaveKey('server');
});

it('collects an on-demand sqlite system snapshot without synthetic server features', function (): void {
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]);

    DB::statement('create table monitor_items (id integer primary key, name text not null)');
    DB::statement('create index monitor_items_name_idx on monitor_items (name)');

    $monitor = DB::monitor();
    $snapshot = $monitor->snapshot();

    expect($snapshot)
        ->driver->toBe('sqlite')
        ->database->toBe(':memory:')
        ->sessions->toBe([])
        ->long_running_queries->toBe([])
        ->locks->toBe([])
        ->replication->toBe([])
        ->errors->toBe([])
        ->not->toHaveKey('maintenance');

    expect($snapshot['status']['server']['page_size'])->toBeGreaterThan(0);
    expect($snapshot['table_metrics'][0]['table_name'])->toBe('monitor_items');
    expect($snapshot['index_metrics'][0]['index_name'])->toBe('monitor_items_name_idx');
});

it('keeps heavier maintenance inspection opt in', function (): void {
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]);

    $snapshot = DB::monitor()->snapshot(includeMaintenance: true);

    expect($snapshot)->toHaveKey('maintenance');
    expect($snapshot['maintenance'][0])
        ->toHaveKeys(['page_count', 'page_size', 'free_pages', 'reclaimable_bytes', 'reclaimable_percent']);
});
