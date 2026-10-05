<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\DBLayer\Exceptions\ConnectionException;
use Infocyph\DBLayer\Exceptions\QueryException;
use Infocyph\DBLayer\Query\ConnectionRepository;
use Infocyph\DBLayer\Schema\SchemaManager;

function dblayerBatchAConnection(array $overrides = []): Connection
{
    return new Connection(ConnectionConfig::fromArray(array_replace_recursive([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ], $overrides)), 'batch-a');
}

function dblayerBatchAPopulatedConnection(array $overrides = []): Connection
{
    $connection = dblayerBatchAConnection($overrides);
    $connection->statement('create table items (id integer primary key, tenant_id integer, value text)');
    $connection->insert('insert into items (id, tenant_id, value) values (?, ?, ?)', [1, 1, 'original']);
    $connection->resetRuntimeStateForReuse();

    return $connection;
}

it('rejects SQL syntax smuggled through structured aggregate identifiers', function (): void {
    $connection = dblayerBatchAPopulatedConnection([
        'security' => ['raw_sql_policy' => 'deny'],
    ]);

    expect(fn() => $connection->table('items')->aggregate('MAX(42) FROM items --'))
        ->toThrow(QueryException::class, 'Aggregate function must be a plain SQL function identifier')
        ->and(fn() => $connection->table('items')->aggregate('MAX', 'value'))->not->toThrow(QueryException::class)
        ->and($connection->table('items')->count('id'))->toBe(1);
});

it('rejects tenant conflicts and hook-driven tenant reassignment', function (): void {
    $connection = dblayerBatchAConnection();
    $connection->statement('create table items (id integer primary key, tenant_id integer, value text)');

    $repository = new class($connection) extends ConnectionRepository {
        protected function table(): string
        {
            return 'items';
        }
    };

    $scoped = $repository->forTenant(1);

    expect(fn() => $scoped->create(['id' => 1, 'tenant_id' => 2, 'value' => 'wrong']))
        ->toThrow(InvalidArgumentException::class, 'Tenant-scoped write cannot assign');

    $scoped->beforeCreate(static function (array $payload): array {
        $payload['tenant_id'] = 2;

        return $payload;
    });

    expect(fn() => $scoped->create(['id' => 2, 'value' => 'hooked']))
        ->toThrow(InvalidArgumentException::class, 'Tenant-scoped write cannot assign');

    $cleanRepository = new class($connection) extends ConnectionRepository {
        protected function table(): string
        {
            return 'items';
        }
    };
    $cleanRepository->forTenant(1)->create(['id' => 3, 'value' => 'owned']);

    expect(fn() => $cleanRepository->forTenant(1)->updateById(3, ['tenant_id' => 2]))
        ->toThrow(InvalidArgumentException::class, 'Tenant-scoped write cannot assign')
        ->and(fn() => $cleanRepository->forTenant(1)->upsert(
            ['id' => 4, 'tenant_id' => 2, 'value' => 'wrong'],
            ['id'],
        ))->toThrow(InvalidArgumentException::class, 'Tenant-scoped write cannot assign');
});

it('versions cache identity across schema role and explicit security scope', function (): void {
    $first = new Connection(ConnectionConfig::fromArray([
        'driver' => 'pgsql',
        'host' => 'localhost',
        'database' => 'app',
        'username' => 'role_a',
        'password' => 'unused',
        'schema' => 'tenant_a',
        'cache_scope' => 'rls:a',
    ]), 'main');
    $second = new Connection(ConnectionConfig::fromArray([
        'driver' => 'pgsql',
        'host' => 'localhost',
        'database' => 'app',
        'username' => 'role_b',
        'password' => 'unused',
        'schema' => 'tenant_b',
        'cache_scope' => 'rls:b',
    ]), 'main');

    expect($first->cacheScopeFingerprint())
        ->not->toBe($second->cacheScopeFingerprint())
        ->and($first->cacheTableTag('items'))
        ->not->toBe($second->cacheTableTag('items'));
});

it('bypasses result cache inside externally owned PDO transactions', function (): void {
    $connection = dblayerBatchAPopulatedConnection();
    $connection->setQueryCache(Cache::memory('dblayer-batch-a-native-transaction'));
    $query = $connection->table('items')->cacheFor(60);

    expect($query->get()[0]['value'])->toBe('original');

    $pdo = $connection->getPdo();
    $pdo->beginTransaction();
    $pdo->exec("update items set value = 'uncommitted' where id = 1");

    expect($query->get()[0]['value'])->toBe('uncommitted')
        ->and(fn() => $connection->afterCommit(static function (): void {}))
        ->toThrow(ConnectionException::class, 'DBLayer-managed transaction ownership');

    $pdo->rollBack();

    expect($query->get()[0]['value'])->toBe('original');
});

it('invalidates schema cache through the exact connection owner', function (): void {
    $connection = dblayerBatchAPopulatedConnection();
    $connection->setQueryCache(Cache::memory('dblayer-batch-a-schema-owner'));
    $query = $connection->table('items')->cacheFor(60);

    expect($query->get()[0]['value'])->toBe('original');

    (new SchemaManager($connection))->drop('items');
    $connection->statement('create table items (id integer primary key, tenant_id integer, value text)');
    $connection->insert('insert into items (id, tenant_id, value) values (?, ?, ?)', [1, 1, 'replacement']);

    expect($query->get()[0]['value'])->toBe('replacement');
});

it('does not report a cooperative timeout after a completed mutation', function (): void {
    $connection = dblayerBatchAPopulatedConnection(['sticky' => true]);
    $connection->setQueryCache(Cache::memory('dblayer-batch-a-late-write'));
    $query = $connection->table('items')->cacheFor(60);

    expect($query->get()[0]['value'])->toBe('original');

    $pdo = $connection->getPdo();
    expect($pdo)->toBeInstanceOf(Pdo\Sqlite::class);
    $pdo->createFunction('batch_a_delay', static function (): int {
        usleep(5_000);

        return 1;
    });
    $connection->statement(
        'create trigger batch_a_update after update on items begin select batch_a_delay(); end',
    );

    $affected = $connection->withQueryTimeoutMs(
        1,
        fn(): int => $connection->table('items')->where('id', '=', 1)->update(['value' => 'committed']),
    );

    expect($affected)->toBe(1)
        ->and($connection->hasStickyWrite())->toBeTrue()
        ->and($query->get()[0]['value'])->toBe('committed');
});
