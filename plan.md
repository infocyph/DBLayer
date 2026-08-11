# DBLayer — Final Modernization & Major Upgrade Draft

## 1. Objective

Modernize DBLayer into a leaner, faster, more internally consistent database layer while fully aligning it with:

```json
{
    "php": "^8.4",
    "ext-pdo": "*",
    "infocyph/arraykit": "^5.0",
    "infocyph/cachelayer": "^3.0",
    "psr/log": "^3.0.2"
}
```

Development dependency remains unchanged:

```json
{
    "infocyph/phpforge": "dev-main@dev"
}
```

The current uploaded DBLayer snapshot still targets ArrayKit `^4.6.1` and CacheLayer `^2.0.1`; it already contains the newer typed `CompiledQuery` execution path, read strategies including `weighted`, schema/migrations, relation loading, cursor signing, statement caching, telemetry, pooling, and other advanced functionality.

This upgrade is therefore **not a feature rewrite**. The main goals are:

- align DBLayer with ArrayKit 5.0 and CacheLayer 3.0;
- remove duplicated generic functionality now owned by those libraries;
- make the typed compiled-query path the canonical execution path;
- introduce proper CacheLayer-powered query caching and invalidation;
- reduce unnecessary hot-path validation, allocations, dispatch and normalization;
- improve cross-driver correctness;
- keep optional systems lazy and out of ordinary query paths;
- preserve DBLayer as a database layer rather than growing it into an ORM or application framework.

This should be treated as a **DBLayer major modernization release**. Do not retain compatibility code solely for ArrayKit 4.x or CacheLayer 2.x.

---

# 2. Architectural Boundary

Keep the current three-layer architecture.

```text
DB
│
├── infrastructure/orchestration
├── connection lifecycle
├── transactions
├── execution controls
├── caching integration
├── observability
├── pooling
├── schema
└── runtime state

QueryBuilder
│
├── SQL composition
├── clauses
├── bindings
├── joins/subqueries
├── CTEs
├── pagination
├── locking
└── compilation

Repository
│
├── table policy
├── tenant scope
├── soft deletes
├── optimistic locking
├── casts
├── hooks
├── default ordering
└── cache policy
```

The uploaded implementation already documents this responsibility split and should continue using it.

Do **not** collapse these three layers.

Also retain the explicit optional modules:

```text
RelationLoader
SchemaManager
MigrationRunner
SeedRunner
PoolManager
Telemetry
Profiler
Logger
```

They must remain outside normal query construction until explicitly requested.

---

# 3. ArrayKit 5.0 Alignment

ArrayKit 5 owns generic array manipulation, dot notation, `Collection`, `HookedCollection`, `LazyCollection`, pipelines, array-shape validation, configuration utilities and namespaced helpers.

DBLayer must stop maintaining parallel generic implementations.

## 3.1 Remove DBLayer Collection duplication

Current DBLayer contains:

```php
final class Collection extends ArrayKitCollection
```

while reimplementing functionality such as:

```text
avg
chunk
contains
filter
first
grouping
mapping
sorting
...
```

The class explicitly exists to preserve the old DBLayer API over ArrayKit. 

Remove this compatibility collection.

Use directly:

```php
use Infocyph\ArrayKit\Collection\Collection;
```

DBLayer must not own generic collection semantics.

### Result behavior

Keep arrays as the fastest normal database result representation.

```php
$rows = DB::table('users')->get();
```

should remain an array-based hot path.

Where collection semantics are explicitly wanted, expose:

```php
$users = DB::table('users')->collect();
```

returning the **ArrayKit 5 Collection directly**.

Repository APIs that intentionally return collections should likewise return ArrayKit's Collection rather than `Infocyph\DBLayer\Support\Collection`.

This removes:

- duplicated code;
- duplicate maintenance;
- wrapper allocations;
- semantic drift between DBLayer and ArrayKit.

---

# 4. ArrayKit LazyCollection Integration

Do not change the database iteration primitives:

```text
cursor()
stream()
unbufferedStream()
lazy()
lazyById()
chunk()
chunkById()
```

DBLayer must continue owning these because they determine:

- SQL execution strategy;
- PDO lifetime;
- connection occupancy;
- keyset pagination;
- buffering;
- query boundaries.

ArrayKit 5's `LazyCollection` should instead become an optional transformation layer over those primitives. ArrayKit explicitly provides generator-backed lazy mapping/filtering/chunking.

Add:

```php
DB::table('events')
    ->lazyCollection(1000)
    ->filterLazy(...)
    ->mapLazy(...)
    ->take(100);
```

Recommended underlying source:

```text
lazyCollection()
    ↓
lazyById()/generator
    ↓
ArrayKit LazyCollection
```

DBLayer controls **how data leaves the database**.

ArrayKit controls **what happens to the data afterward**.

Do not put LazyCollection into `get()` or ordinary query execution.

---

# 5. ArrayKit ArrayShape Integration

ArrayKit 5 provides lightweight array-shape validation.

Use it selectively for **cold-path structural validation**, particularly:

```text
Connection configuration
Replica descriptors
Pool configuration
Security configuration
Query-comment configuration
Migration/seed descriptors where arrays are accepted
External structured configuration
```

Do not repeatedly execute generalized ArrayShape validation in the query hot path.

Preferred flow:

```text
input array
    ↓
ArrayShape / structural validation
    ↓
DBLayer semantic validation
    ↓
ConnectionConfig normalization
    ↓
runtime-ready immutable values
```

`ConnectionConfig` remains the authoritative database-specific configuration object.

Do **not** replace it with ArrayKit `Config`.

ArrayKit handles generic structure.

DBLayer handles:

```text
driver rules
TLS semantics
PDO options
read/write topology
security policy
database invariants
```

---

# 6. Do Not Replace Small Native Operations Just to Use ArrayKit

Do not force ArrayKit into every array operation.

For example, the existing:

```php
ArrayNormalizer::stringKeyArray()
```

is a tiny specialized loop.

If a native PHP loop or PHP 8.4 function is simpler and faster, keep it.

Prefer native:

```text
array_any()
array_find()
array_find_key()
array_map()
array_filter()
array_column()
```

where appropriate.

The rule is:

```text
generic substantial reusable behavior → ArrayKit
tiny database-specific hot-path operation → native PHP
```

Integration should reduce code and cost, not increase abstraction.

---

# 7. Remove Generic DBLayer Helpers

The current package autoloads:

```json
"files": [
    "src/helpers.php"
]
```

and documents generic helpers alongside database helpers.

ArrayKit 5 already provides namespaced helpers by default and optional globals separately.

DBLayer should no longer own generic helpers such as:

```text
collect()
data_get()
data_set()
retry()
rescue()
blank()
filled()
value()
tap()
with()
now()
```

Best final design: remove DBLayer's global helper autoload entirely.

Use:

```php
DB::table(...)
DB::transaction(...)
DB::select(...)
```

directly.

This avoids:

- global namespace pollution;
- Composer autoload-file execution;
- function ownership conflicts;
- duplicate ArrayKit behavior.

If any DB-specific helper absolutely needs to survive, it must be genuinely database-specific. However, the preferred final state is **no DBLayer global helpers**.

---

# 8. CacheLayer 3.0 Alignment

CacheLayer 3 already owns:

- unified PSR-6/PSR-16 caching;
- memory/APCu/file/PDO/Redis/Valkey/Memcached/etc. adapters;
- tiered cache composition;
- versioned tags;
- stampede-safe `remember()`;
- pluggable locks;
- metrics;
- payload compression;
- integrity controls;
- serialization controls.

DBLayer must **use these directly rather than building another caching framework**.

CacheLayer 3 retains:

```php
Cache::memory(...)
Cache::pdo(...)
Cache::sqlite(...)
Cache::redis(...)
Cache::valkey(...)
Cache::tiered(...)
```

including a lightweight array-backed `Cache::memory()` implementation.

---

# 9. Keep Lazy Default Cache

DBLayer should continue lazy cache initialization.

Normal database-only usage must not initialize:

```text
Redis
Valkey
SQLite cache
filesystem cache
APCu
tiered cache
lock infrastructure
```

unless caching is actually requested.

Default:

```php
DB::cache();
```

may continue lazily resolving:

```php
Cache::memory('dblayer');
```

which remains available in CacheLayer 3.

This preserves a near-zero cache cost for applications that do not use caching.

---

# 10. Simplify DB Cache API

Use an explicit configuration API:

```php
DB::cache(): CacheInterface;

DB::setCache(CacheInterface $cache): void;
```

rather than overloading the getter as the primary setter.

Allow applications to configure any CacheLayer 3 topology:

```php
DB::setCache(
    Cache::tiered([
        ...,
    ]),
);
```

CacheLayer 3 already supports tiered L1/L2 composition itself.

Remove DBLayer-specific adapter shortcuts where they add no database semantics.

In particular, deprecate/remove:

```php
DB::useFileCache(...)
```

and use:

```php
DB::setCache(Cache::file(...));
```

or the appropriate CacheLayer 3 factory.

DBLayer should not privilege one CacheLayer adapter.

---

# 11. Implement Real Query Result Caching

DBLayer currently exposes CacheLayer, but the database layer itself should now provide a proper optional query-cache policy.

Add to QueryBuilder:

```php
$query
    ->cacheFor(120)
    ->cacheTags(['users'])
    ->get();
```

Optional explicit key:

```php
$query
    ->cacheKey('dashboard.active-users')
    ->cacheFor(120)
    ->get();
```

Recommended methods:

```text
cacheFor(int|DateInterval|null $ttl)
cacheTags(array|string ...$tags)
cacheKey(?string $key)
withoutCache()
```

Caching remains **strictly opt-in**.

Never automatically cache ordinary:

```php
->get()
```

calls.

---

# 12. CacheLayer 3 `remember()` Must Power Query Cache Misses

Do not implement custom:

```php
if (!$cache->has($key)) {
    $cache->set(...);
}
```

logic.

CacheLayer 3 already provides stampede-safe:

```php
remember(
    string $key,
    callable $resolver,
    mixed $ttl = null,
    array $tags = [],
)
```

and supports pluggable lock providers.

DBLayer query caching should therefore reduce to:

```text
build cache identity
      ↓
CacheLayer::remember()
      ↓
execute DB query on miss
```

This automatically avoids large bursts of identical SQL after cache expiry.

---

# 13. Query Cache Identity

Automatic keys must be deterministic and database-aware.

Key material should contain:

```text
logical connection name
driver
database identity
query type/result mode
compiled SQL fingerprint
bindings fingerprint
```

Conceptually:

```text
dblayer:{connection}:{databaseHash}:{queryHash}
```

Use a fast non-cryptographic hash such as `xxh3` for internal deterministic identifiers.

Do not include:

```text
password
username
TLS key
full connection configuration
physical replica index
```

in the key.

Bindings need a deterministic lightweight encoding.

Cacheable bindings should normally be limited to cache-key-safe values:

```text
null
bool
int
float
string
stringable values explicitly normalized
```

Queries containing stream/resource bindings must bypass result caching.

Do not use a heavy serializer merely to calculate every query key.

---

# 14. Query Cache Tags

Use CacheLayer 3's versioned tags directly. CacheLayer provides:

```text
setTagged()
invalidateTag()
invalidateTags()
remember(... tags: ...)
```

as native behavior.

Builder-generated queries should automatically receive conservative table tags:

```text
table:users
table:orders
```

Joined query:

```text
users + orders
```

gets:

```text
table:users
table:orders
```

Repository queries may add more precise tags:

```text
table:users
table:users:id:42
tenant:10:users
```

Do not attempt to parse arbitrary raw SQL to determine tags.

Raw SQL caching requires explicit tags.

---

# 15. Transaction-Safe Cache Invalidation

DBLayer already provides robust `afterCommit()` semantics.

All automatic cache invalidation caused by writes must use it.

Correct:

```text
UPDATE
   ↓
schedule tags for invalidation
   ↓
COMMIT
   ↓
CacheLayer::invalidateTags()
```

Incorrect:

```text
UPDATE
   ↓
invalidate immediately
   ↓
ROLLBACK
```

Automatic invalidation must therefore be:

```php
DB::afterCommit(
    static fn () => DB::cache()->invalidateTags($tags),
);
```

Nested savepoint rollback must discard invalidations registered within the rolled-back scope.

Transaction retry must discard callbacks from failed attempts.

The current transaction architecture already provides the foundation for this behavior.

---

# 16. Cache Consistency Rules

Shared query result caching must be bypassed automatically when:

```text
an explicit transaction is active
query uses FOR UPDATE
query uses a shared/write-sensitive lock
query is streaming
query is unbuffered
query uses cursor()
query uses lazyById()
query uses chunkById()
query contains unsupported bindings
query is an unsafe/raw shape without explicit cache metadata
```

Also bypass query-result cache while the connection is in **sticky read-after-write mode**.

Otherwise:

```text
write
→ sticky primary read
→ old cached result
```

would defeat sticky consistency.

Recommended behavior:

```text
write occurs
    ↓
sticky runtime state enabled
    ↓
shared query cache bypassed
    ↓
afterCommit invalidation
    ↓
runtime/request reset
    ↓
normal caching resumes
```

---

# 17. Do Not Mirror CacheLayer Security Configuration

CacheLayer 3 owns:

```php
configurePayloadCompression()
configurePayloadSecurity()
configureSerializationSecurity()
```

including integrity keys, payload limits and closure/object serialization policy.

Do not add equivalents to:

```text
ConnectionConfig
DBLayer security config
Repository config
```

Applications should configure CacheLayer itself:

```php
$cache
    ->configurePayloadSecurity(...)
    ->configureSerializationSecurity(...);

DB::setCache($cache);
```

DBLayer owns database security.

CacheLayer owns cached-payload security.

---

# 18. Migration Locking — Align Directly with CacheLayer 3

Keep the current architecture.

DBLayer's MigrationRunner already consumes CacheLayer lock abstractions directly, and its tests already cover lock acquisition, lease loss and guaranteed release. 

CacheLayer's current lock module exposes:

```text
LockProviderInterface
LockHandle
FileLockProvider
PdoLockProvider
RedisLockProvider
MemcachedLockProvider
```

directly.

Do not create:

```text
DBLayerLockInterface
MigrationLockManager
DBLockProvider
```

Update imports/signatures to CacheLayer 3 where required and retain direct dependency injection.

Preserve:

```text
acquire
refresh/checkpoint
release in finally
```

behavior.

Add/retain tests for:

```text
lock acquisition
contention
wait timeout
lease expiration
refresh failure
migration exception
rollback exception
release exactly once
long-running checkpoint
```

---

# 19. Canonical Compiled Query Pipeline

The uploaded DBLayer already contains:

```php
CompiledQuery
QueryType
Connection::runCompiled()
```

and explicitly labels it the new typed execution pipeline. 

Make this the **single canonical execution architecture**.

Final flow:

```text
QueryBuilder
    ↓
QueryPayload
    ↓
Grammar / Compiler
    ↓
CompiledQuery
    ↓
Connection::runCompiled()
    ↓
PDO/Driver
    ↓
result
```

Raw execution:

```text
DB::select()/statement()/...
    ↓
normalize raw query
    ↓
typed/raw execution metadata
    ↓
same Connection execution core
```

Remove obsolete alternate execution paths once all functionality has moved to this model.

The source still contains internal methods explicitly marked `legacy`; those paths should not survive indefinitely solely for transition convenience.

---

# 20. Extend CompiledQuery with Query Provenance

DBLayer currently knows `QueryType`, but generated SQL and arbitrary raw SQL still receive similar downstream security treatment.

Add lightweight provenance metadata.

Recommended:

```php
enum SqlOrigin
{
    case BUILDER;
    case RAW;
    case INTERNAL;
    case SCHEMA;
}
```

Compiled builder queries should also know whether developer-controlled raw fragments were used:

```text
containsRawFragments = true/false
```

Do not turn `CompiledQuery` into a general execution-context object.

It should contain only query composition metadata such as:

```text
SQL
bindings
QueryType
origin
raw-fragment flag
```

Do not add:

```text
logger
cache instance
connection instance
retry policy
telemetry object
```

to it.

---

# 21. Security Hot-Path Optimization

Current execution performs broad security validation before execution.

Separate security by query provenance.

### Fully generated QueryBuilder SQL

Identifiers and operators should already have been validated while composing the query.

Execution needs:

```text
binding limits
binding sizes
query size limits
rate limits
remaining structural checks
```

Avoid repeatedly scanning the entire final generated SQL with expensive injection heuristics.

### Builder query containing raw fragments

Validate the raw fragment at the point it enters:

```text
whereRaw()
selectRaw()
join raw expression
CTE raw SQL
raw fromSub()
```

Then retain origin metadata.

### Fully raw SQL

Keep full raw SQL policy and heuristic validation.

### Schema/internal SQL

Use the explicit trusted internal/migration boundary.

The goal is:

```text
generated SQL → structural safety
raw SQL       → stronger runtime policy
```

rather than treating both as equally untrusted.

This should reduce repeated regex work on the most common builder hot path without weakening raw SQL controls.

---

# 22. Raw SQL Policy

Keep:

```text
allow
deny
allowlist
```

but position allowlists as an optional high-restriction mode rather than the universal production default.

Recommended general production baseline:

```text
security enabled
strict identifiers enabled
bound parameters
raw SQL explicitly developer-owned
reasonable SQL/binding limits
TLS when required
```

Use `allowlist` when the consuming application genuinely has a narrow raw-SQL surface.

Do not pretend regex allowlisting is a replacement for parameterization.

---

# 23. ConnectionConfig

Keep `ConnectionConfig` immutable and make it the only normalization authority.

After construction, runtime code should consume normalized accessors rather than repeatedly processing arbitrary config arrays.

Use ArrayKit 5 structural utilities where they simplify initial validation, but keep semantic database checks inside DBLayer.

Normalize once:

```text
driver aliases
read strategy
replicas
weights
timeouts
security
statement cache
query comments
TLS
prefix
PDO options
```

Runtime code should work against the normalized representation.

Avoid creating many small config classes unless they materially reduce complexity.

Do not split into:

```text
StatementCacheConfig
CommentConfig
TlsConfig
ReplicaTimeoutConfig
...
```

simply for architectural purity.

Performance and maintainability come first.

---

# 24. Replica Routing

Keep all four existing strategies:

```text
random
round_robin
weighted
least_latency
```

The uploaded DBLayer already supports `weighted`, although the older online README still only advertises three strategies. 
Update public documentation accordingly.

Recommended production guidance:

```text
homogeneous replicas      → round_robin
different replica capacity → weighted
specific latency use-case → least_latency
simple non-determinism     → random
```

`least_latency` should be described accurately as choosing based on DBLayer's probe latency, not as a complete measure of replica health or query performance.

Do not add adaptive EWMA/load-aware routing in this release unless production evidence shows a need.

---

# 25. Extract Replica Selection from Connection

Replica selection is cohesive enough to justify one internal class.

Recommended:

```php
final class ReplicaSelector
```

Own:

```text
round-robin cursor
weighted selection
least-latency winner
latency TTL
health cooldowns
failed replica suppression
probe ordering
sample selection
```

Connection then orchestrates:

```text
resolve config
ask selector
create/read PDO
execute
```

This is a justified extraction because it removes substantial state and branching.

Do **not** respond to complexity by creating many tiny interfaces/classes.

One cohesive extraction is enough.

---

# 26. Driver-Owned Session Behavior

Move driver-specific behavior progressively out of `ConnectionInternals`.

Examples:

```text
MySQL max_execution_time
MariaDB max_statement_time
PostgreSQL statement_timeout
SQLite busy_timeout
read-only transaction syntax
read-only session syntax
vendor-specific connection initialization
```

Preferred shape:

```php
$driver->applyStatementTimeout(...);
$driver->applyReadOnlyTransaction(...);
$driver->configureReadSession(...);
```

Connection should orchestrate behavior, not contain every vendor SQL command.

Do not create separate interfaces for every tiny capability.

Extend the existing driver contract/concrete driver classes instead.

---

# 27. Timeout Semantics

Keep portable client-side query budgets as the canonical DBLayer guarantee.

Document native server enforcement as best-effort.

Particularly:

```text
SQLite busy_timeout
```

is a lock-wait timeout, not a true statement-execution timeout.

`withQueryTimeout()` should therefore guarantee DBLayer's execution budget semantics, while native database controls are supplementary.

---

# 28. Statement Cache

Keep:

```php
'statement_cache_enabled' => false
```

as the default.

The current benchmark suite correctly compares enabled vs disabled behavior.

Do not enable statement caching globally based on theory.

Prepared-statement reuse differs substantially by:

```text
driver
transaction state
PDO behavior
query shape
resource bindings
server configuration
```

Continue requiring benchmark evidence.

Only extract statement-cache internals into a dedicated class if doing so materially lowers Connection complexity without adding extra calls/allocations to the disabled path.

Avoid overengineering the current small LRU while its default capacity is small and the entire feature is opt-in.

---

# 29. QueryBuilder Scope

The QueryBuilder already has enough functionality:

```text
joins
subqueries
CTEs
unions
window expressions
aggregates
upsert
RETURNING
pagination
cursor pagination
chunking
streaming
locks
EXPLAIN
```

Do not turn the modernization into a large fluent-method expansion.

Focus on:

```text
compiler correctness
binding ordering
cross-driver parity
typed execution
bulk behavior
caching
performance
```

---

# 30. Automatic Bulk Parameter Chunking

Implement bounded batching for:

```text
bulkInsert()
multi-row insert
upsert()
bulk repository writes
large relation IN lists
```

Calculate effective rows from:

```text
configured security.max_params
driver practical parameter ceiling
parameters required per row
```

Conceptually:

```text
maxRows = floor(maxParameters / parametersPerRow)
```

Account for additional bindings generated by:

```text
conflict expressions
update sections
scopes
```

Do not rely on applications to manually guess safe batch sizes.

Explicit caller chunk size may lower the automatically calculated value but should not exceed a known unsafe limit.

---

# 31. RelationLoader

Keep RelationLoader explicit and non-ORM.

Current bounded set-based relation projection is a strong architectural choice.

Do not add:

```text
$user->posts
hasMany()
belongsTo()
morphMany()
automatic eager loading
lazy relationship properties
```

Instead improve effective relation batching.

Calculate:

```text
effectiveBatchSize =
min(
    requestedBatchSize,
    securityParameterLimit,
    driverParameterLimit
)
```

For composite keys:

```text
effectiveBatchSize =
floor(parameterLimit / keyWidth)
```

Preserve:

```text
lastQueryCount()
lastRelatedRowCount()
```

for N+1 regression diagnostics.

---

# 32. Repository Modernization

Repository remains the table-policy layer.

Keep:

```text
tenant scope
global scopes
soft deletes
optimistic locking
casts
hooks
default order
mapping
chunking
pagination
streaming
```

Use ArrayKit 5 for generic collection/mapping functionality only where it removes actual duplication.

Database-specific casting stays in DBLayer.

For example:

```text
database boolean normalization
datetime conversion policy
optimistic version handling
soft-delete semantics
```

remain DBLayer responsibilities.

---

# 33. Repository Cache Policy

Allow repository defaults to opt into caching:

```php
$users = DB::repository('users')
    ->cacheFor(60);
```

or through TableRepository configuration:

```php
protected static function configureRepository(Repository $repository): Repository
{
    return $repository
        ->enableSoftDeletes()
        ->cacheFor(60);
}
```

Repository can automatically add:

```text
table tags
record tag where primary key is known
tenant tag where tenant scope is known
```

Writes schedule invalidation after successful commit.

Do not automatically cache repository reads unless `cacheFor()` or equivalent policy is explicitly enabled.

Correctness remains the default.

---

# 34. Repository `findMany()`

Ensure:

```php
findMany([...])
```

is a single set-based query per safe parameter batch.

Avoid one query per ID.

When batching is required, merge results without changing semantics.

Define output ordering explicitly.

Recommended behavior: preserve requested ID order when practical because it is deterministic for application callers.

---

# 35. TableRepository

Keep TableRepository as an optional static repository ergonomic layer.

Keep:

```php
User::find(...)
User::where(...)
User::query(...)
User::repository(...)
User::forTenant(...)
```

Do not turn it into Active Record.

### Narrow magic infrastructure dispatch

Current TableRepository dispatches unknown static calls through:

```text
Repository
→ QueryBuilder
→ DB facade
```

which creates a very broad magic namespace and already requires raw SQL aliases such as `sqlSelect()` to resolve collisions.

For the next major, narrow automatic dispatch to database-table concerns:

```text
Repository
→ QueryBuilder
```

Keep explicit TableRepository methods for:

```text
connection()
transaction()
sqlSelect()
sqlStatement()
sqlScalar()
```

Infrastructure should stay explicit:

```php
DB::stats();
DB::health();
DB::telemetry();
DB::pool();
DB::cache();
```

rather than:

```php
User::telemetry();
User::pool();
User::cache();
```

This reinforces the three-layer architecture and reduces runtime magic.

---

# 36. Transactions

Preserve the existing design:

```text
manual transactions
closure transactions
nested savepoints
retry attempts
read-only transactions
afterCommit
transaction statistics
```

Execution-state wrappers must remain properly nested with `try/finally`.

For example:

```php
DB::withQueryTimeout(500, function () {
    DB::withQueryTimeout(100, function () {
        // 100 ms
    });

    // restored to 500 ms
});
```

Apply the same stack semantics to:

```text
timeout
deadline
cancellation
retry policy
temporary query events
```

Add nested-state restoration tests where missing.

---

# 37. Long-Running Worker Reset

`DB::resetRuntimeState()` is important and should remain first-class.

Ensure it resets request/job-local state including:

```text
query logs
profiler buffers
telemetry buffers
temporary listeners
query threshold monitors
query comments/context
cancellation state
deadlines/timeouts
retry policy
sticky read-after-write state
temporary cache bypass state
```

Preserve:

```text
registered connection configurations
configured CacheLayer instance
stable infrastructure configuration
```

Do not destroy shared cache instances or durable cache state between logical requests.

---

# 38. Schema & Migration Architecture

Keep:

```text
SchemaManager
Blueprint
SchemaGrammar
MigrationRunner
MigrationContext
SeedRunner
SeedContext
```

outside ordinary query execution.

Do not make migrations:

```text
scan directories
discover packages
render console UI
inspect frameworks
```

DBLayer should continue accepting explicit compiled migration manifests.

Preserve explicit failure for unsupported SQLite DDL rather than silently rebuilding tables.

Keep raw default expressions explicit through:

```php
Expression::make(...)
```

A normal string remains data.

An Expression remains explicitly trusted SQL.

---

# 39. Driver Capabilities

Keep the existing `Capabilities` design and extend only when DBLayer actually needs to branch.

Current capabilities include:

```text
RETURNING
insert ignore
upsert
savepoints
schemas
JSON
window functions
```

Possible additions when implementation requires them:

```text
transactional DDL
session read-only
generated columns
rename index
native UUID
```

Do not build a massive speculative feature matrix.

Where behavior is version-dependent, allow capabilities to account for server version instead of assuming every release of a vendor behaves identically.

---

# 40. Cursor Pagination

Keep the current opaque query-bound cursor architecture.

Preserve:

```text
versioned token
order definition
filter/binding binding
unique tie-breaker
optional HMAC-SHA256 signing
```

Do not add key rotation/keyrings until there is an actual requirement for seamless long-lived cursor rotation.

The existing single signing key is simpler and adequate for the current scope.

---

# 41. Security Configuration Cleanup

Retain core security controls:

```text
enabled
max_sql_length
max_params
max_param_bytes
queries_per_second
queries_per_minute
rate_limit_key
rate_limit_callback
strict_identifiers
require_tls
allow_insecure
raw_sql_policy
raw_sql_allowlist
cursor_signing_key
```

Use one canonical config name everywhere.

The existing code/docs use:

```text
max_params
```

so retain that instead of introducing alternate names.

Avoid overlapping security layers whose precedence is unclear.

Document exact precedence:

```text
global enforced security defaults
    ↓
connection normalized security config
    ↓
query provenance-specific validation
```

---

# 42. Rate Limiting

Keep the current local limiter for simple/process-local use.

For actual distributed application limits, continue supporting:

```php
rate_limit_callback
```

Do not make DBLayer depend on Redis/CacheLayer for query-rate limiting automatically.

The consuming application can wire distributed behavior when required.

Caching and rate limiting remain separate concerns.

---

# 43. Observability

Preserve:

```text
query logger
PSR logger forwarding
profiler
telemetry
slow query reports
query-shape reports
query events
transaction events
threshold callbacks
```

The critical rule:

> When observability is disabled, the hot path must do almost no observability work.

Do not unnecessarily:

```text
build event arrays
normalize trace context
fingerprint SQL
allocate profiler objects
store query metadata
```

unless a consumer requires it.

---

# 44. Typed Event Canonicalization

DBLayer already contains typed database events such as:

```text
QueryExecuted
QueryFailed
TransactionCommitted
...
```

Make typed payloads the internal canonical event representation.

Avoid independently constructing similar representations for:

```text
logger
profiler
telemetry
listener
```

Capture execution metadata once, then fan it out to enabled consumers.

This reduces duplicate timing, fingerprinting and allocation.

A simple array listener adapter may remain if useful, but typed events should be the source of truth.

---

# 45. Query Fingerprinting

Keep query fingerprints independent from observability comments.

The same parameterized query should produce the same fingerprint regardless of:

```text
app
route
trace
request
query comment
```

unless SQL semantics change.

Do not fingerprint bound values into the SQL shape.

Use bindings separately where cache identity requires them.

---

# 46. Query Comments

Keep disabled by default.

They add SQL bytes and may affect:

```text
statement caching
server parsing
database monitoring
```

Continue sanitizing and bounding context.

Measure:

```text
comments enabled
comments disabled
```

with paired benchmarks before recommending them for high-throughput workloads.

---

# 47. Pooling

Keep connection pooling opt-in.

Do not initialize pools for normal PHP-FPM usage automatically.

Position pooling primarily for:

```text
long-running workers
RoadRunner
Swoole
CLI daemons
persistent processes
```

Keep:

```text
max_connections
idle_timeout
max_lifetime
health_check_interval
```

bounded and measurable.

Avoid adding an external pool abstraction until there is a concrete requirement.

---

# 48. Public API Result Types

Performance priority:

```text
QueryBuilder::get() → array
raw select          → array
streaming           → Generator/iterable
repository collection APIs → ArrayKit Collection where collection semantics are intended
collect()           → ArrayKit Collection
lazyCollection()    → ArrayKit LazyCollection
```

Do not wrap every row/query result inside generic result objects.

Objects should only be introduced when they provide enough semantic value to justify allocation.

`DriverResult` may remain an internal execution contract where needed.

---

# 49. Keep Interfaces Limited

Do not create interfaces for every internal implementation.

Use an interface when:

```text
third-party extension is expected;
multiple meaningful implementations exist;
a public contract needs stability;
dependency inversion provides real value.
```

Concrete internal classes are preferred otherwise.

Good existing interface examples:

```text
DriverInterface
Migration
Seeder
LockProviderInterface from CacheLayer
```

Do not add:

```text
ReplicaSelectorInterface
StatementCacheInterface
QueryCommentInterface
TimeoutInterface
...
```

without a real extension requirement.

---

# 50. Enum Policy

Use enums only for genuinely closed semantic sets.

Good candidates:

```text
QueryType
SecurityMode
ReadStrategy
RawSqlPolicy
SqlOrigin
```

Do not create enums for ordinary booleans or configuration constants.

If a value exists only internally and does not benefit from type safety, class constants remain sufficient.

---

# 51. Performance Benchmark Expansion

Keep the existing PHPBench subjects, including:

```text
SQL compilation
primary-key select
transactions
single-column update
raw execution
typed runCompiled
statement cache on/off
query comments on/off
event dispatch on/off
buffered streaming
unbuffered streaming
least-latency replica
relation loading
schema compilation
```

The uploaded benchmark suite already covers these areas.

Add focused subjects for the modernization:

```text
Array result vs collect()
lazyById vs lazyCollection adapter overhead
trusted generated query validation
raw query validation
query-cache hit
query-cache miss
query-cache disabled
cache key generation
cache tags generation
repository cached find
bulk insert compilation: 100 rows
bulk insert compilation: 1000 rows
upsert chunking
findMany 100 IDs
effective relation batching
afterCommit cache invalidation registration
```

Do not benchmark Redis/network CacheLayer adapters as PHP microbenchmarks.

Use integration/load tests for network adapters.

---

# 52. Performance Acceptance Rules

Continue using the project's existing benchmark discipline.

For hot paths:

- compare on the same PHP version/hardware/configuration;
- use repeated runs;
- watch median plus variance/RSD;
- investigate a sustained regression greater than roughly 2%;
- measure memory allocations/peak memory as well as latency;
- verify semantic equivalence;
- never call a microbenchmark result an application RPM figure.

A feature may justify a measurable cost, but the cost must be known.

Performance comes before scaling abstractions.

---

# 53. Cross-Driver Test Matrix

All query/schema changes must be tested against:

```text
SQLite
MySQL/MariaDB where appropriate
PostgreSQL
```

Test especially:

```text
binding order
bulk parameter chunking
RETURNING
insert ignore
upsert
locks
savepoints
read-only transactions
statement timeout behavior
table prefixes
CTEs
subqueries
cursor pagination
schema alterations
migration ledger
relation loading
query cache invalidation
```

SQLite remains the fast local baseline, but driver-specific functionality cannot be considered complete based only on SQLite tests.

---

# 54. CacheLayer 3 Integration Tests

Add tests for:

```text
default lazy memory cache
custom injected cache
tiered cache injection
remember hit
remember miss
stampede-safe resolver path
tagged query caching
table invalidation
record invalidation
tenant invalidation
rollback does not invalidate
commit does invalidate
retry attempt does not leak invalidation callbacks
sticky reads bypass stale cache
streaming rejects/bypasses query cache
locking reads reject/bypass query cache
cache security configuration remains CacheLayer-owned
```

Use CacheLayer's native test-friendly memory adapter for unit tests where possible.

---

# 55. ArrayKit 5 Integration Tests

Add/adjust tests for:

```text
Repository collection return types
QueryBuilder collect()
lazyCollection()
ArrayKit Collection methods
ArrayKit LazyCollection operations
config structural validation
no duplicate global helper definitions
no DBLayer Collection compatibility wrapper
```

Do not test ArrayKit internals from DBLayer.

Only test DBLayer's integration contract.

---

# 56. Documentation Rewrite

Synchronize README and Sphinx documentation with the implementation.

The online DBLayer README currently presents an older/lighter feature surface—for example, it lists only three replica strategies and describes Repository as a thin wrapper—while the uploaded project already contains `weighted`, TableRepository, migrations, schema, relation loading, cursor signing and broader runtime behavior. 
README should describe DBLayer primarily as:

```text
High-performance database layer
QueryBuilder
Repository policies
TableRepository ergonomics
multi-driver execution
transactions
read/write routing
streaming
schema/migrations
explicit relation loading
execution controls
CacheLayer-powered result caching
security
observability
```

Avoid describing it as an ORM.

---

# 57. Update Cache Documentation to CacheLayer 3

Examples should show modern CacheLayer 3 behavior, including tags.

For example:

```php
$cache = DB::cache();

$users = $cache->remember(
    'users.active',
    fn () => DB::table('users')
        ->where('active', '=', 1)
        ->get(),
    ttl: 120,
    tags: ['users'],
);
```

CacheLayer 3's public contract supports TTL plus tags directly in `remember()`.

Then show the preferred DBLayer-integrated equivalent:

```php
$users = DB::table('users')
    ->where('active', '=', 1)
    ->cacheFor(120)
    ->cacheTags('users')
    ->get();
```

Make clear that DBLayer delegates storage, locks, tags and stampede protection to CacheLayer.

---

# 58. Composer Final State

Target:

```json
{
    "require": {
        "php": "^8.4",
        "ext-pdo": "*",
        "infocyph/arraykit": "^5.0",
        "infocyph/cachelayer": "^3.0",
        "psr/log": "^3.0.2"
    },
    "require-dev": {
        "infocyph/phpforge": "dev-main@dev"
    }
}
```

Retain:

```text
minimum-stability: stable
prefer-stable: true
classmap-authoritative: true
optimize-autoloader: true
sort-packages: true
```

Remove `autoload.files` if DBLayer global helpers are removed.

Do not change PHPForge away from:

```text
dev-main@dev
```

---

# 59. Recommended Final Internal Structure

```text
src/
├── Connection/
│   ├── Connection.php
│   ├── ConnectionConfig.php
│   ├── ReplicaSelector.php
│   └── ...
│
├── Driver/
│   ├── MySqlDriver.php
│   ├── PostgreSqlDriver.php
│   ├── SQLiteDriver.php
│   └── Support/
│
├── Query/
│   ├── QueryBuilder.php
│   ├── Core/
│   │   ├── QueryPayload.php
│   │   ├── CompiledQuery.php
│   │   ├── QueryType.php
│   │   └── SqlOrigin.php
│   ├── Grammar/
│   └── Pagination/
│
├── Repository/
│   └── TableRepository.php
│
├── Relation/
│   └── RelationLoader.php
│
├── Schema/
│   ├── SchemaManager.php
│   ├── Blueprint.php
│   └── SchemaGrammar.php
│
├── Migration/
│   ├── MigrationRunner.php
│   ├── MigrationContext.php
│   ├── SeedRunner.php
│   └── SeedContext.php
│
├── Events/
├── Security/
├── Support/
│   ├── Telemetry.php
│   └── only DB-specific utilities
│
└── DB.php
```

Do not create extra files simply to satisfy architectural symmetry.

In particular:

```text
remove DBLayer Support\Collection
remove duplicated generic array utilities
remove generic helpers
```

and avoid replacing them with new DBLayer wrappers around ArrayKit.

---

# 60. Implementation Order

Implement in this order:

### Phase 1 — Dependency Alignment

1. Upgrade ArrayKit to `^5.0`.
2. Upgrade CacheLayer to `^3.0`.
3. Keep PHPForge `dev-main@dev`.
4. Resolve namespace/signature changes.
5. Run complete existing test suite before structural cleanup.

### Phase 2 — ArrayKit Cleanup

1. Remove DBLayer Collection compatibility layer.
2. Return/use ArrayKit Collection directly where required.
3. Remove generic helper duplication.
4. Remove `autoload.files` if no DB-specific globals remain.
5. Integrate ArrayShape only into cold structural validation.
6. Add `collect()` and `lazyCollection()` where useful.
7. Benchmark result-wrapper overhead.

### Phase 3 — CacheLayer 3 Integration

1. Align `CacheInterface` imports and cache factories.
2. Keep lazy `Cache::memory('dblayer')` default.
3. Introduce explicit `setCache()`.
4. Remove adapter-specific DB shortcuts such as `useFileCache()`.
5. Verify MigrationRunner locking against CacheLayer 3.
6. Update cache documentation/examples.

### Phase 4 — Query Result Cache

1. Implement `cacheFor()`.
2. Implement `cacheTags()`.
3. Implement optional `cacheKey()`.
4. Implement `withoutCache()`.
5. Generate deterministic DB/query keys.
6. Delegate misses to CacheLayer 3 `remember()`.
7. Add automatic builder table tags.
8. Add repository record/tenant tags.
9. Add `afterCommit()` invalidation.
10. Add transaction/sticky/locking/streaming bypass rules.

### Phase 5 — Execution Pipeline

1. Make `CompiledQuery` canonical.
2. Remove obsolete duplicate Executor paths.
3. Add query provenance.
4. Validate generated vs raw SQL through separate paths.
5. Avoid reclassifying known QueryType SQL.
6. Benchmark generated and raw execution separately.

### Phase 6 — Connection Cleanup

1. Extract cohesive `ReplicaSelector`.
2. Move native timeout/session SQL to drivers.
3. Keep statement cache opt-in.
4. Verify nested runtime scopes.
5. Ensure sticky state resets between worker jobs.

### Phase 7 — Query/Repository Improvements

1. Automatic safe bulk chunk sizing.
2. Automatic upsert chunk sizing.
3. RelationLoader effective parameter sizing.
4. Optimize `findMany()`.
5. Add repository cache policy.
6. Narrow TableRepository infrastructure magic.

### Phase 8 — Security & Observability

1. Apply provenance-aware validation.
2. Normalize raw SQL policy behavior.
3. Keep raw fragments explicit.
4. Consolidate typed execution events.
5. Avoid telemetry/fingerprint work when disabled.
6. Verify bounded worker memory.

### Phase 9 — Cross-Driver Validation

1. SQLite full suite.
2. MySQL/MariaDB integration suite.
3. PostgreSQL integration suite.
4. Table-prefix matrix.
5. schema/migration matrix.
6. cache/transaction consistency matrix.

### Phase 10 — Benchmark & Documentation

1. Capture old baseline.
2. Capture new baseline.
3. Investigate sustained >2% hot-path regressions.
4. Record memory changes.
5. Update README.
6. Update Sphinx docs.
7. Update examples.
8. Update benchmark documentation.
9. Ensure public documented behavior matches actual code.

---

# 61. Explicit Non-Goals

Do **not** add:

```text
Active Record
ORM models
implicit relations
relationship properties
identity map
unit of work
dirty tracking
automatic query caching
automatic Redis
automatic tiered cache
automatic cluster cache
DBLayer-specific cache adapters
DBLayer-specific lock abstractions
directory-scanning migrations
automatic schema optimization
automatic index creation
automatic partitioning
SQL parsing for raw table prefixes
query cache for streams
query cache for locking reads
interfaces for every internal class
DTO wrappers around every row
collection wrappers around every normal query result
compatibility shims for ArrayKit 4
compatibility shims for CacheLayer 2
```

---

# 62. Final Design Principles

The final architecture should follow these boundaries:

```text
ArrayKit 5
    → generic arrays
    → collections
    → lazy collections
    → dot notation
    → structural array validation
    → generic configuration mechanics

CacheLayer 3
    → cache storage
    → tiering
    → tags
    → stampede protection
    → locks
    → cached-payload security
    → metrics

DBLayer
    → SQL
    → databases
    → drivers
    → connections
    → transactions
    → repositories
    → query semantics
    → result-cache identity/invalidation semantics
    → replicas
    → schema/migrations
    → database security
    → database observability
```

The central rule is:

> **Do not reimplement a generic capability that ArrayKit 5 or CacheLayer 3 already owns. DBLayer should add only the database-specific semantics needed to use that capability correctly.**

---

# 63. Expected End State

After this modernization, DBLayer should have:

- fewer internal compatibility classes;
- fewer global helpers;
- direct ArrayKit 5 collection/lazy integration;
- direct CacheLayer 3 cache/lock/tag integration;
- real stampede-safe query-result caching;
- transaction-safe cache invalidation;
- sticky-read/cache consistency;
- bounded bulk operations;
- safer relation batching;
- one typed query execution pipeline;
- less SQL reclassification;
- less unnecessary security scanning for generated SQL;
- clearer driver ownership;
- smaller Connection responsibility;
- clearer Repository/TableRepository boundaries;
- stronger long-running-worker behavior;
- more accurate documentation;
- expanded cross-driver tests;
- benchmark protection for every important new hot path.

Most importantly, DBLayer should become **smaller internally despite gaining better behavior**.

That is the correct direction for the next major: **use ArrayKit 5 and CacheLayer 3 deeply, but keep them out of DBLayer's ordinary hot path unless their functionality is explicitly needed.**