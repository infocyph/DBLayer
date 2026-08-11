<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;

it('compiles truncate without cascade or identity reset for every dialect', function (array $config, string $expected): void {
    $connection = new Connection(new ConnectionConfig($config), 'truncate-contract');
    $compiled = $connection->getCompiler()->compile(
        $connection->table('parent_rows')->toTruncatePayload(),
    );

    expect($compiled->sql)->toBe($expected)
        ->and(strtoupper($compiled->sql))->not->toContain('CASCADE')
        ->and(strtoupper($compiled->sql))->not->toContain('RESTART IDENTITY');
})->with([
    'mysql' => [[
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'database' => 'app',
        'username' => 'app',
    ], 'DELETE FROM `parent_rows`'],
    'pgsql' => [[
        'driver' => 'pgsql',
        'host' => '127.0.0.1',
        'database' => 'app',
        'username' => 'app',
    ], 'TRUNCATE TABLE "parent_rows"'],
    'sqlite' => [[
        'driver' => 'sqlite',
        'database' => ':memory:',
    ], 'DELETE FROM "parent_rows"'],
]);
