# DBLayer — Final Consolidated Audit & Fix-All Draft
## ArrayKit 5.1 / CacheLayer 3.1 Alignment

---

# 1. Final Target

DBLayer should finish this modernization around one principle:

> One structured query model, one compiler path, one execution path, one transaction state model, one observation pipeline, and cache behavior whose correctness is provable.

Final dependency baseline:

```json
{
    "require": {
        "php": "^8.4",
        "ext-pdo": "*",
        "infocyph/arraykit": "^5.1",
        "infocyph/cachelayer": "^3.1",
        "psr/log": "^3.0.2"
    },
    "require-dev": {
        "infocyph/phpforge": "dev-main@dev"
    }
}
```

Do not add a direct `ext-mbstring` requirement merely to support current utility code. Remove the unnecessary mbstring usage instead.

Keep:

```text
PHP                 ^8.4
ArrayKit            ^5.1
CacheLayer          ^3.1
PHPForge            dev-main@dev
PDO                 required
PSR Log             ^3.0.2
```

No ArrayKit 5.0-specific compatibility layer should remain.

No CacheLayer 3.0-specific compatibility layer should remain.

---

# 2. Architecture That Should Survive

Keep the existing major boundaries:

```text
DB
├── connection registry
├── transactions
├── pooling
├── runtime controls
├── cache registration
├── observability
└── infrastructure

QueryBuilder
├── SQL/query state
├── cache policy
├── QueryPayload
└── execution-facing API

Repository
├── table policies
├── tenant scope
├── soft delete
├── casts
├── optimistic locking
├── hooks
└── repository cache policy

Driver
├── PDO creation
├── dialect/compiler
├── capabilities
├── timeout/session behavior
└── driver limits

Schema / Migration
├── DDL
├── ledger
├── leases
└── deployment-time behavior
```

Do not turn DBLayer into:

```text
ORM
Active Record
identity map
unit of work
implicit relations
automatic eager loading
automatic cache framework
framework bootstrapper
migration discovery engine
generic utility library
```

---

# 3. ArrayKit 5.1 Alignment

The overall ArrayKit integration direction is correct.

Keep direct use of:

```text
Infocyph\ArrayKit\Collection\Collection
Infocyph\ArrayKit\Collection\LazyCollection
ArrayShape
other genuinely generic ArrayKit functionality
```

Normal query results should remain arrays:

```php
$rows = DB::table('users')->get();
```

Explicit conversion:

```php
$rows = DB::table('users')->collect();
```

Bounded lazy adaptation:

```php
$rows = DB::table('users')->lazyCollection(500);
```

Do not wrap every query result in ArrayKit classes.

## Required 5.1 changes

Update:

```json
"infocyph/arraykit": "^5.1"
```

and every exact dependency reference in:

```text
composer.json
docs/installation.rst
README/package requirements if present
examples/comments that state version
CI dependency assumptions
integration tests
```

Run the complete DBLayer suite against ArrayKit 5.1 rather than assuming 5.0 behavior.

---

# 4. CacheLayer 3.1 Alignment

Keep CacheLayer responsible for:

```text
memory/file/APCu/PDO/Redis/Valkey/etc.
tiering
TTL
tags
versioned tag invalidation
remember()
stampede protection
locks
payload serialization
cache security
compression
metrics
```

DBLayer should own only database semantics:

```text
cache eligibility
query identity
table dependencies
transaction-safe invalidation
tenant/record tags
sticky-read bypass
locking/streaming bypass
```

Keep:

```php
DB::cache();
DB::setCache($cache);
```

and lazy memory default behavior.

Update:

```json
"infocyph/cachelayer": "^3.1"
```

and all documentation/CI references.

Do not introduce a DBLayer cache abstraction over CacheLayer 3.1.

---

# 5. P0 — `insertGetId()` Can Perform the INSERT Twice

This is a release-blocking correctness bug.

Current conceptual flow:

```text
insertGetId()
    ↓
insertReturning()
    ↓
fallback performs INSERT
    ↓
lastInsertId unavailable
    ↓
returns null
    ↓
insertGetId() performs INSERT again
```

A successful first mutation must never result in a second write merely because its ID could not be retrieved.

## Required redesign

Provide one mutation primitive that returns a mutation outcome separately from return-data availability.

Conceptually:

```php
final readonly class MutationResult
{
    public function __construct(
        public int $affectedRows,
        public array $returnedRows = [],
        public ?string $lastInsertId = null,
    ) {}
}
```

Do not necessarily create this class if an existing result type can carry these semantics cleanly.

Important requirement:

```text
mutation success != returned-row availability
```

`insertGetId()` should:

1. execute exactly one insert;
2. obtain `RETURNING` value if supported;
3. otherwise read `lastInsertId()`;
4. if no ID can be determined, throw an explicit exception;
5. never execute the insert again.

Add tests asserting physical row count remains exactly one.

---

# 6. P0 — `insertReturning()` Invalidation Is Tied to Return Payload

A mutation may succeed while no row is returned.

Cache invalidation must depend on:

```text
mutation succeeded
```

not:

```text
returned row != null
```

Fix this for:

```text
insertReturning()
insertGetId()
```

and any fallback paths.

---

# 7. P0 — `upsertReturning()` Can Mutate Without Invalidating

The fallback path may successfully mutate data but return an empty result because read-back was unavailable.

Do not infer write success from:

```php
$returnedRows !== []
```

Instead:

```text
execute mutation
    ↓
know mutation succeeded
    ↓
schedule invalidation
    ↓
optionally perform readback
```

This same rule should govern every mutation API.

---

# 8. P0 — TRUNCATE Cache Invalidation

`truncate()` must invalidate its table dependency.

Required:

```text
truncate succeeds
      ↓
schedule table tag invalidation
      ↓
outer commit
      ↓
CacheLayer::invalidateTags()
```

Outside a transaction, `afterCommit()` can execute immediately.

Tests:

```text
cached rows → truncate → next cached read empty
truncate → rollback → old cache remains valid
nested truncate → savepoint rollback → no invalidation
nested truncate → savepoint release → invalidation promoted
```

---

# 9. P0 — Empty `whereIn()` Is Destructive in the New Compiler

The compiler currently treats an empty `IN` list by omitting the predicate.

That is dangerous:

```php
DB::table('users')
    ->whereIn('id', [])
    ->delete();
```

must **never** become effectively:

```sql
DELETE FROM users;
```

Required canonical semantics:

```text
whereIn([], ...)
    → always false

whereNotIn([], ...)
    → always true
```

Examples:

```sql
0 = 1
1 = 1
```

or an equally portable compiler representation.

This must behave identically in:

```text
SELECT
UPDATE
DELETE
Repository scopes
RelationLoader-generated conditions
nested WHERE clauses
```

Also remove any legacy `IN ()` generation.

---

# 10. P0 — Complex Query Cache Dependencies Are Incomplete

Automatic tags currently cannot fully represent tables underneath:

```text
CTEs
recursive CTEs
fromSub()
joinSub()
UNION
UNION ALL
nested builders
WHERE EXISTS subqueries
derived tables
```

Never guess dependencies from SQL text.

## Immediate safe behavior

Until structural dependencies are complete:

```text
simple source/join graph
    → automatic tags allowed

complex graph + explicit cacheTags()
    → cache allowed

complex graph + no explicit tags
    → bypass query cache
```

Correctness wins over hit rate.

---

# 11. P0 — Child Raw-Fragment Provenance Must Propagate

A parent builder can contain a child builder containing raw SQL.

The parent must inherit:

```text
containsRawFragments = true
```

from:

```text
CTEs
fromSub
joinSub
UNION
WHERE EXISTS
nested structured queries
```

Otherwise a query containing raw SQL deep in its graph can be treated as fully structured.

Implement recursive provenance aggregation in `QueryPayload`.

---

# 12. P0 — Cache Identity and Executed SQL Must Be the Same Query

Current caching can build its identity from the legacy Grammar path while Executor later chooses the newer compiler.

That creates two potential SQL representations:

```text
SQL used for cache key
SQL actually executed
```

This must disappear.

Final flow:

```text
QueryBuilder
    ↓
QueryPayload
    ↓
compile exactly once
    ↓
CompiledQuery
    ├── cache identity
    └── database execution
```

The exact same:

```text
SQL
bindings
type
dependency/provenance metadata
```

must feed cache and execution.

---

# 13. P0 — Explicit `cacheKey()` Must Not Replace Query Identity

An explicit caller key currently risks making:

```php
Query A -> cacheKey('dashboard')
Query B -> cacheKey('dashboard')
```

resolve to one shared cache value even when SQL/bindings differ.

Preferred contract:

```text
canonical query identity
+
optional caller namespace/salt
```

Example conceptual identity:

```text
dblayer:
  database identity
  query fingerprint
  bindings fingerprint
  result mode
  caller cache-key salt
```

If a true raw override key is ever required, expose it as a clearly separate unsafe/expert API.

Do not make normal `cacheKey()` replace semantic identity.

---

# 14. P0 — Cache Hits Must Not Open a Database Connection

Cache eligibility currently calls transaction inspection that can lazily create PDO.

This means:

```text
CacheLayer hit
    ↓
DB connection still required first
```

That defeats:

```text
cache hit latency
database outage tolerance
connection conservation
```

Use managed transaction state that does not connect:

```php
$connection->managedTransactionLevel();
```

or equivalent.

Cache hit flow should be:

```text
compile query without PDO
      ↓
check transaction/runtime flags without PDO
      ↓
CacheLayer lookup
      ├── HIT → return
      └── MISS → obtain PDO and execute
```

A valid memory/Redis cache hit should not fail merely because the database is temporarily unreachable.

---

# 15. P0 — Automatic Statement Retry Can Duplicate Writes

Connection-level automatic reconnect/retry is too broad for writes.

After a network failure, the client may not know whether:

```text
INSERT
UPDATE
DELETE
UPSERT
```

was already committed on the server.

Repeating automatically can duplicate or corrupt data.

## Final retry rules

### Safe default

Automatic reconnect/retry only for:

```text
SELECT
outside a managed transaction
connection-level failure
```

### Never automatically retry

```text
INSERT after connection loss
UPDATE after connection loss
DELETE after connection loss
UPSERT after ambiguous outcome
any query inside managed transaction after connection failure
```

### Transaction conflicts

Retry:

```text
deadlock
serialization failure
```

at the **whole transaction boundary**, not at arbitrary individual statement level.

A PostgreSQL serialization/deadlock error aborts the transaction context; transaction-level retry is the correct abstraction.

Custom user retry policies may support explicitly idempotent operations, but that must be opt-in.

---

# 16. P0 — Do Not Reconnect Mid-Transaction

A connection failure during:

```text
BEGIN
query 1
query 2 ← connection lost
```

cannot safely reconnect and continue query 2 as though the same transaction survived.

Managed transaction + connection loss should:

```text
mark transaction failed/uncertain
invalidate connection
rollback locally if possible
throw
allow transaction wrapper to decide whether whole callback is retryable
```

Never transparently resume on a fresh PDO.

---

# 17. P0 — Transaction Level Changes Before COMMIT/ROLLBACK Succeeds

Internal transaction state must not say “committed” before PDO has actually committed.

Required ordering:

```text
perform PDO commit
      ↓
if success:
    update level
    update stats
    promote callbacks
    emit commit event
```

Likewise rollback:

```text
perform rollback/savepoint rollback
      ↓
if success:
    update internal level
    discard callbacks
    update stats
```

If PDO finalization fails, preserve an “uncertain/failed” state or invalidate the connection rather than pretending the level completed.

---

# 18. P0 — After-Commit Callbacks Are Discarded Too Early on Rollback

Do not remove callback state before the actual rollback/savepoint operation succeeds.

If rollback fails, DBLayer needs enough state to:

```text
report correctly
invalidate/reject connection
avoid pretending transaction is clean
```

Move callback mutation after successful database state transition.

---

# 19. P0 — After-Commit Callback Errors Must Not Suppress Commit Events

Once PDO commit succeeds, the database is durable.

Required sequence:

```text
database COMMIT
    ↓
internal state/stats finalized
    ↓
run afterCommit callbacks
    ↓
emit TransactionCommitted
    ↓
rethrow first callback failure if needed
```

Or emit the commit event immediately after state finalization and then callbacks.

The key invariant:

> A callback failure cannot erase the fact that the transaction committed.

Run all registered callbacks even if one fails; retain and rethrow/report the first failure afterward.

---

# 20. P0 — Observer Exceptions Must Never Change Database Semantics

A successful query must not become an application-visible database failure because:

```text
logger throws
profiler throws
telemetry exporter throws
DB listener throws
threshold callback throws
custom event listener throws
```

Likewise, a failed SQL query's original PDO/DBLayer exception must not be replaced by an observer exception.

Core rule:

```text
database execution
    ≠
observer execution
```

Default observer failures should be isolated and optionally reported through a diagnostics channel.

Do not silently ignore forever; track them.

But never mask:

```text
successful database mutation
original SQL exception
durable commit
```

---

# 21. P0 — Raw Helper Methods Must Enforce Their Declared Semantics

These should not merely classify arbitrary SQL after accepting it:

```php
Connection::select()
Connection::insert()
Connection::update()
Connection::delete()
```

Examples that must fail:

```php
DB::select('DELETE FROM users');
DB::delete('SELECT * FROM users');
```

Each typed helper should enforce expected `QueryType`.

Keep generic:

```php
statement()
execute()
unprepared()
```

for intentionally generic SQL.

---

# 22. P0 — Improve SQL Statement Inspection

The current lightweight scanner needs to understand all supported-driver lexical basics, including:

```text
-- comments
/* comments */
MySQL # comments
single-quoted strings
double-quoted identifiers/strings as appropriate
backticks
PostgreSQL $$...$$ strings
PostgreSQL $tag$...$tag$ strings
CTEs
```

Do not implement a complete SQL parser.

Implement only enough tokenizer behavior to safely determine:

```text
leading statement type
query-comment stripping
raw-query classification
```

and test the supported dialects.

---

# 23. P0 — Validate CTE Names

CTE names are identifiers.

They must go through the same structured identifier validation/quoting rules as other aliases.

Do not concatenate arbitrary:

```php
with($name, ...)
```

directly into SQL.

---

# 24. P0 — Validate Boolean Combinators

Any public/internal parameter representing:

```text
AND
OR
```

must normalize to an exact closed set.

Never allow arbitrary strings to reach SQL such as:

```php
where(..., boolean: $callerString);
```

Normalize/validate:

```text
and
or
```

only.

Apply consistently across:

```text
where
whereNested
whereRaw
whereExists
JoinClause
HAVING variants
```

---

# 25. P0 — Validate JOIN Types

Allowed join types should be explicit:

```text
inner
left
right
cross
```

and any deliberately supported variants.

Do not allow arbitrary `$type` text into SQL.

---

# 26. P0 — `selectWindow()` Must Stop Building Unsafe Raw SQL

Current window construction has too much raw concatenation.

Validate/structure:

```text
alias
PARTITION BY identifiers
ORDER BY identifiers
directions
```

Function expressions should be:

```text
known structured function
or explicit Expression/raw boundary
```

rather than arbitrary string concatenation treated as safe builder output.

---

# 27. P0 — Pretend Mode Must Not Behave Like a Real Query

Migration/schema pretend execution should not:

```text
increment successful query stats
set sticky read-after-write state
alter connection health
emit normal QueryExecuted
record real performance samples
```

Pretend should collect:

```text
SQL
bindings
query type
```

only.

If observability is needed, introduce an explicitly separate preview event.

---

# 28. P0 — Pool Release Must Sanitize Connections

A pooled connection cannot simply become “idle” after borrower release.

Before reuse ensure:

```text
no active transaction
no transaction callbacks
sticky state cleared
timeout cleared
deadline cleared
cancellation checker cleared
query retry policy cleared
temporary query listeners cleared
query comment/request context cleared
mutable fetch mode restored
temporary execution state restored
```

Recommended internal API:

```php
$connection->resetRuntimeStateForReuse();
```

If cleanup cannot be guaranteed:

```text
discard/close connection
```

rather than return it to idle pool.

---

# 29. P0 — Pool Must Reject Foreign Connections

`releaseConnection($name, $connection)` must verify the Connection belongs to that pool and logical slot.

Never accept arbitrary external Connection objects into the idle collection.

---

# 30. P0 — Health Checks Must Not Probe Borrowed Connections

Do not run:

```sql
SELECT 1
```

against a currently borrowed Connection.

That can interfere with:

```text
active transaction
unbuffered stream
server cursor
statement sequencing
```

Health probes should target idle pool members only.

---

# 31. P0 — Replacing Registered Connections Must Close the Old One

Operations such as:

```text
addConnection same name
reconnect/rebuild
security-default reapplication
```

must not overwrite a Connection instance without explicitly cleaning it up.

Before replacement:

```text
active transaction?
    → refuse unless explicit force

otherwise
    → disconnect old
    → clear transaction/runtime ownership
    → replace
```

---

# 32. P0 — `Connection::disconnect()` Must Reset Managed State

Disconnect currently focuses primarily on PDO/read PDO/cache/replica state.

Also clear:

```text
managed Transaction state
afterCommit callbacks
sticky state
query deadline/timeout
cancellation
retry policy
temporary request context
```

If an active transaction exists, perform a safe rollback where possible or mark/discard the object rather than allowing clean reconnect semantics on an inconsistent manager.

---

# 33. P0 — PostgreSQL TRUNCATE Must Not Implicitly CASCADE

Current PostgreSQL truncate behavior is too destructive if it always emits:

```sql
TRUNCATE ... RESTART IDENTITY CASCADE
```

A request to truncate one table must not silently truncate dependent tables.

Safe default should be closer to:

```sql
TRUNCATE table
```

Expose explicit options if desired:

```php
truncate(
    restartIdentity: false,
    cascade: false,
);
```

or dedicated methods/options.

`CASCADE` must always be explicit.

---

# 34. P0 — Cross-Driver TRUNCATE Contract Must Be Defined

SQLite `DELETE FROM` does not inherently match PostgreSQL/MySQL truncate identity semantics.

Define one explicit DBLayer contract for:

```text
rows removed
identity reset yes/no
dependency cascade yes/no
transaction behavior
```

If DBLayer exposes options:

```text
restartIdentity
cascade
```

implement them consistently where supported.

SQLite identity reset may require explicitly updating `sqlite_sequence` where applicable.

Never silently widen destructive scope.

---

# 35. P0 — Remove Undeclared `mbstring` Requirement

Current source calls mbstring functions while Composer does not require `ext-mbstring`.

This can fatal on valid installations.

The current uses do not justify another mandatory extension.

Fix:

```text
remove generic Support\Str
use native ASCII-safe table-name normalization
use substr() for SQL diagnostic excerpts
avoid mb_* where DBLayer doesn't need Unicode semantics
```

Do not add `ext-mbstring` unless a genuinely necessary public feature requires it.

---

# 36. TransactionManager Statistics Need Rework

Current global transaction statistics have multiple inconsistent paths.

Problems include:

```text
total_transactions incremented while transaction wrapper created
active_transactions can decrement on no-op operations
execute() bypasses some Manager accounting
deadlock totals can be accumulated repeatedly
```

Choose one source of truth.

Preferred:

```text
Transaction
    → owns actual per-connection transaction state

TransactionManager
    → aggregates deltas from actual events
```

Or remove redundant global counters if they do not provide unique value.

Required invariants:

```text
active >= 0
active = actual active top-level transaction count
committed = actual durable commits
rolled_back = actual rollbacks
deadlocks = actual detected retry conflicts
total definition explicitly documented
```

---

# 37. Nested Transactions Without Savepoints Must Not Fake Nesting

If a driver truly does not support savepoints:

```text
nested managed transaction
```

must either:

```text
throw unsupported
```

or have an explicitly documented full-transaction nesting policy.

Do not increment levels while rollback/release are effectively no-ops.

---

# 38. Eliminate Two Public Transaction Systems

Connection currently exposes both lower-level PDO-like methods and managed transaction APIs.

This makes it possible to:

```text
begin with system A
commit with system B
```

and desynchronize Transaction state.

For the major release:

```text
one public managed transaction API
```

Keep raw PDO primitives private/internal where possible.

If low-level access must remain, label it explicitly as unmanaged and reject mixing while managed state exists.

---

# 39. Transaction `timeouts` Stat

If this counter is not actually maintained, remove it.

Do not publish counters whose meaning is always zero.

Alternatively implement a precise timeout classification and increment it exactly once.

---

# 40. Preserve Original Failure When Rollback Also Fails

When application callback throws and rollback then throws:

```text
primary failure = callback/database error
secondary failure = rollback error
```

Do not replace the original reason entirely.

Expose/chaining should retain both.

Also invalidate the connection because transaction state is uncertain.

---

# 41. Finish the Compiler Migration

The largest remaining architectural debt is still:

```text
new QueryPayload/Compiler path
+
legacy Grammar/Executor path
```

This must end.

Target:

```text
QueryBuilder
    ↓
full structured QueryPayload
    ↓
Driver QueryCompiler
    ↓
CompiledQuery
    ↓
Connection::runCompiled()
    ↓
PDO
```

No:

```text
canUseDriverCompiler()
fallback Grammar
parallel query compilation
```

after finalization.

---

# 42. `toSql()` Must Use the Actual Compiler

Inspection should represent the exact SQL that execution would use.

After compiler parity:

```php
$query->toSql();
$query->getBindings();
$query->explain();
$query->cacheFor(...);
$query->cursorPaginate(...);
```

must derive their query identity from the same canonical compilation.

---

# 43. Make QueryPayload a Complete Structured Query Model

It must structurally represent:

```text
SELECT
DISTINCT
aggregate
source table
source alias
fromSub
JOIN
JoinClause
joinSub
WHERE
nested WHERE
WHERE EXISTS
raw fragments
GROUP BY
HAVING
ORDER BY
LIMIT/OFFSET
CTE
recursive CTE
UNION
UNION ALL
locks
INSERT
INSERT IGNORE
INSERT RETURNING
UPDATE
DELETE
TRUNCATE
UPSERT
UPSERT RETURNING
```

Do not collapse structured child builders prematurely into SQL strings.

Retain child payloads so DBLayer can derive:

```text
dependencies
provenance
bindings
prefixes
identifiers
```

structurally.

---

# 44. Fix `QueryPayload::with()` Nullable Overrides

If implementation currently uses:

```php
$overrides['limit'] ?? $this->limit
```

then an explicit override to `null` cannot clear the value.

Use:

```php
array_key_exists('limit', $overrides)
```

for nullable fields.

Audit:

```text
limit
offset
lock
aggregate
alias
other nullable payload properties
```

---

# 45. QueryPayload Must Fail on Invalid Internal Shape

Current normalization code should not silently `continue` past malformed:

```text
joins
orders
unions
columns
conditions
```

Silently dropping part of a query changes semantics.

Because QueryPayload is internal structured state:

```text
invalid state
    → throw immediately
```

not:

```text
invalid state
    → quietly omit component
```

---

# 46. Unsupported INSERT IGNORE / UPSERT Must Not Degrade Semantics

Do not turn unsupported:

```text
insertIgnore
upsert
```

into ordinary INSERT.

Use driver capabilities:

```text
supported
    → compile

unsupported
    → explicit capability/QueryException
```

A “graceful fallback” that changes conflict behavior is incorrect.

---

# 47. Multi-Row `insertReturning()` Fallback Contract

A bulk insert cannot meaningfully synthesize all returned rows from one `lastInsertId()`.

Either:

```text
driver supports RETURNING
    → return full result
```

or:

```text
fallback restricted to a single inserted row
```

For bulk inserts on unsupported drivers, expose a different result contract rather than pretending equivalent behavior.

---

# 48. Remove Unused `runCompiled()` Parameters

Final API should be minimal:

```php
Connection::runCompiled(CompiledQuery $query): DriverResult;
```

Remove parameters that do not affect behavior.

---

# 49. Simplify CompiledQuery Metadata

Retain only what downstream execution genuinely consumes:

```text
SQL
bindings
QueryType
SqlOrigin
possibly raw-fragment provenance
```

If raw-fragment provenance is fully consumed/validated before compilation, don't carry it further.

Likewise remove unused:

```text
INTERNAL
SCHEMA
```

origin cases unless they result in actual distinct execution behavior.

---

# 50. Remove or Shrink Executor

Once every operation compiles canonically:

Preferred:

```text
QueryBuilder
→ Compiler
→ Connection
```

Executor can disappear.

If retained, limit it to higher-level orchestration such as:

```text
bulk splitting
returning fallback coordination
result aggregation
```

It must no longer be a second:

```text
compiler
event dispatcher
logger
security validator
execution engine
```

---

# 51. One Canonical Query Event Pipeline

Final:

```text
Connection::runCompiled()
    ↓
one measurement
    ↓
one typed event
       ├── logger
       ├── profiler
       ├── telemetry
       └── public listener adapter
```

No duplicated timing/fingerprinting/event construction between Executor and Connection.

---

# 52. Centralize Driver Bind Limits

Do not hardcode practical parameter ceilings in:

```text
Executor
Repository
RelationLoader
bulk helpers
```

Add driver-owned authority:

```php
$driver->maxBindParameters();
```

or equivalent existing capability API.

Then Connection computes:

```text
effective limit =
min(
    driver limit,
    configured security.max_params when active
)
```

All batching systems use the same value.

---

# 53. Centralize Safe Batch Size Calculation

Use one DB-specific helper for:

```text
bulk insert
upsert
findMany
RelationLoader
```

Formula conceptually:

```text
available = effectiveMaxParams - fixedBindings
rows      = floor(available / paramsPerRow)
```

Handle:

```text
composite keys
conflict/update bindings
scopes
fixed query bindings
caller-requested max batch size
```

Minimum one row or explicit impossible-query exception.

---

# 54. Cache Hits Should Not Deep-Copy Rowsets

Current cached result normalization reconstructs rows.

For a memory-cache hit that adds unnecessary:

```text
array allocation
hash-table creation
memory churn
CPU
```

Validate without copying:

```php
if (!array_is_list($result)) {
    throw ...;
}

foreach ($result as $row) {
    if (!is_array($row)) {
        throw ...;
    }
}

return $result;
```

Benchmark:

```text
1
50
500
5000 rows
```

before deciding how much deeper key validation is warranted.

---

# 55. Scope Internal Cache Tags by Database Identity

Current internal tags like:

```text
table.users
```

cause one DBLayer CacheLayer instance shared by multiple connections/databases to over-invalidate unrelated data.

Prefer internal tags such as:

```text
db.<identity>.table.users
db.<identity>.table.users.id.42
db.<identity>.tenant.10.users
```

Use a stable non-sensitive database identity hash.

This is mostly an efficiency/isolation fix, but it also makes tag semantics precise.

---

# 56. Do Not Silently Rewrite Caller Cache Tags

If CacheLayer accepts a defined safe alphabet, validate caller tags.

Do not turn different strings into the same normalized tag silently.

Bad:

```text
"a b" → "a.b"
"a.b" → "a.b"
```

Better:

```text
invalid caller tag
    → InvalidArgumentException
```

Internal DBLayer tags should already be emitted in valid form.

---

# 57. Canonicalize Schema-Qualified Table Identity

A physical table should not receive unrelated cache identities depending on whether query code wrote:

```text
users
public.users
```

where both resolve to the same effective table for the connection.

Define canonical logical dependency identity using:

```text
connection schema
explicit qualifier
table prefix rules
```

without parsing arbitrary raw SQL.

---

# 58. Raw Writes Need Explicit Cache Invalidation Contract

DBLayer cannot reliably infer affected tables from arbitrary raw SQL.

Do not write a SQL parser.

Document:

```php
DB::statement(...);

DB::invalidateCacheTagsAfterCommit([
    ...,
]);
```

for raw writes that coexist with shared query caching.

---

# 59. Structured Schema Operations Should Invalidate Query Cache

Operations such as:

```text
drop table
rename table
alter columns
truncate
dropAllTables
```

can make cached rowsets stale or structurally invalid.

For structured SchemaManager APIs, DBLayer knows the affected table and should invalidate corresponding table tags after successful operation.

Raw DDL remains caller responsibility.

---

# 60. Driver Timeout Semantics Must Be Precise

Do not advertise all server-side timeout behavior as equivalent.

For example, MySQL's `max_execution_time` is specifically limited to read-only SELECT behavior in current MySQL documentation.

SQLite `busy_timeout` controls lock waiting, not general SQL execution time.

Distinguish:

```text
DBLayer client execution budget
driver-native timeout
lock wait timeout
hard cancellation
```

Do not claim a universal hard cancellation guarantee when the driver cannot provide one.

---

# 61. Read-Only Transaction Enforcement

Driver owns this behavior.

PostgreSQL transaction read-only mode has explicit transaction-state timing semantics.

Do not silently swallow every native enforcement failure and then imply the transaction is read-only.

Choose:

```text
best-effort mode
    → clearly report capability/enforcement result

strict mode
    → throw if read-only enforcement cannot be applied
```

At minimum ensure documentation says exactly what is guaranteed.

---

# 62. Move Replica Session Setup into Driver

Lower priority, but this finishes driver ownership.

Instead of external driver-name branching:

```php
$driver->configureReadSession(
    $pdo,
    enforceReadOnly: $flag,
);
```

can own:

```text
SQLite PRAGMA query_only
MySQL read-only session
PostgreSQL read-only session
```

Do not add another interface just for this.

---

# 63. Replica Reconnect Telemetry Must Distinguish Fallback

If replica connection fails and DBLayer silently falls back to the writer, do not emit telemetry that implies:

```text
read replica reconnect succeeded
```

Expose:

```text
replica selected
replica connection failed
writer fallback used
```

accurately.

---

# 64. Lifecycle Hook Exceptions

If lifecycle hooks are intentionally non-blocking:

```text
beforeConnect
afterConnect
beforeReconnect
afterReconnect
failure hooks
```

then hook exceptions should be captured into diagnostics rather than completely disappearing.

Do not let them affect connection semantics, but don't erase developer bugs.

---

# 65. Validate Pool Configuration

Validate:

```text
max_connections > 0
min_connections >= 0
min_connections <= max_connections
idle_timeout semantics
max_lifetime semantics
health_check_interval semantics
```

If zero means disabled, document that explicitly.

`min_connections` currently needs one of:

```text
implement it
remove it
```

Do not advertise ineffective settings.

---

# 66. Correct Pool Statistics

Define whether:

```text
created
closed
active
idle
```

refer to:

```text
Connection wrapper objects
or
actual opened PDO handles
```

Current lazy Connections make this distinction important.

Make statistics internally consistent.

---

# 67. Validate HealthCheck Configuration

Normalize once:

```text
check interval
max latency
max error rate
sample size
```

Requirements:

```text
sample size > 0
max error rate 0..1
durations non-negative/positive according to contract
```

---

# 68. Health Error Rate Should Be Rolling

If HealthCheck keeps a bounded recent sample buffer, calculate health error percentage from that buffer.

Do not combine:

```text
bounded recent samples
+
lifetime connection failure counters
```

in a way that makes ancient failures poison health indefinitely.

---

# 69. Unopened Connections and Health

A lazily created Connection with no PDO is not necessarily unhealthy.

Pool health handling should distinguish:

```text
never opened
currently open and healthy
currently open and failed
```

Do not evict a valid unused lazy Connection because `isHealthy()` interprets no PDO as failure.

---

# 70. `DB::raw()` Should Not Resolve a Connection

An `Expression` is connection-independent.

Use:

```php
DB::raw('NOW()');
```

without forcing the default connection to resolve.

Prefer:

```php
public static function raw(string $sql): Expression
```

rather than accepting arbitrary mixed scalar coercion.

---

# 71. Raw Fragment Validation Must Use Connection Security Config

Do not hardcode fragment limits such as:

```text
8192
256
2048
```

independently of normalized connection security.

Use:

```text
raw-fragment boundary validation
+
final whole-query validation
```

according to configured:

```text
max_sql_length
max_params
max_param_bytes
raw_sql_policy
```

---

# 72. Identifier Length Validation

Identifier length should apply per identifier segment, not to a complete:

```text
schema.table.column
```

string as one 64-character value.

Given current strict identifier grammar is ASCII-oriented:

```text
measure bytes consistently
validate each segment
driver-aware/conservative limit
```

Do not report “characters” while using byte length.

---

# 73. Structured Alias API

Documentation currently includes patterns like:

```php
DB::table('orders as o');
```

while strict identifiers reject spaces.

Do not weaken identifier validation.

Add structured aliases:

```php
DB::table('orders')->as('o');
```

and joins with explicit alias argument/API.

Internally represent:

```text
table = orders
alias = o
```

separately.

This also solves:

```text
prefix handling
cache dependencies
compiler correctness
identifier quoting
```

---

# 74. Validate Raw Allowlist Regex at Configuration Time

If a configured allowlist entry looks like a regex, verify it is a valid regex when ConnectionConfig is constructed.

Do not defer malformed regex handling to per-query matching.

---

# 75. One Canonical SQL Fingerprint Implementation

Current code has multiple shape/fingerprint behaviors.

Create one shared implementation used by:

```text
cache identity
telemetry
query logs
cursor binding
failure metadata
```

Requirements:

```text
strip only DBLayer-injected leading comment
normalize irrelevant whitespace
preserve literal content
do not lowercase string literals
stable deterministic hash
```

Lowercasing entire SQL text can collapse semantically different literal SQL.

Bound parameter values remain outside SQL shape.

---

# 76. Remove Generic `sanitizeInput()`

A database library does not need a generic “sanitize input” utility if it is not part of a meaningful SQL boundary.

Parameterized SQL is the primary input safety mechanism.

Remove unused/general security helpers.

---

# 77. Logger Binding Redaction

If docs say bindings are “redacted”, numeric and boolean binding values should not silently remain visible unless that behavior is explicitly documented.

Safer default:

```text
null → null marker
string → redacted/type-length
number → redacted/type
bool → redacted/type
resource → type marker
```

Allow full binding logging only through explicit debug opt-in.

---

# 78. Exception Trace Logging

Full exception stack traces can contain:

```text
filesystem paths
application implementation details
secrets in arguments depending on runtime/debug settings
```

Make trace export configurable.

Default external/PSR logging should remain operationally useful without dumping unnecessary sensitive context.

---

# 79. Validate Security Numeric Ranges

ConnectionConfig should reject/normalize invalid:

```text
max_sql_length
max_params
max_param_bytes
queries_per_second
queries_per_minute
```

Requirements:

```text
length/count limits > 0
rate limits >= 0
integer semantics only
no arbitrary negative values
no fractional numeric strings
```

If `0` means unlimited for a specific field, document and normalize it explicitly.

---

# 80. Reject Unknown Read Strategy

Configuration typo:

```php
'read_strategy' => 'round_robn'
```

must fail.

Do not silently turn it into `random`.

Validate against:

```text
random
round_robin
weighted
least_latency
```

once during config normalization.

---

# 81. Replica Config Must Fail on Invalid Entries

Do not silently drop malformed replica descriptors.

Validate every supplied element.

Examples:

```text
invalid host type
invalid weight
weight <= 0
non-array descriptor
unsupported built-in key
```

should produce an explicit configuration exception.

---

# 82. Repository `firstOrCreate()` Race

Current read-then-insert behavior is race-prone:

```text
request A SELECT none
request B SELECT none
request A INSERT
request B INSERT → unique error
```

Preferred:

```text
insert attempt
unique-conflict handling
reselect existing row
```

or a driver-appropriate atomic upsert strategy where semantic equivalence is exact.

A unique constraint remains necessary.

---

# 83. Repository `updateOrCreate()` Race

Same problem.

Prefer atomic upsert-like semantics where possible.

If cross-driver behavior cannot be made equivalent, document exactly how concurrency is handled.

---

# 84. `updateOrCreate()` Runs `beforeUpdate` Twice

Current primary-key update path can:

```text
cast
beforeUpdate
    ↓
call updateById()
    ↓
cast again
beforeUpdate again
```

This can:

```text
double-transform data
execute side effects twice
break non-idempotent hooks
```

Let one internal mutation layer own:

```text
casts
before hook
write
after hook
```

exactly once.

---

# 85. Define `create()` Return Contract

When DB-generated values cannot be read back, returning the input payload can be mistaken for a durable database row.

Decide explicitly:

```text
create() always returns persisted row
```

if DBLayer can guarantee it;

or document:

```text
returns submitted/normalized payload when database readback unavailable
```

A separate result method may be clearer.

Do not silently mix both semantics.

---

# 86. Define Hook Coverage

Current behavior differs between:

```text
create
bulkInsert
upsert
restore
optimistic update
```

Define exact lifecycle.

Since DBLayer is not an ORM, a minimal policy is preferable.

For example:

```text
create/update/delete hooks
    → only corresponding explicit repository operations

upsert
bulk
restore
    → documented dedicated/bypass behavior
```

Do not add dozens of ORM-like hooks unless required.

---

# 87. `findMany()` Key Identity

If input order/duplicates are preserved exactly, avoid conflating:

```text
1
"1"
```

through naive string map keys unless DB primary-key coercion intentionally defines them equivalent.

Use typed key encoding or document normalization behavior.

---

# 88. RelationLoader Query Counting

Do not derive `lastQueryCount()` from a global/shared Connection stats delta.

In coroutine/concurrent environments, unrelated queries can distort it.

Increment a local counter each time RelationLoader itself executes a query.

---

# 89. Many-to-Many Pivot Ordering

Documentation says pivot order is retained, but SQL without `ORDER BY` has no guaranteed row ordering.

Choose:

```text
remove the ordering guarantee
```

or add an explicit deterministic pivot order API.

Do not promise database-natural row order.

---

# 90. RelationLoader `one()` Determinism

Selecting “first” related row without explicit order is not deterministic.

Document:

```text
one() with multiple matches requires scope order for deterministic choice
```

or enforce an explicit ordering strategy.

---

# 91. Cursor Uses Legacy Query Fingerprint

Cursor query binding should use the final canonical compiler SQL/fingerprint, not legacy Grammar SQL.

Fix naturally as part of one-compiler migration.

---

# 92. Qualified Cursor Column Collision

Current result-key derivation can reduce:

```text
users.id
orders.id
```

both to:

```text
id
```

in a joined query.

Cursor ordering must map to a unique selected output key.

Require:

```text
selected alias
or structured order-expression result mapping
```

for ambiguous qualified columns.

---

# 93. Cursor Float Validation

Reject non-finite values:

```text
INF
-INF
NAN
```

before JSON encoding.

Wrap JSON encoding failures in a DBLayer QueryException rather than leaking raw `JsonException`.

---

# 94. Document Cursor Order-Column Cap

If DBLayer intentionally caps cursor ordering at eight columns, surface this limitation in API documentation.

---

# 95. Telemetry Must Store Logical Connection Name

Current telemetry can use driver name as connection identity, causing:

```text
main mysql
reporting mysql
```

to collapse into the same bucket.

Event data should contain separately:

```text
connection = logical DBLayer connection name
driver     = mysql/pgsql/sqlite
```

Query-shape reports should group by logical connection.

---

# 96. Telemetry Statement Classification

Do not independently parse a CTE into:

```text
statement = WITH
```

when DBLayer already has canonical query-type classification.

Use:

```text
CompiledQuery::type
SqlStatementInspector canonical fallback
```

---

# 97. Telemetry Buffers Should Use RingBuffer

Current front-removal through `array_splice()` is O(n).

DBLayer already has/uses bounded buffer concepts.

Use RingBuffer for:

```text
query telemetry
transaction telemetry
```

when retention is bounded.

---

# 98. Profiler Buffer Should Use RingBuffer

Same issue.

Avoid repeated O(n) shifting/splicing on long-running workers.

---

# 99. Profiler Global Start State Is Unsafe

One global:

```text
startTime
startMemory
```

is fragile for nested/overlapping/coroutine execution.

Prefer already-measured query event duration.

If memory deltas remain valuable, correlate by execution ID or remove the feature from the hot event bridge rather than maintain unsafe global timing state.

---

# 100. OTel-Like Scope Version

Do not hardcode:

```text
1.0.0
```

if it is supposed to represent DBLayer package version.

Either:

```text
derive actual version
```

or omit it.

---

# 101. Telemetry Exporter Lifecycle

Decide whether exporter callbacks are:

```text
process-global configuration
```

or:

```text
request-scoped state
```

If global, document it.

If scoped, reset them to prevent closure/object retention between worker requests.

---

# 102. Identify DBLayer Query Comments Explicitly

Fingerprint normalization should not strip every leading block comment supplied by an application.

Generate comments as something recognizable, e.g.:

```sql
/* dblayer app=api trace=... */
```

and strip only DBLayer-generated comment format.

---

# 103. Query Threshold Callback Isolation

`whenQueryingForLongerThan()` callback failure should not modify a successful query outcome.

Same observer isolation rules apply.

---

# 104. Event Statistics Naming

If `dispatched` counts event emissions rather than listener invocations, call/document it accordingly.

Avoid ambiguous operational metrics.

---

# 105. Event Subscriber Missing Methods

`subscribe()` should not silently ignore a configured subscriber method that doesn't exist.

Configuration errors should fail clearly during registration.

---

# 106. Event Queue Must Be Bounded or Removed

An unbounded process-global queue is risky in long-running PHP.

Either:

```text
bound it
```

or remove/deprecate queueing if it provides little database-layer value.

---

# 107. Static Event Listener Worker Lifecycle

Define which listeners are:

```text
process-global
request/job-scoped
```

`resetRuntimeState()` cannot blindly remove permanent integrations, but it also cannot allow request-specific closures to leak forever.

Consider scoped registrations/tokens rather than ambiguous global behavior.

---

# 108. Schema-Qualified `hasTable()` / `hasColumn()`

Catalog introspection must parse:

```text
schema.table
database.table
attached_schema.table
```

correctly per driver.

Do not send:

```text
table_name = 'public.users'
```

while independently filtering current schema.

Add tests for:

```text
PostgreSQL public.users
PostgreSQL custom_schema.users
MySQL explicit database.table
SQLite attached schema if supported
```

---

# 109. Schema DDL Cache Invalidation

Structured schema operations know their targets.

Invalidate query-result table tags after successful:

```text
drop
rename
alter
truncate
fresh/drop-all
```

as applicable.

Do not attempt it for arbitrary raw SQL.

---

# 110. `dropAllTables()` Error Preservation

If dropping fails and restoring foreign-key state also fails in `finally`, preserve the original destructive-operation exception.

Do not replace it with cleanup failure.

Report both.

---

# 111. Migration Lease Recheck After Migration Work

Current migration flow should re-check the lease after `up()` and before writing the migration ledger.

Required:

```text
checkpoint before migration
    ↓
migration up()
    ↓
checkpoint/refresh after migration
    ↓
ledger INSERT
```

Likewise rollback:

```text
checkpoint
down()
checkpoint
ledger DELETE
```

This prevents a runner that lost its lease during a long migration from writing authoritative ledger state afterward.

Long-running data migrations should still call `$context->checkpoint()` internally between chunks.

---

# 112. Migration Lock Release Must Not Mask Primary Error

In:

```php
try {
    migration work
} finally {
    release lock
}
```

a release failure can replace the original migration failure.

Preserve:

```text
primary migration exception
secondary release exception
```

and expose both appropriately.

---

# 113. Migration Lock Identity Needs Server/Schema Scope

A lock key containing only:

```text
driver
database
migration table
```

can collide across completely different database servers using one shared Redis lock service.

Use stable hash of non-secret identity:

```text
driver
host/socket identity
port
database
schema
ledger table
```

Do not include credentials.

---

# 114. Pretend/Migration Interaction

Once pretend mode is fixed not to update runtime state, ensure MigrationRunner preview inherits the corrected semantics.

---

# 115. Version-Sensitive DDL

Do not claim universal capability for operations that depend on actual database version.

SQLite ALTER support is especially version-sensitive.

Either:

```text
inspect server version where necessary
```

or allow native DB error with a clear DBLayer wrapper.

Do not create a massive speculative capabilities matrix.

---

# 116. MySQL TLS Hardening Semantics

Clarify whether:

```text
require_tls = true
```

means merely encrypted transport or verified server identity.

If `hardenProduction()` claims strong verification, ensure the configured CA/verification settings actually achieve it.

Otherwise document it as “encrypted transport required” rather than overstate verification guarantees.

---

# 117. Remove Generic `Support\Str`

It currently contains generic functionality far outside a DB package:

```text
camel
kebab
slug
plural
singular
random
limit
contains
startsWith
endsWith
...
```

Yet DBLayer mainly needs table-name normalization.

Remove it.

Keep a tiny ASCII-oriented DB-specific table normalizer.

This also eliminates current mbstring reliance.

---

# 118. Remove Legacy Compatibility Helpers

Once compiler migration finishes, remove transitional leftovers including:

```text
legacy Grammar/Executor accessors
backward-compat config aliases no longer needed
old exception aliases if unnecessary
legacy DB::batch positional inference
legacy comments/paths
```

Do not retain transition architecture indefinitely.

---

# 119. Redesign `DB::batch()`

The old positional array format is ambiguous.

Prefer explicit descriptors or dedicated operations.

For example:

```php
DB::batch([
    BatchStatement::statement(...),
    BatchStatement::insert(...),
]);
```

Only introduce a DTO/class if it pays for itself; a clearly keyed structured array could be enough.

Do not infer operation type from ambiguous numeric-array positions.

---

# 120. `resetRuntimeState(false)` Must Reset Existing Connection Runtime State

If keeping underlying connections open:

```php
DB::resetRuntimeState(false);
```

must still sanitize request/job-local state on each Connection.

It should mean:

```text
keep physical/live connection
reset logical request state
```

not:

```text
leave connection runtime context untouched
```

---

# 121. Correct DB Statistics

Do not present query-log entry count as authoritative total query count.

Separate:

```text
query_log_entries
```

from:

```text
actual connection execution count
```

Logging can be disabled or flushed independently.

---

# 122. Normalize `lastInsertId()` Return Type

Facade should delegate to Connection's normalized return contract.

Do not allow facade and connection methods to disagree between:

```text
string
false
null
```

without explicit semantics.

---

# 123. Dependency Versions in Documentation

Update every current baseline reference:

```text
ArrayKit 5.0 → 5.1
CacheLayer 3.0 → 3.1
```

Use:

```text
5.1 / 3.1
```

when describing DBLayer's minimum integrated versions.

Use:

```text
5.x / 3.x
```

only where documentation intentionally describes the major family rather than minimum package requirement.

---

# 124. Fix TableRepository API Documentation

Actual desired dispatch:

```text
1. Repository
2. QueryBuilder
```

Remove stale:

```text
3. DB facade
```

from API docs.

---

# 125. Remove Invalid TableRepository Infrastructure Examples

Documentation still contains examples such as:

```php
User::health();
User::capabilities();
User::version();
```

while infrastructure forwarding has been deliberately removed.

Use:

```php
DB::health();
DB::capabilities();
DB::version();
```

or:

```php
User::connection()->...
```

where appropriate.

---

# 126. Fix `max_parameter_count` Documentation

Use the actual key everywhere:

```text
security.max_params
```

Remove:

```text
security.max_parameter_count
```

---

# 127. Complete QueryBuilder API Reference

Add:

```text
collect()
lazyCollection()
cacheFor()
cacheTags()
cacheKey()
withoutCache()
```

and exact result contracts.

---

# 128. Complete Repository API Reference

Document:

```text
cacheFor()
findMany batching/order behavior
record/table/tenant cache tags
mutation invalidation rules
hook semantics
```

---

# 129. Architecture Documentation Must Match Reality

Until legacy Grammar is removed, do not claim every query already uses only CompiledQuery.

After removal, update architecture docs to explicitly show:

```text
QueryBuilder
→ QueryPayload
→ Driver Compiler
→ CompiledQuery
→ Connection
```

with no “Grammar/compiler” dual wording.

---

# 130. Replace Alias Examples

Every example like:

```php
DB::table('orders as o');
```

should use the new structured alias API.

---

# 131. Observability Documentation Cleanup

After Executor removal, remove references to:

```text
per-connection executor query logs
```

and describe the canonical Connection/typed-event pipeline.

---

# 132. Security Documentation Cleanup

Lead with:

```text
bound parameters
structured identifiers
explicit raw SQL boundaries
```

not regex injection detection.

Raw scanning is defense-in-depth, not the core protection model.

---

# 133. Caching Documentation

Explicitly document:

```text
raw write requires explicit invalidation
complex query without structural dependencies or explicit tags bypasses cache
cache hits do not connect to DB
active transaction bypasses cache
sticky write/read bypasses cache
lock/stream/lazy paths bypass cache
```

---

# 134. Document Final TRUNCATE Semantics

After redesign, document exact defaults:

```text
identity reset?
cascade?
transaction behavior?
driver differences?
```

Never leave destructive behavior implicit.

---

# 135. Benchmark Execution Subjects Must Skip Without SQLite

Current benchmarks can silently substitute:

```text
compile-only work
```

for subjects named:

```text
cache hit
select
stream
transaction
```

when PDO SQLite is unavailable.

That produces misleading benchmark data.

Execution subjects should explicitly skip.

Only compiler subjects should remain compile-only.

---

# 136. Add Cache Hit Size Benchmarks

Add:

```text
1 row
50 rows
500 rows
5000 rows
```

for:

```text
memory cache hit
uncached execution comparison where useful
validation-only vs old deep-copy behavior
```

---

# 137. Temporary Grammar-vs-Compiler Equivalence Suite

Before deleting legacy Grammar, compare:

```text
SQL
bindings
execution results
```

for every query family.

Cover:

```text
simple SELECT
DISTINCT
all WHERE variants
nested conditions
JOIN
joinSub
fromSub
CTE
recursive CTE
UNION
GROUP/HAVING
aggregate
locks
pagination
INSERT
INSERT IGNORE
RETURNING
UPDATE
DELETE
TRUNCATE
UPSERT
prefixes
aliases
raw fragments
```

Across:

```text
SQLite
MySQL/MariaDB
PostgreSQL
```

Delete the temporary compatibility tests after legacy implementation is removed.

---

# 138. Add Critical Regression Tests

At minimum add explicit tests for:

```text
insertGetId executes once
insertReturning mutation invalidates cache without returned ID
upsertReturning mutation invalidates with empty readback
truncate invalidates cache
truncate rollback preserves cache

whereIn([]) SELECT
whereIn([]) UPDATE
whereIn([]) DELETE
whereNotIn([])

cache hit without opening PDO
cache hit while DB unavailable
explicit cacheKey cannot cross-contaminate queries

CTE cache dependency
fromSub cache dependency
joinSub cache dependency
UNION cache dependency
raw child provenance propagation

observer exception after successful SELECT
observer exception after successful INSERT
observer exception during QueryFailed does not replace SQL exception
afterCommit exception does not suppress commit event

connection failure during write does not auto-retry
connection failure inside transaction does not reconnect and continue
transaction-level retry reruns entire callback only

commit failure state
rollback failure state
savepoint failure state
manager stats never negative

pool release with active transaction
pool release resets sticky/deadline/cancellation
pool rejects foreign connection
health check ignores borrowed connection

schema-qualified hasTable
schema-qualified hasColumn

migration lease lost during up
migration lease lost during down
lock release failure + migration failure

PostgreSQL truncate does not cascade by default
SQLite truncate identity contract

invalid read_strategy
invalid replica weight/config
invalid security numeric limits
invalid regex allowlist

updateOrCreate beforeUpdate runs once
firstOrCreate concurrency behavior

cursor qualified-order collision
cursor NAN/INF rejection
```

---

# 139. CI Matrix

Required:

```text
PHP 8.4
├── SQLite
├── MySQL
├── MariaDB
└── PostgreSQL

PHP 8.5
├── SQLite
├── MySQL
├── MariaDB
└── PostgreSQL
```

Minimum PHP remains:

```text
^8.4
```

Do not raise minimum merely because CI supports newer PHP.

Test with:

```text
ArrayKit ^5.1
CacheLayer ^3.1
PHPForge dev-main@dev
```

---

# 140. Documentation CI

Add:

```text
Sphinx build
PHP examples syntax
copy-paste example smoke tests where feasible
broken API-reference detection
```

Documentation is now broad enough that regressions should fail CI.

---

# 141. Performance Acceptance

Benchmark before and after final cleanup.

Primary hot paths:

```text
build select SQL
simple first()
primary-key read
uncached get()
raw select
typed runCompiled
single-column update
transaction two reads
events disabled
comments disabled
statement cache disabled
cache hit
cache miss
```

Investigate a sustained repeated median regression above approximately 2% when benchmark variance makes that threshold meaningful.

Never optimize a subsystem benchmark at the cost of full application correctness.

---

# 142. Memory Acceptance

Measure allocation/peak memory for:

```text
cache hits
Collection conversion
LazyCollection
bulk compilation
findMany
RelationLoader
telemetry
profiler
query logging
```

Particularly verify the final cache-hit path no longer rebuilds the result array.

---

# 143. Network Cache Benchmarks

Do not put Redis/Valkey transport performance into PHPBench microbenchmarks.

Test CacheLayer network adapters with:

```text
integration tests
concurrency/load tests
real serializer/network configuration
```

DBLayer only needs to verify its interaction contract.

---

# 144. Final Query Architecture

Target:

```text
QueryBuilder
    ↓
structured QueryPayload
    ├── source/aliases
    ├── child payloads
    ├── dependency graph
    ├── raw provenance
    └── bindings
    ↓
Driver QueryCompiler
    ↓
CompiledQuery
    ├── exact SQL
    ├── exact bindings
    ├── QueryType
    └── required provenance
    ↓
cache eligibility
    ├── bypass
    └── CacheLayer 3.1 remember()
             ├── hit → return without PDO
             └── miss
                    ↓
            Connection::runCompiled()
                    ↓
                   PDO
                    ↓
             one typed event
```

---

# 145. Final Write Architecture

```text
structured mutation
    ↓
compile once
    ↓
execute exactly once
    ↓
Mutation outcome
    ├── affected rows
    ├── returned rows if available
    └── last ID if available
    ↓
schedule canonical table tags
    ↓
outer transaction commit
    ↓
CacheLayer 3.1 invalidateTags()
```

Never couple:

```text
mutation success
```

to:

```text
returned data exists
```

---

# 146. Final Transaction Architecture

```text
managed transaction state
    ↓
PDO operation succeeds first
    ↓
internal level/state transition
    ↓
callbacks/events
```

Rules:

```text
no automatic write retry after ambiguous connection loss
no reconnect-and-continue inside transaction
deadlock/serialization retry at whole transaction level
no duplicate public unmanaged/managed transaction semantics
observer failures never rewrite transaction reality
```

---

# 147. Final Pool Architecture

```text
Pool checkout
    ↓
tracked Connection only
    ↓
application use
    ↓
release
    ↓
sanitize for reuse
    ├── rollback/evict active tx
    ├── reset sticky
    ├── reset timeout/deadline
    ├── reset cancellation/retry
    ├── reset comments/listeners/scoped state
    └── restore mutable defaults
    ↓
health-check idle only
    ↓
return to idle
```

---

# 148. Final Cache Architecture

```text
compile exact query
    ↓
non-connecting cache eligibility
    ↓
canonical DB/query identity
    ↓
canonical dependency tags
    ↓
CacheLayer 3.1 remember()
```

Automatic tags only when dependencies are structurally known.

No SQL parsing.

No hidden Redis logic.

No adapter-specific DBLayer cache architecture.

---

# 149. Final Security Model

Primary protections:

```text
structured identifiers
bound values
structured compiler
explicit Expression/raw boundary
driver-aware capabilities
TLS policy
query/binding limits
```

Defense in depth:

```text
raw SQL heuristic scanner
raw fragment allow/deny policy
rate limiter
cursor signing
```

Never market heuristic pattern matching as proof against SQL injection.

---

# 150. Final Utility Ownership

## ArrayKit 5.1

Owns:

```text
Collection
LazyCollection
ArrayShape
generic array/data utilities
generic configuration mechanics
```

## CacheLayer 3.1

Owns:

```text
cache adapters
tiering
tags
remember
stampede protection
locks
serialization/security
metrics
```

## DBLayer

Owns:

```text
SQL structure
compiler
PDO lifecycle
driver behavior
transactions
query cache semantics
dependency invalidation
repositories
relations
schema
migrations
database security
database observability
```

Anything outside those DB responsibilities should need a strong reason to remain in DBLayer.

---

# 151. Implementation Order

## Phase 1 — Immediate Correctness Blockers

Resolve first:

```text
insertGetId double-write
insertReturning/upsertReturning invalidation
truncate invalidation
empty whereIn semantics
cache identity mismatch
cacheKey semantics
cache hit opening PDO
write/query retry safety
transaction state ordering
afterCommit event ordering
observer isolation
raw helper QueryType validation
PostgreSQL truncate cascade
pool transaction sanitation
mbstring dependency
```

Do not begin architectural polish before these have regression tests.

## Phase 2 — Transaction + Pool Hardening

Fix:

```text
TransactionManager stats
nested transaction savepoint guarantees
single managed transaction surface
rollback-failure preservation
Connection disconnect state
pool membership validation
idle-only health probes
pool config validation
health rolling statistics
worker reset behavior
```

## Phase 3 — Complete QueryPayload

Implement:

```text
structured aliases
CTEs
recursive CTEs
subqueries
structured joins
unions
all conditions
dependencies
raw provenance propagation
full mutation representation
```

## Phase 4 — Compiler Cutover

1. Grammar-vs-compiler equivalence tests.
2. Cross-driver execution.
3. Switch `toSql`, cache, cursor and execution to same compiler.
4. Delete legacy fallback.
5. Remove legacy Grammar ownership.
6. Simplify/remove Executor.

## Phase 5 — Cache Finalization

Fix:

```text
scoped internal tags
schema-qualified identities
caller tag validation
complex dependency behavior
raw-write documentation
SchemaManager invalidation
cache-hit allocation
```

## Phase 6 — Repository / Relation / Pagination

Fix:

```text
firstOrCreate race
updateOrCreate race
double hooks
create return contract
hook coverage
RelationLoader local query count
pivot ordering docs/API
one() ordering
cursor result-key ambiguity
cursor finite scalar validation
```

## Phase 7 — Security / Config / Driver Details

Fix:

```text
fragment limits
identifier segments
regex allowlist validation
numeric security limits
read strategy validation
replica config validation
read-only enforcement reporting
replica fallback telemetry
TLS wording
```

## Phase 8 — Schema / Migration

Fix:

```text
qualified introspection
DDL cache invalidation
dropAll error preservation
post-migration lease checkpoint
release-error preservation
server-scoped lock key
version-sensitive DDL validation
```

## Phase 9 — Observability

Unify:

```text
logical connection identity
canonical fingerprint
canonical statement type
RingBuffer telemetry
RingBuffer profiler
single query measurement
observer error isolation
exporter lifecycle
bounded event queue
```

## Phase 10 — Cleanup

Remove:

```text
Support\Str
sanitizeInput
legacy batch inference
legacy query compiler/Grammar code
unused metadata
unused compatibility aliases
dead methods/imports/comments
```

## Phase 11 — Dependencies / Docs

Upgrade:

```text
ArrayKit ^5.1
CacheLayer ^3.1
```

Keep:

```text
PHPForge dev-main@dev
```

Synchronize all documentation and examples.

## Phase 12 — Release Validation

Run:

```text
PHPForge complete checks
unit tests
cross-driver integration tests
compiler equivalence
cache tests
migration tests
pool/worker tests
PHPBench
memory comparisons
Sphinx build
example smoke tests
```

---

# 152. Release Blockers Checklist

Do not release until every item below passes:

```text
[ ] ArrayKit ^5.1
[ ] CacheLayer ^3.1
[ ] PHPForge dev-main@dev unchanged

[ ] insertGetId can never write twice
[ ] mutation invalidation is independent of returned payload
[ ] truncate invalidates cache
[ ] PostgreSQL truncate cannot CASCADE accidentally
[ ] empty whereIn cannot remove mutation WHERE clause

[ ] cache identity is exact executed CompiledQuery
[ ] cacheKey cannot cross-contaminate SQL shapes
[ ] cache hit does not require PDO
[ ] complex dependencies are known or cache is bypassed
[ ] raw provenance recursively propagates

[ ] no automatic ambiguous write retry
[ ] no reconnect-and-continue inside transaction
[ ] transaction state changes after DB finalization
[ ] rollback failure does not corrupt state silently
[ ] commit event survives afterCommit callback failure

[ ] observer exception cannot turn successful SQL into failure
[ ] failed-query observer cannot mask original SQL exception

[ ] typed raw helpers enforce QueryType
[ ] identifier/boolean/join/CTE/window validation complete

[ ] pooled connection cannot retain active transaction or request state
[ ] pool rejects foreign objects
[ ] health checker never probes borrowed connection

[ ] ext-mbstring is no longer accidentally required

[ ] compiler covers every QueryBuilder shape
[ ] toSql/cache/cursor/execution use same compiler
[ ] legacy Grammar fallback removed

[ ] driver parameter limit is centralized
[ ] findMany/RelationLoader/bulk use same limit

[ ] schema-qualified introspection works
[ ] migration lease checked after migration work

[ ] updateOrCreate hooks execute once

[ ] cursor joined-column ambiguity handled

[ ] logical connection telemetry is correct
[ ] bounded buffers are efficient

[ ] SQLite integration passes
[ ] MySQL integration passes
[ ] MariaDB integration passes
[ ] PostgreSQL integration passes
[ ] PHP 8.4 passes
[ ] PHP 8.5 passes

[ ] Sphinx docs build cleanly
[ ] benchmark results contain no unexplained hot-path regression
```

---

# 153. Definition of Done

DBLayer is finished when:

1. **ArrayKit 5.1** and **CacheLayer 3.1** are the explicit current ecosystem baselines.
2. PHPForge remains exactly `dev-main@dev`.
3. Every builder query is structurally represented before SQL compilation.
4. One driver compiler produces the exact SQL used everywhere.
5. No legacy Grammar fallback remains.
6. Every database mutation executes exactly once unless an explicitly safe retry contract says otherwise.
7. Empty predicates cannot accidentally broaden mutations.
8. Transactions cannot report a state different from actual PDO state.
9. Connection loss cannot transparently break transaction guarantees.
10. Observability cannot alter successful database behavior or mask database failures.
11. Cache hits can return without establishing PDO.
12. Cache keys always include actual query semantics.
13. Automatic cache dependencies are complete or caching is conservatively bypassed.
14. Every structured mutation invalidates correct cache dependencies after durable commit.
15. Raw SQL remains explicit and does not trigger heuristic table parsing.
16. Pool reuse cannot leak transaction/request state.
17. Configuration fails on typos rather than silently changing behavior.
18. Driver-specific destructive behavior is explicit.
19. Repository concurrency behavior is deterministic/documented.
20. Schema and migration locking remain correct under failure.
21. Telemetry identifies logical connections correctly.
22. All bounded long-running buffers remain bounded efficiently.
23. No accidental generic/string helper or undeclared extension dependency remains.
24. Documentation matches actual API and runtime semantics.
25. PHP 8.4/8.5 and every supported database driver pass the release matrix.
26. The common uncached query path remains performance-neutral or better unless a measured correctness feature justifies the cost.

---

# 154. Explicit Non-Goals for This Release

Do not expand into:

```text
ORM behavior
Active Record
implicit relationship loading
identity map
dirty tracking
unit of work
automatic schema tuning
automatic indexes
automatic partitioning
adaptive replica AI/load prediction
new database drivers
CacheLayer adapter wrappers
automatic Redis/Valkey setup
database SQL parser
migration filesystem discovery
framework-specific bootstrap
```

Resolve the architecture and correctness first.

---

# Final Direction

This should be the last broad redesign pass for this major.

The finished project should converge to:

```text
small default hot path
+
strict structured SQL
+
one compiler
+
one execution engine
+
transaction correctness
+
CacheLayer 3.1 correctness
+
ArrayKit 5.1 reuse
+
explicit optional advanced behavior
+
cross-driver proof
```

After the blocker list and Definition of Done are clean, further changes should be driven by real production measurements or specific missing capabilities—not another general architecture rewrite.