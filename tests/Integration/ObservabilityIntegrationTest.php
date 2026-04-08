<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;

it('executes queries successfully when using retry policy wrappers', function (): void {
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]);

    $rows = DB::withQueryRetryPolicy(
        static fn (\Throwable $error, int $attempt, string $sql, array $bindings): bool => false,
        static fn (): array => DB::select('select 1 as ok'),
    );

    expect($rows)->toHaveCount(1);
    expect($rows[0]['ok'])->toBe(1);
});

it('exports telemetry through a custom exporter callback', function (): void {
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]);

    DB::enableTelemetry();
    DB::table('sqlite_master')->select('name')->limit(1)->get();

    $captured = null;

    $payload = DB::flushTelemetry(function (array $telemetry) use (&$captured): void {
        $captured = $telemetry;
    });

    expect($payload['summary']['query_count'])->toBeGreaterThan(0);
    expect($captured)->not->toBeNull();
    expect($captured['summary']['query_count'])->toBeGreaterThan(0);
});
