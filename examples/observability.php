<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Exceptions\ConnectionException;

require __DIR__ . '/../vendor/autoload.php';

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

echo 'Rows: ' . count($rows) . PHP_EOL;

try {
    DB::withQueryDeadline(
        0.0,
        static fn(): mixed => DB::select('select 1'),
    );
} catch (ConnectionException $e) {
    echo 'Deadline triggered: ' . $e->getMessage() . PHP_EOL;
}

$telemetry = DB::flushTelemetry();
echo 'Collected queries: ' . ($telemetry['summary']['query_count'] ?? 0) . PHP_EOL;

DB::disableTelemetry();
