# Reproduce the updated-code review

Candidate: `a33cbe7b955a10add97170592394f101e4dfefce`. Run from the repository
root with its declared development dependencies installed and PDO SQLite enabled.
Save the fenced PHP as `/tmp/dblayer-updated-review.php`, then run
`php /tmp/dblayer-updated-review.php`. All databases are private SQLite databases;
file-backed probes remove their temporary files. Warmup expiry uses Reflection
to age idle timestamps deterministically. No external database is contacted.

The JSONL records capture current incorrect outcomes; they are not assertions
that these behaviors should be preserved. See the
[consolidated feedback](../dblayer-6.0-audit-and-runwire.md#open-findings) for
expected behavior and required real-server verification.

```php
<?php
require getcwd() . '/vendor/autoload.php';
use Infocyph\DBLayer\Connection\{Connection,ConnectionConfig,Pool,PoolManager};
use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\Runwire\{RuntimeContext,RequestContext};
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
function probe(string $name, callable $work): void {
    try { $out = $work(); } catch (Throwable $e) { $out = ['exception' => get_class($e), 'message' => $e->getMessage()]; }
    echo json_encode(['probe' => $name, 'result' => $out], JSON_THROW_ON_ERROR), PHP_EOL;
}
function conn(string $db=':memory:', array $extra=[]): Connection { return new Connection(new ConnectionConfig(['driver'=>'sqlite','database'=>$db]+$extra),'main'); }
function seed(Connection $c): void { $c->statement('create table items (id integer primary key, value text)'); $c->insert('insert into items values (1, ?)', ['old']); $c->insert('insert into items values (2, ?)', ['two']); $c->insert('insert into items values (3, ?)', ['three']); }
probe('native_transaction_refill_before_commit', function () {
    $db=tempnam('/tmp','dblayer-review-native-'); $a=conn($db); $b=conn($db); seed($a);
    $cache=Cache::memory('review-native'); $a->setQueryCache($cache); $b->setQueryCache($cache);
    $query=$b->table('items')->where('id',1)->cacheFor(60); $query->get();
    try {
        $a->getPdo()->beginTransaction(); $a->table('items')->where('id',1)->update(['value'=>'committed']);
        $during=$query->get()[0]['value']; $a->getPdo()->commit();
        return ['reader_during_transaction'=>$during,'cached_after_commit'=>$query->get()[0]['value'],'uncached_after_commit'=>$b->table('items')->where('id',1)->get()[0]['value']];
    } finally { $a->disconnect();$b->disconnect();unlink($db); }
});
probe('cross_security_scope_write_invalidation', function () {
    $db=tempnam('/tmp','dblayer-review-scope-');$a=conn($db,['cache_scope'=>'reader']);$b=conn($db,['cache_scope'=>'writer']);seed($a);
    $cache=Cache::memory('review-scope');$a->setQueryCache($cache);$b->setQueryCache($cache);$q=$a->table('items')->where('id',1)->cacheFor(60);$q->get();
    try { $b->table('items')->where('id',1)->update(['value'=>'new']);return ['cached_after_write'=>$q->get()[0]['value'],'uncached_after_write'=>$a->table('items')->where('id',1)->get()[0]['value']]; }
    finally {$a->disconnect();$b->disconnect();unlink($db);}
});
probe('lazyById_cancel_between_buffered_rows', function () {
    $c=conn();seed($c);$r=RuntimeContext::standalone();$request=RequestContext::create($r);
    $g=$c->withRunwire($r,fn()=>$c->table('items')->lazyById(chunkSize:3),$request);$g->rewind();$first=$g->current()['id'];$request->cancel(CancellationReason::HOST_CANCELLED);$g->next();return ['first'=>$first,'row_after_cancellation'=>$g->current()['id']];
});
probe('stream_created_under_binding_consumed_after_request_complete', function () {
    $c=conn();seed($c);$r=RuntimeContext::standalone();$request=RequestContext::create($r);
    $g=$c->withRunwire($r,fn()=>$c->stream('select * from items'),$request);$request->complete();return ['row_count'=>count(iterator_to_array($g))];
});
probe('lease_release_with_live_stream', function () {
    $pool=new Pool(['min_connections'=>0,'max_connections'=>1]);$pool->addConfig('main',new ConnectionConfig(['driver'=>'sqlite','database'=>':memory:']));$m=new PoolManager($pool);$lease=$m->checkout('main');$c=$lease->connection();seed($c);$g=$c->stream('select * from items');$g->rewind();$lease->release();$next=$m->checkout('main');
    try {$g->next();return ['same_wrapper'=>$next->connection()===$c,'old_stream_row'=>$g->current()['id'],'new_borrower_scalar'=>$next->connection()->scalar('select 42')];} finally {unset($g);$next->release();$pool->closeAll();}
});
probe('warmup_counts_expired_idle_as_ready', function () {
    $p=new Pool(['min_connections'=>1,'max_connections'=>1,'idle_timeout'=>1]);$p->addConfig('main',new ConnectionConfig(['driver'=>'sqlite','database'=>':memory:']));$m=new PoolManager($p);$m->warmUp('main');$l=$m->checkout('main');$old=$l->connection()->getPdo();$l->release();
    $rp=new ReflectionProperty($p,'idle');$idle=$rp->getValue($p);foreach($idle['main'] as &$data){$data['idle_since']=microtime(true)-2;}unset($data);$rp->setValue($p,$idle);
    $ready=$m->warmUp('main');$l=$m->checkout('main');try{return ['warmup_reported_ready'=>$ready,'checkout_already_open'=>$l->connection()->isConnected(),'same_pdo_after_checkout'=>$l->connection()->getPdo()===$old];}finally{$l->release();$p->closeAll();}
});
probe('tenant_scoped_upsert_conflict_updates_other_tenant', function () {
    $c=conn();$c->statement('create table tenant_items (id integer primary key, tenant_id integer, value text)');$c->insert('insert into tenant_items values (7, 2, ?)', ['tenant-two']);
    $repo=new class($c) extends Infocyph\DBLayer\Query\ConnectionRepository { protected function table(): string {return 'tenant_items';}};
    $result=$repo->forTenant(1)->upsert(['id'=>7,'value'=>'changed-by-tenant-one'], ['id'], ['value']);
    return ['accepted'=>$result,'row'=>$c->table('tenant_items')->where('id',7)->get()[0]];
});
probe('tenant_scoped_upsert_default_updates_tenant_identity', function () {
    $c=conn();$c->statement('create table tenant_items (id integer primary key, tenant_id integer, value text)');$c->insert('insert into tenant_items values (7, 2, ?)', ['tenant-two']);
    $repo=new class($c) extends Infocyph\DBLayer\Query\ConnectionRepository { protected function table(): string {return 'tenant_items';}};
    $repo->forTenant(1)->upsert(['id'=>7,'value'=>'taken-by-tenant-one'], ['id']);
    return ['row'=>$c->table('tenant_items')->where('id',7)->get()[0]];
});
```
