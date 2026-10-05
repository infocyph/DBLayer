# Reproducing the 2026-10-05 audit probes

Historical baseline: `087f179ecac3e5555c346ce84cfc353050f8e3cb` plus the
dependency-floor changes. The recorded outcomes predate remediation. See the
[consolidated feedback](../dblayer-6.0-audit-and-runwire.md) for current statuses;
running this harness on a newer revision may produce different outcomes.

Run from the DBLayer repository root with its installed Composer dependencies.
Copy the PHP block into `/tmp/dblayer-audit-probes.php`, then run
`php /tmp/dblayer-audit-probes.php`. These diagnostic probes use private SQLite
in-memory databases. The PostgreSQL cache-identity probe derives keys without
connecting to a server. They print current behavior and deliberately expose
unfixed defects; they are not a passing regression suite. The recorded output
used PHP 8.5.4, ArrayKit 5.3 and CacheLayer 4.0.

The harness is documentation so diagnostic failures do not masquerade as
passing library tests. Implement permanent failing-before/passing-after tests
in the normal suite for each remediation. Reflection is limited to key/store
inspection and never changes production state.

```php
<?php
declare(strict_types=1);
require getcwd() . '/vendor/autoload.php';

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\DBLayer\Connection\Pool;
use Infocyph\DBLayer\Connection\PoolManager;
use Infocyph\CacheLayer\Cache\Cache;

function connection(array $extra = []): Connection {
    return new Connection(ConnectionConfig::fromArray(array_replace(['driver'=>'sqlite', 'database'=>':memory:'], $extra)), 'main');
}
function populated(array $extra = []): Connection {
    $c = connection($extra);
    $c->statement('create table items (id integer primary key, value text)');
    $c->insert('insert into items (id,value) values (?,?)', [1,'original']);
    $c->resetRuntimeStateForReuse();
    return $c;
}
function observe(string $name, callable $probe): void {
    try { $result = $probe(); } catch (Throwable $e) { $result=['probe_error'=>get_class($e), 'message'=>$e->getMessage()]; }
    echo json_encode(['probe'=>$name,'observed'=>$result], JSON_THROW_ON_ERROR), PHP_EOL;
}

observe('cached_result_ignores_cancellation', function() {
    $c=populated(); $q=$c->table('items')->cacheFor(60); $q->get();
    return $c->withQueryCancellation(fn()=>true, fn()=>$q->get());
});
observe('cached_result_ignores_expired_deadline', function() {
    $c=populated(); $q=$c->table('items')->cacheFor(60); $q->get();
    $c->setQueryDeadlineAt(microtime(true)-1);
    return $q->get();
});
observe('nested_cancellation_replaces_parent', function() {
    $c=populated();
    return $c->withQueryCancellation(fn()=>true, fn()=>$c->withQueryCancellation(fn()=>false, fn()=>$c->select('select 1')));
});
observe('native_transaction_reads_cached_committed_data', function() {
    $c=populated(); $q=$c->table('items')->cacheFor(60); $q->get();
    $pdo=$c->getPdo(); $pdo->beginTransaction(); $pdo->exec("update items set value = 'uncommitted'");
    $cached=$q->get(); $actual=$c->select('select * from items'); $pdo->rollBack();
    return ['cached'=>$cached,'actual'=>$actual];
});
observe('native_transaction_publishes_rolled_back_rows', function() {
    $c=populated(); $pdo=$c->getPdo(); $pdo->beginTransaction(); $pdo->exec("update items set value = 'uncommitted'");
    $q=$c->table('items')->cacheFor(60); $before=$q->get(); $pdo->rollBack();
    return ['inside'=>$before,'after_rollback_cached'=>$q->get(),'actual'=>$c->select('select * from items')];
});
observe('statement_cache_closes_active_stream', function() {
    $c=populated(['statement_cache_enabled'=>true]); $c->insert('insert into items (id,value) values (?,?)',[2,'second']);
    $stream=$c->stream('select * from items order by id'); $stream->rewind(); $first=$stream->current();
    $c->select('select * from items order by id'); $stream->next();
    return ['first'=>$first,'has_second'=>$stream->valid(),'second'=>$stream->current()];
});
observe('scope_returns_generator_after_lease_release', function() {
    $pool=new Pool(['min_connections'=>0,'max_connections'=>1]);
    $pool->addConfig('main', ConnectionConfig::fromArray(['driver'=>'sqlite','database'=>':memory:']));
    $manager=new PoolManager($pool); $old=null;
    $generator=$manager->using('main',function($c) use (&$old) {$old=$c; return $c->stream('select 1');});
    $lease=$manager->checkout('main');
    $same=$old===$lease->connection(); $generator->rewind(); $row=$generator->current(); $lease->release();
    return ['same_connection_borrowed_again'=>$same,'old_generator_can_execute'=>$row];
});
observe('cache_key_omits_schema_and_role', function() {
    $a=new Connection(ConnectionConfig::fromArray(['driver'=>'pgsql','database'=>'app','schema'=>'tenant_a','username'=>'role_a']), 'main');
    $b=new Connection(ConnectionConfig::fromArray(['driver'=>'pgsql','database'=>'app','schema'=>'tenant_b','username'=>'role_b']), 'main');
    $qa=$a->table('items'); $qb=$b->table('items');
    $key=new ReflectionMethod($qa,'resultCacheKey');
    return ['same_sql'=>$qa->toSql()===$qb->toSql(), 'same_cache_key'=>$key->invoke($qa,$qa->toSql(),'binding')===$key->invoke($qb,$qb->toSql(),'binding'), 'same_table_tag'=>$a->cacheTableTag('items')===$b->cacheTableTag('items'), 'evidence'=>'key derivation only; PostgreSQL servers were not contacted'];
});
observe('like_escape_changes_semantics', function() {
    $pattern=Infocyph\DBLayer\Security\Security::sanitizeLikePattern('a%b');
    $statement=connection()->getPdo()->prepare('select ? like ? escape ?');
    $statement->execute(['a%b',$pattern,'\\']);
    return ['pattern'=>$pattern,'literal_matches'=>$statement->fetchColumn()];
});
observe('pool_reset_leaves_native_timeout', function() {
    $c=populated(); $c->setQueryTimeoutMs(123); $before=$c->getPdo()->query('pragma busy_timeout')->fetchColumn();
    $c->resetRuntimeStateForReuse();
    return ['before'=>$before, 'wrapper_after'=>$c->getQueryTimeoutMs(),'native_after'=>$c->getPdo()->query('pragma busy_timeout')->fetchColumn()];
});
observe('schema_drop_keeps_instance_cache', function() {
    Infocyph\DBLayer\DB::purge();
    $c=populated(); $q=$c->table('items')->cacheFor(60); $q->get();
    (new Infocyph\DBLayer\Schema\SchemaManager($c))->drop('items');
    $c->resetRuntimeStateForReuse();
    return $q->get();
});
observe('aggregate_bypasses_raw_sql_deny', function() {
    $c=populated(['security'=>['raw_sql_policy'=>'deny']]);
    return $c->table('items')->aggregate('MAX(42) FROM items --');
});
observe('committed_write_times_out_without_cache_invalidation', function() {
    $c=populated(); $q=$c->table('items')->cacheFor(60); $q->get();
    $c->getPdo()->sqliteCreateFunction('audit_delay', function() {usleep(5000); return 1;});
    $c->getPdo()->exec('create trigger audit_update after update on items begin select audit_delay(); end');
    $error=null;
    try { $c->withQueryTimeoutMs(1,fn()=>$c->table('items')->update(['value'=>'committed'])); }
    catch (Throwable $e) { $error=$e->getMessage(); }
    return ['error'=>$error,'sticky'=>$c->hasStickyWrite(),'cached'=>$q->get(),'actual'=>$c->select('select * from items')];
});
observe('tenant_scope_accepts_other_tenant_payload', function() {
    $c=connection(); $c->statement('create table items (id integer primary key, tenant_id integer, value text)');
    $repo=new class($c) extends Infocyph\DBLayer\Query\ConnectionRepository {protected function table(): string {return 'items';}};
    $repo->forTenant(1)->create(['id'=>1,'tenant_id'=>2,'value'=>'cross-tenant']);
    return $c->select('select * from items');
});
observe('expired_private_cache_retains_distinct_keys', function() {
    $c=populated();
    for($i=0;$i<100;$i++) {$c->table('items')->cacheFor(1)->cacheKey('unique.'.$i)->get();}
    usleep(1100000);
    $cache=$c->queryCache();
    $adapter=(new ReflectionProperty($cache,'adapter'))->getValue($cache);
    return ['expired_entries_retained'=>count((new ReflectionProperty($adapter,'store'))->getValue($adapter)), 'capacity_configured'=>false];
});

```
