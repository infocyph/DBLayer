<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Events\Events;
use Infocyph\DBLayer\Exceptions\SecurityException;
use Infocyph\DBLayer\Security\RateLimiter;

it('bounds the facade query log by default and retains the newest entries', function (): void {
    Events::forgetAll();
    Events::listen('db.query.executing', static function (): void {});
    Events::listen('db.query.executed', static function (): void {});
    Events::listen('db.query.failed', static function (): void {});

    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ], 'bounded_query_log');
    DB::enableQueryLog();

    for ($value = 0; $value < 2_005; $value++) {
        DB::select('select ? as value', [$value], 'bounded_query_log');
    }

    $queryLog = DB::getQueryLog();

    expect($queryLog)->toHaveCount(2_000);
    expect($queryLog[0]['bindings'] ?? null)->toBe([5]);
    expect($queryLog[1_999]['bindings'] ?? null)->toBe([2_004]);
});

it('restores the default query log bound when passed null', function (): void {
    DB::setMaxQueryLogEntries(2);
    DB::setMaxQueryLogEntries(null);

    $reflection = new ReflectionClass(DB::class);
    $property = $reflection->getProperty('maxQueryLogEntries');

    expect($property->getValue())->toBe(2_000);
});

it('bounds executor query logs and restores their safe default', function (): void {
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ], 'bounded_executor_log');

    $executor = DB::connection('bounded_executor_log')->getExecutorInstance();
    $executor->setMaxQueryLogEntries(2);
    $executor->setMaxQueryLogEntries(null);
    $executor->enableQueryLog();

    for ($value = 0; $value < 2_005; $value++) {
        $executor->raw('select ? as value', [$value]);
    }

    $queryLog = $executor->getQueryLog();

    expect($queryLog)->toHaveCount(2_000);
    expect($queryLog[0]['bindings'] ?? null)->toBe([5]);
    expect($queryLog[1_999]['bindings'] ?? null)->toBe([2_004]);
});

it('fails closed when active rate limit storage reaches its bound', function (): void {
    $limiter = new RateLimiter(2);
    $limiter->check('tenant-a', 10, 60);
    $limiter->check('tenant-b', 10, 60);

    expect(static fn() => $limiter->check('tenant-c', 10, 60))
        ->toThrow(SecurityException::class, 'storage capacity');
});

it('purges expired rate limit buckets before enforcing capacity', function (): void {
    $limiter = new RateLimiter(1);
    $reflection = new ReflectionClass($limiter);
    $storage = $reflection->getProperty('storage');
    $expiresAt = $reflection->getProperty('expiresAt');

    $storage->setValue($limiter, ['expired:1:1' => 1]);
    $expiresAt->setValue($limiter, ['expired:1:1' => time() - 1]);

    $limiter->check('current', 10, 60);

    expect($limiter->getStats())->toBe([
        'total_keys' => 1,
        'total_requests' => 1,
    ]);
});

it('rejects non-positive rate limit storage capacity', function (int $capacity): void {
    expect(static fn() => new RateLimiter($capacity))
        ->toThrow(SecurityException::class, 'capacity must be greater than zero');
})->with([0, -1]);
