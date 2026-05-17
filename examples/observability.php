<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Exceptions\ConnectionException;

require __DIR__ . '/../vendor/autoload.php';

$writeLine = static function (string $message): void {
    fwrite(STDOUT, $message . PHP_EOL);
};

DB::purge();

DB::addConnection([
    'driver' => 'sqlite',
    'database' => ':memory:',
]);

DB::enableTelemetry();

// Retry wrapper around query execution.
$rows = DB::withQueryRetryPolicy(
    static function (Throwable $error, int $attempt, string $sql, array $bindings): bool {
        unset($error, $attempt, $sql, $bindings);

        return false;
    },
    static fn(): array => DB::select('select 1 as ok'),
);

$writeLine('Rows: ' . count($rows));

try {
    DB::withQueryDeadline(
        0.0,
        static fn(): mixed => DB::select('select 1'),
    );
} catch (ConnectionException $e) {
    $writeLine('Deadline triggered: ' . $e->getMessage());
}

$telemetry = DB::flushTelemetry();
$writeLine('Collected queries: ' . ($telemetry['summary']['query_count'] ?? 0));

DB::disableTelemetry();
