<?php

declare(strict_types=1);

use Infocyph\DBLayer\Query\Core\QueryPayload;
use Infocyph\DBLayer\Query\Core\QueryType;
use Infocyph\DBLayer\Query\Expression;

/**
 * @param list<string|Expression> $columns
 * @param list<array<string,mixed>> $wheres
 * @param list<array<string,mixed>|object> $joins
 * @param list<array{query:QueryPayload,all:bool}> $unions
 * @param list<array{name:string,query:string|QueryPayload,recursive:bool}> $ctes
 * @param list<mixed> $bindings
 */
function planV2QueryPayload(
    array $columns = ['id'],
    array $wheres = [],
    array $joins = [],
    array $unions = [],
    array $ctes = [],
    array $bindings = [],
    ?QueryPayload $sourceQuery = null,
    ?string $tableAlias = null,
): QueryPayload {
    return new QueryPayload(
        type: QueryType::SELECT,
        table: $sourceQuery === null ? 'users' : null,
        columns: $columns,
        wheres: $wheres,
        joins: $joins,
        groups: [],
        havings: [],
        orders: [],
        limit: null,
        offset: null,
        unions: $unions,
        lock: null,
        aggregate: null,
        bindings: $bindings,
        ctes: $ctes,
        tableAlias: $tableAlias,
        sourceQuery: $sourceQuery,
    );
}

it('derives raw provenance recursively from every structured child shape', function (): void {
    $rawChild = planV2QueryPayload(columns: [new Expression('count(*)')]);

    $payloads = [
        planV2QueryPayload(sourceQuery: $rawChild, tableAlias: 'source_users'),
        planV2QueryPayload(wheres: [['type' => 'exists', 'query' => $rawChild]]),
        planV2QueryPayload(joins: [['type' => 'inner', 'subquery' => true, 'query' => $rawChild]]),
        planV2QueryPayload(unions: [['query' => $rawChild, 'all' => false]]),
        planV2QueryPayload(ctes: [['name' => 'active_users', 'query' => $rawChild, 'recursive' => false]]),
        planV2QueryPayload(ctes: [['name' => 'active_users', 'query' => 'select * from users', 'recursive' => false]]),
        planV2QueryPayload(wheres: [['type' => 'nested', 'conditions' => [['type' => 'raw', 'sql' => '1 = 1']]]]),
    ];

    foreach ($payloads as $payload) {
        expect($payload->containsRawFragments)->toBeTrue();
    }
});

it('rejects malformed internal collection shapes instead of changing their semantics', function (): void {
    expect(static fn() => planV2QueryPayload(columns: ['selected' => 'id']))
        ->toThrow(\LogicException::class, 'columns must be a list')
        ->and(static fn() => planV2QueryPayload(wheres: [[0 => 'invalid']]))
        ->toThrow(\LogicException::class, 'associative components must use string keys')
        ->and(static fn() => planV2QueryPayload(bindings: ['named' => 1]))
        ->toThrow(\LogicException::class, 'bindings must be a list')
        ->and(static fn() => planV2QueryPayload(ctes: [[
            'name' => 'invalid_cte',
            'query' => 'select 1',
            'recursive' => 1,
        ]]))
        ->toThrow(\LogicException::class, 'recursive flag must be boolean');
});

it('allows nullable fields to be explicitly cleared with with', function (): void {
    $payload = planV2QueryPayload()->with([
        'limit' => 10,
        'offset' => 5,
        'lock' => 'for update',
        'aggregate' => ['function' => 'count', 'column' => '*'],
        'tableAlias' => 'users_alias',
    ]);

    $cleared = $payload->with([
        'limit' => null,
        'offset' => null,
        'lock' => null,
        'aggregate' => null,
        'tableAlias' => null,
    ]);

    expect($cleared->limit)->toBeNull()
        ->and($cleared->offset)->toBeNull()
        ->and($cleared->lock)->toBeNull()
        ->and($cleared->aggregate)->toBeNull()
        ->and($cleared->tableAlias)->toBeNull();
});
