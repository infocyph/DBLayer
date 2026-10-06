<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\CacheLayer\Cache\Cache;

function dblayerSchemaCacheConnection(string $schema, string $deployment = ''): Connection
{
    return new Connection(ConnectionConfig::fromArray([
        'driver' => 'pgsql',
        'database' => 'shared',
        'username' => 'test',
        'host' => '127.0.0.1',
        'schema' => $schema,
        'cache_dependency_scope' => $deployment === '' ? null : $deployment,
    ]), 'shared');
}

it('shares qualified physical dependencies across default schemas while isolating result visibility', function (): void {
    $first = dblayerSchemaCacheConnection('tenant_a');
    $second = dblayerSchemaCacheConnection('tenant_b');

    expect($first->cacheTableTag('public.items'))->toBe($second->cacheTableTag('public.items'))
        ->and($first->cacheScopeFingerprint())->not->toBe($second->cacheScopeFingerprint())
        ->and($first->cacheTableTag('items'))->toBe($second->cacheTableTag('tenant_a.items'))
        ->and($first->cacheTableTag('items'))->not->toBe($second->cacheTableTag('items'));
});

it('preserves explicit deployment isolation for qualified tables', function (): void {
    $first = dblayerSchemaCacheConnection('tenant_a', 'deployment-a');
    $second = dblayerSchemaCacheConnection('tenant_b', 'deployment-b');

    expect($first->cacheTableTag('public.items'))->not->toBe($second->cacheTableTag('public.items'));
});

it('does not reuse warm result entries carrying the previous dependency identity', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'dblayer-cache-upgrade-');
    $config = ConnectionConfig::fromArray(['driver' => 'sqlite', 'database' => $file]);
    $legacy = new Connection($config, 'cache-upgrade');
    // Seed the previous release's immutable identities to reproduce a warm external cache.
    new ReflectionProperty(Connection::class, 'cacheScopeFingerprintMemo')->setValue($legacy, substr(
        hash('sha256', implode("\0", ['v2', 'cache-upgrade', 'sqlite', $file, '', '', '', ''])),
        0,
        32,
    ));
    new ReflectionProperty(Connection::class, 'cacheDependencyFingerprintMemo')->setValue($legacy, substr(
        hash('sha256', implode("\0", ['v1', 'cache-upgrade', 'sqlite', $file, '', '', ''])),
        0,
        32,
    ));
    $current = new Connection($config, 'cache-upgrade');
    $cache = Cache::memory('cache-upgrade-' . bin2hex(random_bytes(4)));
    $legacy->setQueryCache($cache);
    $current->setQueryCache($cache);
    try {
        $legacy->statement('create table items (id integer primary key, value text)');
        $legacy->table('items')->insert(['id' => 1, 'value' => 'old']);
        expect($legacy->table('items')->cacheFor(60)->first()['value'])->toBe('old');
        $current->table('items')->where('id', '=', 1)->update(['value' => 'new']);

        expect($legacy->table('items')->cacheFor(60)->first()['value'])->toBe('old')
            ->and($current->table('items')->cacheFor(60)->first()['value'])->toBe('new');
    } finally {
        $legacy->disconnect();
        $current->disconnect();
        unlink($file);
    }
});
