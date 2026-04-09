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

it('builds OpenTelemetry payload and slow-query percentile report', function (): void {
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]);

    DB::enableTelemetry();

    DB::table('sqlite_master')->select('name')->limit(1)->get();
    DB::table('sqlite_master')->select('name')->limit(1)->get();
    DB::table('sqlite_master')->select('name')->limit(1)->get();

    $otel = DB::telemetryOtel('dblayer-tests');
    $spans = $otel['resourceSpans'][0]['scopeSpans'][0]['spans'] ?? [];

    expect($spans)->toBeArray();
    expect(count($spans))->toBeGreaterThan(0);
    expect($otel['resourceSpans'][0]['resource']['attributes'][0]['value']['stringValue'] ?? null)
        ->toBe('dblayer-tests');

    $report = DB::slowQueryReport([50, 90, 99], 0.0);

    expect($report['count'] ?? 0)->toBeGreaterThan(0);
    expect($report['percentiles']['50'] ?? null)->not->toBeNull();
    expect($report['percentiles']['90'] ?? null)->not->toBeNull();

    $flushed = DB::flushTelemetryOtel(null, 'dblayer-tests');
    $flushedSpans = $flushed['resourceSpans'][0]['scopeSpans'][0]['spans'] ?? [];

    expect($flushedSpans)->toBeArray();
    expect(DB::telemetry()['summary']['query_count'] ?? 0)->toBe(0);
});
