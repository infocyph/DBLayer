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

it('rejects tenant-scoped upsert contracts that can target another tenant', function (): void {
    $connection = dblayerBatchAConnection();
    $connection->statement(
        'create table tenant_items ('
        . 'id integer primary key, tenant_id integer not null, value text, '
        . 'unique (tenant_id, id))',
    );
    $connection->insert(
        'insert into tenant_items (id, tenant_id, value) values (?, ?, ?)',
        [7, 2, 'tenant-two'],
    );

    $repository = new class($connection) extends ConnectionRepository {
        protected function table(): string
        {
            return 'tenant_items';
        }
    };

    $scoped = $repository->forTenant(1);

    expect(fn() => $scoped->upsert(
        ['id' => 7, 'value' => 'changed-by-tenant-one'],
        ['id'],
        ['value'],
    ))->toThrow(InvalidArgumentException::class, 'requires the tenant column')
        ->and(fn() => $scoped->upsert(
            ['id' => 7, 'value' => 'changed-by-tenant-one'],
            ['tenant_id', 'id'],
            ['tenant_id', 'value'],
        ))->toThrow(InvalidArgumentException::class, 'cannot update the tenant column');

    try {
        $scoped->upsert(
            ['id' => 7, 'value' => 'changed-by-tenant-one'],
            ['tenant_id', 'id'],
            ['value'],
        );
    } catch (QueryException) {
        // SQLite rejects the unrelated primary-key conflict rather than
        // updating the foreign tenant row. That is the required safe outcome.
    }

    expect($connection->table('tenant_items')->where('id', '=', 7)->first())
        ->toBe(['id' => 7, 'tenant_id' => 2, 'value' => 'tenant-two']);

    $mysql = new Connection(ConnectionConfig::fromArray([
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'database' => 'unused',
        'username' => 'unused',
        'password' => 'unused',
    ]), 'mysql-policy');
    $mysqlRepository = new class($mysql) extends ConnectionRepository {
        protected function table(): string
        {
            return 'tenant_items';
        }
    };

    expect(fn() => $mysqlRepository->forTenant(1)->upsert(
        ['id' => 7, 'value' => 'unsafe'],
        ['tenant_id', 'id'],
        ['value'],
    ))->toThrow(InvalidArgumentException::class, 'not supported on MySQL/MariaDB');
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

    $firstTableTag = $first->cacheTableTag('items');
    $firstRecordTag = $first->cacheTableTag('items', 'id.i:1');

    expect($first->cacheScopeFingerprint())
        ->not->toBe($second->cacheScopeFingerprint())
        ->and($firstTableTag)->not->toBe($second->cacheTableTag('items'))
        ->and($firstRecordTag)->not->toBe($firstTableTag)
        ->and(strlen($firstTableTag))->toBeLessThanOrEqual(64)
        ->and(strlen($firstRecordTag))->toBeLessThanOrEqual(64);
});

it('shares dependency tags across visibility scopes while isolating result identities', function (): void {
    $first = new Connection(ConnectionConfig::fromArray([
        'driver' => 'pgsql',
        'host' => 'localhost',
        'database' => 'app',
        'username' => 'role_a',
        'password' => 'unused',
        'schema' => 'public',
        'cache_scope' => 'rls:a',
    ]), 'main');
    $second = new Connection(ConnectionConfig::fromArray([
        'driver' => 'pgsql',
        'host' => 'localhost',
        'database' => 'app',
        'username' => 'role_b',
        'password' => 'unused',
        'schema' => 'public',
        'cache_scope' => 'rls:b',
    ]), 'main');

    expect($first->cacheScopeFingerprint())->not->toBe($second->cacheScopeFingerprint())
        ->and($first->cacheDependencyFingerprint())->toBe($second->cacheDependencyFingerprint())
        ->and($first->cacheTableTag('items'))->toBe($second->cacheTableTag('items'));

    $isolated = new Connection(ConnectionConfig::fromArray([
        'driver' => 'pgsql',
        'host' => 'localhost',
        'database' => 'app',
        'username' => 'role_b',
        'password' => 'unused',
        'schema' => 'other',
        'cache_scope' => 'rls:b',
    ]), 'main');

    expect($first->cacheTableTag('items'))->not->toBe($isolated->cacheTableTag('items'));
});

it('invalidates shared table data across explicit cache visibility scopes', function (): void {
    $database = tempnam(sys_get_temp_dir(), 'dblayer-batch-a-scope-');
    expect($database)->not->toBeFalse();

    $reader = dblayerBatchAConnection([
        'database' => $database,
        'cache_scope' => 'reader',
    ]);
    $writer = dblayerBatchAConnection([
        'database' => $database,
        'cache_scope' => 'writer',
    ]);
    $cache = Cache::memory('dblayer-batch-a-cross-scope');
    $reader->setQueryCache($cache);
    $writer->setQueryCache($cache);

    try {
        $reader->statement('create table items (id integer primary key, tenant_id integer, value text)');
        $reader->insert('insert into items (id, tenant_id, value) values (?, ?, ?)', [1, 1, 'old']);
        $query = $reader->table('items')->where('id', '=', 1)->cacheFor(60);

        expect($query->get()[0]['value'])->toBe('old');

        $writer->table('items')->where('id', '=', 1)->update(['value' => 'new']);

        expect($query->get()[0]['value'])->toBe('new');
    } finally {
        $reader->disconnect();
        $writer->disconnect();

        if (is_string($database) && is_file($database)) {
            unlink($database);
        }
    }
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

it('rejects cache-aware DBLayer writes inside externally owned native transactions before mutation', function (): void {
    $connection = dblayerBatchAPopulatedConnection();
    $connection->setQueryCache(Cache::memory('dblayer-batch-a-native-write-policy'));
    $pdo = $connection->getPdo();
    $pdo->beginTransaction();

    try {
        expect(fn() => $connection->table('items')->where('id', '=', 1)->update(['value' => 'blocked']))
            ->toThrow(ConnectionException::class, 'externally owned native PDO transaction')
            ->and($connection->table('items')->where('id', '=', 1)->first()['value'])->toBe('original');
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
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

    $connection->statement(
        'create trigger batch_a_update after update on items '
        . 'begin select length(randomblob(2000000)); end',
    );

    $affected = $connection->withQueryTimeoutMs(
        1,
        fn(): int => $connection->table('items')->where('id', '=', 1)->update(['value' => 'committed']),
    );

    expect($affected)->toBe(1)
        ->and($connection->hasStickyWrite())->toBeTrue()
        ->and($query->get()[0]['value'])->toBe('committed');
});
