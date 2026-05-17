<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Driver\Support\DriverProfile;

it('clears runtime-only facade state and preserves connection configs', function (): void {
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ], 'runtime_reset');

    DB::enableQueryLog();
    DB::enableProfiler();
    DB::enableTelemetry();

    $listenerCalls = 0;
    DB::listen(static function () use (&$listenerCalls): void {
        $listenerCalls++;
    });

    $thresholdCalls = 0;
    DB::whenQueryingForLongerThan(0.0001, static function () use (&$thresholdCalls): void {
        $thresholdCalls++;
    });

    DB::select('select 1', [], 'runtime_reset');
    $baselineListenerCalls = $listenerCalls;
    $baselineThresholdCalls = $thresholdCalls;
    expect(DB::hasConnection('runtime_reset'))->toBeTrue();

    DB::resetRuntimeState(false);

    expect(DB::hasConnection('runtime_reset'))->toBeTrue();
    expect(DB::getQueryLog())->toBe([]);
    expect(DB::profiler()->profiles())->toBe([]);
    expect((int) (DB::telemetry()['summary']['query_count'] ?? 0))->toBe(0);

    DB::select('select 1', [], 'runtime_reset');

    expect($listenerCalls)->toBe($baselineListenerCalls);
    expect($thresholdCalls)->toBe($baselineThresholdCalls);
});

it('recreates disconnected instances when runtime state reset requests disconnection', function (): void {
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ], 'runtime_reset_disconnect');

    $first = DB::connection('runtime_reset_disconnect');
    $firstId = spl_object_id($first);

    DB::resetRuntimeState(true);

    expect(DB::hasConnection('runtime_reset_disconnect'))->toBeTrue();

    $second = DB::connection('runtime_reset_disconnect');
    expect(spl_object_id($second))->not->toBe($firstId);
});

it('classifies retryable transaction conflicts by driver metadata and message hints', function (): void {
    $mysqlDeadlock = new PDOException('Deadlock found when trying to get lock', 1213);
    $mysqlDeadlock->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock'];

    $sqliteBusy = new PDOException('database is locked');
    $sqliteBusy->errorInfo = ['HY000', 5, 'database is locked'];

    $pgsqlSerialization = new PDOException('serialization failure');
    $pgsqlSerialization->errorInfo = ['40001', null, 'serialization failure'];

    expect(DriverProfile::causedByRetryableTransactionError('mysql', $mysqlDeadlock))->toBeTrue();
    expect(DriverProfile::causedByRetryableTransactionError('sqlite', $sqliteBusy))->toBeTrue();
    expect(DriverProfile::causedByRetryableTransactionError('pgsql', $pgsqlSerialization))->toBeTrue();
});

it('retries connection errors once by default before stopping', function (): void {
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ], 'runtime_retry_connection_errors');

    $connection = DB::connection('runtime_retry_connection_errors');
    $reflection = new ReflectionClass($connection);
    $shouldRetry = $reflection->getMethod('shouldRetryQuery');

    $connectionError = new PDOException('server has gone away', 2006);
    $connectionError->errorInfo = ['HY000', 2006, 'server has gone away'];

    expect((bool) $shouldRetry->invoke($connection, $connectionError, 1, 'select 1', []))->toBeTrue();
    expect((bool) $shouldRetry->invoke($connection, $connectionError, 2, 'select 1', []))->toBeFalse();
});

it('enforces retry max-attempt bounds even when custom policy always retries', function (): void {
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ], 'runtime_retry_bounds');

    $connection = DB::connection('runtime_retry_bounds');
    $reflection = new ReflectionClass($connection);
    $retryPolicy = $reflection->getProperty('queryRetryPolicy');
    $shouldRetry = $reflection->getMethod('shouldRetryQuery');

    $retryPolicy->setValue($connection, static fn(): bool => true);

    $invalidSql = new PDOException('syntax error');
    $invalidSql->errorInfo = ['HY000', 1, 'syntax error'];

    expect((bool) $shouldRetry->invoke($connection, $invalidSql, 1, 'insert into t values (1)', []))->toBeTrue();
    expect((bool) $shouldRetry->invoke($connection, $invalidSql, 5, 'insert into t values (1)', []))->toBeFalse();
});
