<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Exceptions\QueryException;
use Infocyph\DBLayer\Pagination\CursorCodec;
use Infocyph\DBLayer\Query\QueryBuilder;

/**
 * @return array{connection:string,table:string}
 */
function setupKeysetFixture(string $driver, bool $signed = false): array
{
    $connection = 'keyset_' . $driver . ($signed ? '_signed' : '');
    $overrides = $signed
        ? ['security' => ['cursor_signing_key' => str_repeat('cursor-secret-', 3)]]
        : [];
    dblayerAddConnectionForDriver($driver, $connection, $overrides);
    $table = dblayerTable('keyset_events');

    DB::statement(
        sprintf(
            'create table %s (
                id integer primary key,
                tenant_id integer not null,
                created_at %s not null,
                payload %s not null
            )',
            $table,
            dblayerDateTimeType(dblayerConnectionDriver($connection)),
            dblayerStringType(dblayerConnectionDriver($connection)),
        ),
        [],
        $connection,
    );

    DB::table($table, $connection)->insert([
        ['id' => 1, 'tenant_id' => 10, 'created_at' => '2025-01-01 00:00:00', 'payload' => 'one'],
        ['id' => 2, 'tenant_id' => 10, 'created_at' => '2025-01-02 00:00:00', 'payload' => 'two'],
        ['id' => 3, 'tenant_id' => 10, 'created_at' => '2025-01-02 00:00:00', 'payload' => 'three'],
        ['id' => 4, 'tenant_id' => 10, 'created_at' => '2025-01-02 00:00:00', 'payload' => 'four'],
        ['id' => 5, 'tenant_id' => 10, 'created_at' => '2025-01-03 00:00:00', 'payload' => 'five'],
        ['id' => 6, 'tenant_id' => 20, 'created_at' => '2025-01-04 00:00:00', 'payload' => 'other'],
    ]);

    return ['connection' => $connection, 'table' => $table];
}

function keysetQuery(string $table, string $connection, int $tenant = 10): QueryBuilder
{
    return DB::table($table, $connection)
        ->where('tenant_id', '=', $tenant)
        ->orderBy('created_at', 'desc');
}

/**
 * @param list<array<string,mixed>> $rows
 * @return list<int>
 */
function keysetIds(array $rows): array
{
    return array_map('intval', array_column($rows, 'id'));
}

it('paginates tied values forward and backward with a composite cursor', function (string $driver): void {
    ['connection' => $connection, 'table' => $table] = setupKeysetFixture($driver);

    $first = keysetQuery($table, $connection)->cursorPaginate(2, null, 'id', 'asc');
    $second = keysetQuery($table, $connection)->cursorPaginate(2, $first->nextCursor(), 'id', 'asc');
    $third = keysetQuery($table, $connection)->cursorPaginate(2, $second->nextCursor(), 'id', 'asc');
    $back = keysetQuery($table, $connection)->cursorPaginate(2, $second->previousCursor(), 'id', 'asc');

    expect(keysetIds($first->items()))->toBe([5, 2])
        ->and(keysetIds($second->items()))->toBe([3, 4])
        ->and(keysetIds($third->items()))->toBe([1])
        ->and(keysetIds($back->items()))->toBe([5, 2])
        ->and($first->hasPreviousPage())->toBeFalse()
        ->and($second->hasPreviousPage())->toBeTrue()
        ->and($third->hasMorePages())->toBeFalse()
        ->and($second->meta())->toHaveKeys([
            'next_cursor',
            'previous_cursor',
            'has_more',
            'has_previous',
        ]);
})->with('dblayer_drivers');

it('binds cursors to their query scope and ordering', function (string $driver): void {
    ['connection' => $connection, 'table' => $table] = setupKeysetFixture($driver);

    $cursor = keysetQuery($table, $connection)
        ->cursorPaginate(2, null, 'id', 'asc')
        ->nextCursor();

    expect(
        static fn() => keysetQuery($table, $connection, 20)
            ->cursorPaginate(2, $cursor, 'id', 'asc'),
    )->toThrow(QueryException::class, 'current query scope');

    expect(
        static fn() => DB::table($table, $connection)
            ->where('tenant_id', '=', 10)
            ->orderBy('created_at', 'asc')
            ->cursorPaginate(2, $cursor, 'id', 'asc'),
    )->toThrow(QueryException::class, 'ordering does not match');
})->with('dblayer_drivers');

it('signs cursors when configured and rejects tampering', function (string $driver): void {
    ['connection' => $connection, 'table' => $table] = setupKeysetFixture($driver, true);

    $cursor = keysetQuery($table, $connection)
        ->cursorPaginate(2, null, 'id', 'asc')
        ->nextCursor();

    expect($cursor)->not->toBeNull()->and($cursor)->toContain('.');

    $last = substr((string) $cursor, -1);
    $tampered = substr((string) $cursor, 0, -1) . ($last === 'A' ? 'B' : 'A');

    expect(
        static fn() => keysetQuery($table, $connection)
            ->cursorPaginate(2, $tampered, 'id', 'asc'),
    )->toThrow(QueryException::class, 'signature is invalid');
})->with('dblayer_drivers');

it('fails when an ordered cursor value is not selected', function (string $driver): void {
    ['connection' => $connection, 'table' => $table] = setupKeysetFixture($driver);

    expect(
        static fn() => DB::table($table, $connection)
            ->select('id')
            ->where('tenant_id', '=', 10)
            ->orderBy('created_at')
            ->cursorPaginate(1, null, 'id'),
    )->toThrow(QueryException::class, 'must be selected');
})->with('dblayer_drivers');

it('requires explicit result aliases for colliding qualified cursor columns', function (string $driver): void {
    ['connection' => $connection, 'table' => $table] = setupKeysetFixture($driver);

    expect(
        static fn() => DB::table($table, $connection)
            ->as('event_row')
            ->joinAs($table, 'same_row', 'event_row.id', '=', 'same_row.id')
            ->select('event_row.id', 'same_row.id')
            ->orderBy('event_row.id')
            ->cursorPaginate(2, null, 'event_row.id'),
    )->toThrow(QueryException::class, 'ambiguous result key');

    $page = DB::table($table, $connection)
        ->as('event_row')
        ->joinAs($table, 'same_row', 'event_row.id', '=', 'same_row.id')
        ->addSelectAs('event_row.id', 'event_id')
        ->addSelectAs('same_row.id', 'same_id')
        ->orderBy('event_row.id')
        ->cursorPaginate(2, null, 'event_row.id');

    expect($page->items())->toHaveCount(2)
        ->and($page->items()[0])->toHaveKeys(['event_id', 'same_id']);
})->with('dblayer_drivers');

it('supports descending resumable chunks and bounded lazy iteration', function (string $driver): void {
    ['connection' => $connection, 'table' => $table] = setupKeysetFixture($driver);

    $pages = [];
    DB::table($table, $connection)
        ->where('tenant_id', '=', 10)
        ->orderBy('id', 'asc')
        ->chunkById(
            2,
            static function (array $rows) use (&$pages): bool {
                $pages[] = keysetIds($rows);

                return true;
            },
            'id',
            null,
            'desc',
        );

    $lazyIds = keysetIds(iterator_to_array(
        DB::table($table, $connection)
            ->where('tenant_id', '=', 10)
            ->lazyById(2, 'id', 5, 'desc'),
        false,
    ));

    expect($pages)->toBe([[5, 4], [3, 2], [1]])
        ->and($lazyIds)->toBe([4, 3, 2, 1]);
})->with('dblayer_drivers');

it('uses the explicit driver streaming strategy and releases it on early close', function (string $driver): void {
    ['connection' => $connection, 'table' => $table] = setupKeysetFixture($driver);

    $rows = iterator_to_array(
        DB::table($table, $connection)
            ->where('tenant_id', '=', 10)
            ->orderBy('id')
            ->unbufferedStream(fetchSize: 2),
        false,
    );

    expect(keysetIds($rows))->toBe([1, 2, 3, 4, 5]);

    $generator = DB::table($table, $connection)
        ->orderBy('id')
        ->unbufferedStream(fetchSize: 2);
    $generator->current();
    unset($generator);

    expect(DB::table($table, $connection)->count())->toBe(6);
})->with('dblayer_drivers');

it('rejects non-finite cursor positions', function (float $position): void {
    expect(fn() => CursorCodec::encode(
        [['column' => 'score', 'direction' => 'asc']],
        [$position],
        'next',
        'query-fingerprint',
    ))->toThrow(QueryException::class, 'finite');
})->with([
    'positive infinity' => INF,
    'negative infinity' => -INF,
    'not a number' => NAN,
]);
