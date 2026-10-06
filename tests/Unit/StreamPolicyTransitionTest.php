<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\DBLayer\Exceptions\ConnectionException;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\RuntimeContext;

function dblayerTransitionStream(bool $pooled, bool $unbuffered): array
{
    $connection = new Connection(ConnectionConfig::fromArray([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'statement_cache_enabled' => true,
    ]));
    if ($pooled) {
        $connection->markPoolManaged();
    }
    $sql = 'select 1 as id union all select 2 union all select 3';
    $stream = $unbuffered ? $connection->unbufferedStream($sql) : $connection->stream($sql);
    $stream->rewind();
    expect($stream->current()['id'])->toBe(1);

    return [$connection, $stream];
}

it('enforces cancellation introduced while a stream is paused', function (bool $pooled, bool $unbuffered): void {
    [$connection, $stream] = dblayerTransitionStream($pooled, $unbuffered);
    expect(fn() => $connection->withQueryCancellation(
        static fn(): bool => true,
        fn() => $stream->next(),
    ))->toThrow(ConnectionException::class, 'cancelled');
    expect($connection->scalar('select 42'))->toBe(42);
})->with([[false, false], [true, false], [false, true], [true, true]]);

it('keeps the strictest nested deadline introduced between rows', function (bool $pooled, bool $unbuffered): void {
    [$connection, $stream] = dblayerTransitionStream($pooled, $unbuffered);
    expect(fn() => $connection->withQueryDeadline(
        0.0,
        fn() => $connection->withQueryDeadline(60.0, fn() => $stream->next()),
    ))->toThrow(ConnectionException::class);
    expect($connection->hasActiveQueryBudget())->toBeFalse();
})->with([[false, false], [true, false], [false, true], [true, true]]);

it('checks host cancellation activated inside a nested binding between rows', function (bool $pooled): void {
    [$connection, $stream] = dblayerTransitionStream($pooled, false);
    $runtime = RuntimeContext::standalone();
    $request = RequestContext::create($runtime);
    expect(fn() => $connection->withRunwire($runtime, function () use ($connection, $stream, $runtime, $request): void {
        $request->cancel(CancellationReason::HOST_CANCELLED);
        $connection->withRunwire($runtime, fn() => $stream->next(), $request);
    }, $request))->toThrow(ConnectionException::class, 'cancelled');
    expect($connection->runwireBinding())->toBeNull()
        ->and($connection->hasActiveQueryBudget())->toBeFalse();
})->with([false, true]);

it('checks cancellation after a native fetch before yielding its row', function (bool $pooled): void {
    [$connection, $stream] = dblayerTransitionStream($pooled, false);
    $checks = 0;
    $checker = static function () use (&$checks): bool { return ++$checks >= 2; };
    expect(fn() => $connection->withQueryCancellation(
        $checker,
        fn() => $stream->next(),
    ))->toThrow(ConnectionException::class, 'cancelled');
    expect($checks)->toBe(2);
})->with([false, true]);

it('restores temporary policy after a successful stream advance', function (): void {
    [$connection, $stream] = dblayerTransitionStream(false, false);
    $connection->withQueryCancellation(static fn(): bool => false, fn() => $stream->next());
    expect($stream->current()['id'])->toBe(2)
        ->and($connection->hasActiveQueryBudget())->toBeFalse();
    $stream->next();
    expect($stream->current()['id'])->toBe(3);
});
