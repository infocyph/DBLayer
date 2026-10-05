# DBLayer 6.0 audit and optional Runwire integration plan

Date: 2026-10-05 (Asia/Dhaka). Status: remediation required; release blocked.

Audited source: `087f179ecac3e5555c346ce84cfc353050f8e3cb` (current 5.1 line),
plus the requested dependency-floor and matching documentation changes.
This is a risk-based whole-library audit using repository-wide detectors,
existing tests, Graphify navigation, source inspection and focused adversarial
probes. It is not a claim that every source line or deployment was exhaustively
verified. Production PHP contains 162 source files.

The governing policy is
[PHPForge engineering-principles.md](../vendor/infocyph/phpforge/resources/engineering-principles.md)
and its [agent workflow](../vendor/infocyph/phpforge/resources/AGENTS.md).
Correctness, security, transaction integrity and bounded resource lifetimes
come before throughput. Keep fixes inside existing owners where practical;
justify every new production type. Do not weaken quality gates, add baselines,
exclude failing code or edit vendor files.

## Implementation tracker

Last synchronized: 2026-10-05. PR: #32. **All batches are complete and the DBLayer 6.0 candidate is release-ready.**

| Batch | Scope | Status | Evidence / next gate |
| --- | --- | --- | --- |
| A | D01-D06 — policy, tenancy, cache isolation and durable mutation correctness | **Complete** | Exact Batch A candidate `ea61258`: focused regressions and live PostgreSQL schema isolation pass. |
| B | D07-D12 — cancellation, cursor/lease lifetime, native reset, LIKE and memory bounds | **Complete** | Focused regressions pass; live PostgreSQL timeout sanitation is covered; full PHP 8.4/8.5 stable/lowest QA is green on `b7520c0d`. |
| C | D13 — PHPForge/tooling and dependency compatibility | **Complete** | Skip scanner/configuration are fixed and the full PHPForge QA/analyzer matrix is green. Production audit is clean. The PHPBench 1.7.0 → `doctrine/annotations` development-tool warning is explicitly accepted for this release and is not a DBLayer release blocker. |
| D | Optional Runwire 2.1.1 integration | **Complete** | Passed-instance binding, strict nested budgets, pre-connect cancellation, CacheLayer sharing, ArrayKit lazy propagation, coroutine retry sleep, concurrent task isolation, worker replacement and explicit pool warmup are covered. DBLayer does not take over host lifecycle. |
| E | Docs, performance/soak, downstream consumers and final CI | **Complete** | 6.0 upgrade/runtime docs, representative PHPBench coverage, persistent-worker soak, Foundation/ReqShield candidate smokes and the complete PHP 8.4/8.5 stable/lowest PHPForge matrix pass on immutable candidate `ea4e6105`. This tracker-only status update changes no production/test behavior. |

### Batch A item tracker

| ID | Status | Exit requirement |
| --- | --- | --- |
| D01 | Complete | Structured aggregate identifiers validated; injection regressions pass on every compiler path. |
| D02 | Complete | Tenant conflicts/reassignment rejected after casts/hooks across repository write APIs. |
| D03 | Complete | Versioned cache scope includes schema/security identity; PostgreSQL isolation regression covered. |
| D04 | Complete | Native PDO transactions bypass result cache without opening PDO merely to test eligibility; native after-commit ownership is explicit. |
| D05 | Complete | Schema invalidation uses the exact passed Connection/cache owner. |
| D06 | Complete | Completed mutations record durable outcome before late budget failure and cannot leave stale cache/sticky state. |

Tracker statuses are updated only from committed code and verification evidence; a code change alone is not marked complete until its required regression/QA evidence exists.

### Batch B item tracker

| ID | Status | Exit requirement |
| --- | --- | --- |
| D07 | Complete | Warm cache hits, nested cancellation, deadlines, stream/batch and retry checkpoints preserve the strictest active budget. |
| D08 | Complete | Active streaming statements are never reused from the prepared-statement cache; cleanup releases ownership. |
| D09 | Complete | Scoped pool callbacks reject escaping Traversable/lazy results and always release the lease. |
| D10 | Complete | Pool reset restores native timeout state; SQLite and live PostgreSQL regressions pass. |
| D11 | Complete | LIKE escaping is single-pass and literal wildcard/backslash semantics are verified. |
| D12 | Complete | DBLayer-owned private caches are discarded on reuse; caller-owned shared caches survive reset. |

## Changes already applied

- `composer.json`: ArrayKit floor `^5.2` → `^5.3` and CacheLayer `^3.4` → `^4.0`.
- Installation and caching documentation now match those requirements.
- Installed ArrayKit 5.3 (`5f7265555d2906176411b21283381d376aa9e53e`) and
  CacheLayer 4.0 (`58ae96dfe14ee45a528247c00c6a3d727160f833`). ArrayKit was
  already installed at 5.3; the update replaced CacheLayer 3.4 with 4.0.
- PSR Log remains `^3.0.2`, its latest stable release. PHP remains `^8.4`,
  covering the declared PHP 8.4/8.5 matrix. PHPForge remains its existing
  `dev-main@dev` development constraint.
- The local ignored `composer.lock` was refreshed. This library does not track
  its lock file; consumers resolve the declared ranges independently.
- Runwire `2.1.1` is declared in `require-dev` for the integration test target
  and in `suggest` for optional production adoption.
- D01-D12 production correctness fixes are implemented with focused regressions.
- Optional Runwire integration is implemented through passed host-owned runtime/request/scope
  objects; DBLayer never starts or stops the host runtime.
- Worker-local pools support explicit synchronous `warmUp()`; healthy PDO handles are
  reused across requests and request-local runtime state is sanitized on release.
- `docs/upgrade-6.0.rst` documents the CacheLayer 4 floor, changed tenancy/cache/
  transaction/lazy-lifetime contracts, worker pooling and Runwire composition.
- Persistent-worker soak coverage and candidate downstream smokes for Foundation and
  ReqShield are part of the release branch.

## Current verification evidence

| Check | Result and limit |
| --- | --- |
| PHPForge QA | PASS on verified candidate `b7520c0d`: PHP 8.4/8.5, prefer-stable and prefer-lowest; Pest, Pint, PHPCS, Deptrac, Rector, syntax, references, duplicates, comments and skip scanner all green |
| Static analysis | PASS on PHP 8.4 and PHP 8.5; PHPStan and Psalm both green without local suppression/baseline weakening |
| PHPBench | PASS on PHP 8.4 and PHP 8.5; benchmark suite covers indexed reads, buffered/streamed rows, cache hit/miss, structured writes/upsert, transactions, relations, cursor pagination, statement reuse and runtime lifecycle |
| Clean install | PASS; production install does not require Runwire |
| Live database matrix | PHPForge provisions MySQL, MariaDB, PostgreSQL, SQL Server and SQLite plus configured replica/availability-group topologies; Batch A/B live PostgreSQL regressions pass |
| Persistent-worker soak | PASS inside the full Pest matrix: repeated Runwire request lifecycles, distinct cache keys, pooled reuse and bounded memory/connection state |
| Downstream consumers | PASS on candidate `b7520c0d`: ReqShield DBLayer bridge and Foundation DBLayer runtime/query-cache/persistent lifecycle tests with DBLayer 6 + CacheLayer 4 candidate constraints |
| Production Composer audit | 0 advisories and 0 abandoned production packages |
| Development Composer graph | PHPBench 1.7.0 requires abandoned `doctrine/annotations` 2.0.2; this development-tool warning is explicitly accepted for DBLayer 6.0 and does not block release |
| PHPForge audit policy | No DBLayer-side suppression or weaker local configuration was introduced; the accepted development-tool warning remains visible in Composer output |
| Final immutable SHA | PASS on `ea4e6105`: all PHPForge matrix jobs succeeded; Foundation and ReqShield downstream smokes succeeded. The subsequent tracker-only status commit changes no production/test behavior and is revalidated by PR checks. |

The PHPBench 1.7.0 transitive `doctrine/annotations` warning is explicitly
accepted for this release. It remains visible as tooling debt, but it is not a
DBLayer 6.0 release blocker. All required DBLayer batches and repository-owned
acceptance gates are complete.

## Required findings

Priorities are remediation order and release risk, not assigned CVSS scores.
Security impact depends on consumer trust boundaries; no remote application
exploit was attempted.

| ID | Priority | Finding | Existing owner |
| --- | --- | --- | --- |
| D01 | High | Aggregate function text bypasses structured SQL/raw-deny policy | QueryBuilderResults, QueryBuilderInternals, AbstractSqlCompiler |
| D02 | High | Tenant-scoped writes accept a different tenant in the payload | Repository, RepositoryInternals |
| D03 | High | Result-cache identity omits PostgreSQL schema and database role/security scope | QueryBuilderCaching, Connection |
| D04 | High | Native PDO transaction can read/populate the shared result cache | QueryBuilderCaching, Connection, Transaction |
| D05 | High | Schema cache invalidation uses the static DB facade instead of its exact connection | SchemaManager |
| D06 | High | A committed write can become a timeout failure before sticky state/invalidation | Connection execution lifecycle, QueryBuilder mutation lifecycle |
| D07 | High for Runwire adoption | Cache hits bypass cancellation/deadlines; nested cancellation replaces parent | QueryBuilderResults, Connection |
| D08 | Medium | Prepared-statement reuse closes a live streaming cursor | Connection statement cache and streaming |
| D09 | Medium; prerequisite for lazy Runwire work | Generator can escape pooled callback and execute after lease release | PoolManager, ConnectionLease, ConnectionStreaming |
| D10 | Medium | Pool reset clears timeout metadata but leaves native timeout configured | Connection reset and timeout synchronization |
| D11 | Medium | LIKE escaping doubles newly inserted escape characters | SecurityValidator |
| D12 | Medium for persistent workers | Private memory result cache retains expired distinct keys without a capacity bound | Connection private-cache lifecycle; CacheLayer owns adapter eviction |
| D13 | Release blocker | QA configuration, skipped coverage and abandoned dev dependency prevent complete acceptance | PHPForge dependency/configuration and repository integration setup |

### D01: aggregate SQL policy

Reproduced on SQLite with `security.raw_sql_policy=deny`:
`$connection->table('items')->aggregate('MAX(42) FROM items --')` returns `42`.
The setter only checks for an empty string; the compiler interpolates the
function text into `SELECT`, and the query retains generated-SQL provenance.
This becomes an injection path if consumers forward untrusted function text.

Validate aggregate function names and columns at their existing public boundary.
Support legitimate function identifiers deliberately, including qualified names
only where the dialect contract allows them. SQL expressions must go through
the existing explicit raw-expression policy. Keep raw provenance accurate and
test deny/allowlist mode, comments, parenthesis injection and ordinary aggregates
on every compiler. Review other interpolated structured fields alongside this
fix; do not assume generated provenance makes unchecked strings trusted.

### D02: tenant write integrity

Reproduced: an instance-first repository scoped with `forTenant(1)` successfully
creates `['id'=>1, 'tenant_id'=>2, ...]`. The insert does not apply read WHERE
predicates, and tenant enrichment only fills a missing payload column.
`updateById()` also forwards tenant-column changes without a tenant invariant.

Reject conflicts with the active tenant and reject tenant reassignment through
scoped writes. Recheck the final payload after casts/hooks. Cover create, batch
insert, first/update-or-create, upsert, update and optimistic updates through
both repository APIs. Apply database-compatible comparison semantics without
silently changing tenant identity. Explicit trusted cross-tenant operations
must use the existing unscoped API with application authorization.

### D03: cache isolation

Key derivation was tested without contacting PostgreSQL: two connections named
`main`, database `app`, schemas `tenant_a`/`tenant_b`, and roles `role_a`/`role_b`
compile identical unqualified SQL and derive identical result keys. Their table
tags differ, so current tagging does not repair the colliding result key.
Real PostgreSQL schema/RLS data leakage remains a mandatory live regression.

Define one versioned, non-sensitive cache-scope identity including the effective
schema and caller-declared security/tenant scope. Use it consistently for reads
and invalidation. Do not include plaintext credentials or depend on physical
replica indexes. Keep equivalent replicas in the same logical database scope.
For independent deployments sharing a backend, retain the documented distinct
namespace/logical-name requirement or add an explicit deployment identity.
Session `SET ROLE`/search-path/RLS changes require explicit cache scope or cache
bypass; configuration username alone cannot describe dynamic session policy.
Use a collision-resistant fingerprint for adversarial/security-sensitive cache
identities and keep tag/key lengths within CacheLayer contracts. Test cache-key
version migration with a cold old namespace; do not flush unrelated host data.

### D04: unmanaged transaction/cache boundary

Reproduced using the public `getPdo()->beginTransaction()`: cached committed
rows hide transaction-local changes; a cold cached query publishes uncommitted
rows that remain readable after native rollback. Only managed nesting is checked.

Bypass cache reads and fills whenever an already-open PDO handle has an active
transaction. Do not open a PDO connection merely to check a cache hit. Define
native-transaction behavior for structured-write invalidation and after-commit
callbacks: reject unsupported ownership explicitly or integrate through an
explicit transaction owner; never execute deferred effects as if committed.
Test begin/commit/rollback, savepoints, cached/cold queries and external PDO use.

### D05: schema invalidation ownership

Reproduced with a direct Connection and its private query cache: warm a query,
drop its table with `new SchemaManager($connection)`, reset request state, then
the same cached query still returns the old row. SchemaManager calls
`DB::invalidateCacheTagsAfterCommit()` by name; with no facade cache it does
nothing, and with a separately configured facade it can use the wrong owner.

Schedule invalidation through the passed connection and its exact attached
backend, matching the existing QueryBuilder mechanism. Cover create, alter,
rename, drop, drop-all, transactional DDL rollback and migration execution while
an unrelated same-named facade connection/cache is registered.

### D06: completed mutation versus late timeout

A SQLite AFTER UPDATE trigger delayed completion by about 5 ms under a 1 ms
budget. The UPDATE committed, but DBLayer threw a query-timeout error, recorded
no sticky write, skipped builder invalidation, and returned old cached rows on
the next read. An application retry can duplicate a completed mutation.

Record durable execution outcome before evaluating a post-execution budget.
Ensure completed writes retain sticky state and invalidate their dependencies.
Define a distinguishable committed/late or uncertain outcome if a timeout is
still reported; never imply rollback or automatically retry a completed write.
Preserve transaction semantics, original PDO error metadata and exception causes
through executor wrapping so retry classification does not depend on messages.
Test delayed autocommit, managed transaction rollback, commit/listener failure,
read deadlines and cache failures without executing a write twice.

### D07: cancellation and deadlines

Warm `cacheFor(60)->get()` returns rows under an always-cancelled checker and an
expired deadline. Nesting `withQueryCancellation(false-checker)` inside an
always-cancelled outer wrapper allows `select 1`. Deadline wrappers already
take the minimum; cancellation currently replaces its parent.

Compose cancellation with logical OR and preserve/restores bindings in finally.
Check at cache access, before database connection/acquisition and execution,
before each stream/batch fetch and retry delay. Recheck before returning a
cached result where backend access can block. Use monotonic duration/deadline
accounting; retain compatibility for the existing absolute wall-clock setter
through a clearly defined boundary. Do not relabel a committed mutation as
cancelled. Ordinary synchronous PDO cannot always be interrupted mid-call.

### D08-D10: resource lifetime and sanitation

- D08: with statement caching enabled, start a two-row stream, run an identical
  select, then advance the stream: the second row disappears. A cache hit calls
  `closeCursor()` on the active statement. Track active cursor ownership and
  prepare independently or bypass reuse while in use. Cleanup must release the
  busy mark even on partial iteration, errors and cancellation. Test two streams,
  reentrant callbacks, cache eviction and buffered/unbuffered driver limitations.
- D09: `PoolManager::using('main', fn($c)=>$c->stream('select 1'))` releases the
  lease before iteration. A second checkout gets the same Connection while the
  escaped generator remains executable. Define supported lazy lease ownership:
  consume inside the callback, reject escaping connection-bound results, or keep
  a lease explicitly for the lifetime of a supported iterator. Test abandoned
  iterators, partial consumption, cancellation and request completion. Do not
  claim tokenized release checks fence every retained bare Connection reference.
- D10: setting timeout 123 ms then `resetRuntimeStateForReuse()` leaves SQLite
  `pragma busy_timeout=123`, although the wrapper reports null. Reset/synchronize
  native session timeout policy before reuse, or discard on unsafe reset. Cover
  PostgreSQL statement_timeout and driver-specific controls with live services.
  Preserve immutable host connection configuration; do not clear unrelated
  global caches, listeners or other requests' state during lease release.

### D11-D12: escaping and persistent memory

- D11: `sanitizeLikePattern('a%b')` returns two backslashes before `%`; a prepared
  SQLite `LIKE ... ESCAPE '\'` no longer matches the literal `a%b`. Escape input
  backslashes before adding wildcard escapes, preferably with a single mapping.
  Test %, _, backslashes, mixed patterns and each dialect's escape semantics.
- D12: populate 100 distinct `cacheKey()` values with a one-second TTL and wait
  for expiry: all 100 entries remain in the private ArrayCacheAdapter store.
  TTL does not bound distinct-key growth; collection happens when that key is
  revisited. Define a bounded request/task lifetime for library-created private
  caches, or use a caller-provided bounded backend for cross-request caching.
  Do not flush caller-owned shared caches during reset. Adapter-level eviction
  belongs in CacheLayer, not a duplicated DBLayer cache engine. Include distinct
  keys, large rows, expired entries and failure recovery in persistent-worker soak.

### D13: tooling and coverage

The installed cognitive-complexity extension 1.3.0 rejects PHPForge's
`dependency_tree` and `dependency_tree_types` keys. Fix the version/configuration
ownership in PHPForge or its compatible dependency set; do not remove analysis
rules or add a local weaker configuration merely to turn the gate green.

Skip scanner findings occur at RestoredModulesIntegrationTest.php:55,
tests/Pest.php:98, ConnectionConfigurationTest.php:291 and
RegressionFixesTest.php:1373/1399. Provision required engines and extensions,
make enabled service failures fail explicitly, and replace unnecessary conditional
skips with valid tests. Ensure the intended engine/topology inventory is exercised;
silently dropping unavailable drivers from a dataset is not release evidence.

Resolve PHPBench's abandoned dependency at its tooling owner through a supported
upstream version or PHPForge-maintained migration. Production dependencies have
no abandoned packages. Retain the live full-audit result as an open dev-tooling
gate; do not disable abandoned-package checks.

## Optional Runwire 2.1.1 design

Accept the integration after D01-D13 are fixed and representative measurement
supports it. Runwire supplies execution context, cancellation/deadline and
coroutine primitives; its capability enum provides no asynchronous PDO API.
Do not promise nonblocking database I/O from a context binding or fiber alone.

Start with a minimal passed-instance API on the existing Connection owner,
conceptually `withRunwire(RuntimeContext $runtime, callable $callback,
?RequestContext $request = null, ?CoroutineScope $scope = null): mixed`.
This is a proposed API, not a currently callable method. PoolManager may pass
the same binding into an exclusively leased Connection. Repositories/builders
inherit their exact Connection's binding; no framework-specific adapter is
required for framework → DBLayer or framework → another library → DBLayer.

1. The host passes its active RuntimeContext and optional request/task scope.
   Validate request-runtime identity, active request status, current scope,
   PID and worker generation. Construct/open DB/cache resources after fork.
2. Bind to that execution's exclusively owned Connection. Nesting must preserve
   the stricter existing cancellation/deadline and restore prior state in finally.
   One mutable Connection must not be concurrently borrowed by different tasks.
   Static DB/Security/Events/Telemetry state is not a task-local service registry.
3. Automatically apply request and scope cancellation and the earliest deadline.
   Runwire deadlines use monotonic nanoseconds/remainingSeconds(); never pass
   that value directly to DBLayer's wall-clock `setQueryDeadlineAt()`.
4. Use `CoroutineScope::sleep()` for bounded reconnect/transaction backoff only
   with an active compatible scope and RUNWIRE_COROUTINES capability. Bound
   total attempts, delay and acquisition by the existing operation budget.
   Propagate terminal cancellation rather than retrying it or falling back again.
5. Forward the exact objects to ArrayKit 5.3 LazyCollection::withRunwire(). Its
   row checkpoints cooperate with cancellation/yielding. DBLayer also needs
   checkpoints before PDO work and between bounded fetches; an ArrayKit row
   checkpoint alone cannot prevent a first/batch database fetch after expiry.
   Bind lazy execution for its full iterator lifetime and reject traversal after
   request completion. Prefer keyset batches when they release resources safely.
6. CacheLayer 4.0 owns adapter/atomic/locking semantics. The host owns its
   RunwireIntegration::bind()/release() lifecycle. Borrow share() only when the
   already bound runtime matches; never replace another host's global binding.
   DBLayer must enforce its own query/cache budgets regardless. CacheLayer's
   ordinary remember()/lock polling still uses synchronous providers: do not
   claim its Runwire sharing automatically makes cache operations cooperative.
   Measure bounded lock waits and identify any upstream provider work separately.
7. Capability absence selects the normal PDO/cache/iteration/backoff path.
   If an active request still supplies cancellation/deadline, preserve those
   controls even when coroutine yielding is unavailable. Invalid bindings fail
   explicitly; they are not capability absence. No runtime discovery in hot loops.
8. Never start/stop/reload workers, supervisors, listeners, event loops or
   Runwire::run(). Never close, cancel, complete or join host-owned scope/request
   objects. Release only DBLayer-owned leases/cursors and bindings.

Runwire is a Composer suggestion for production and an exact 2.1.1 development
requirement for the integration test target. Test the absent-package production
install separately. A normal DBLayer install must not require Runwire.
Avoid a parallel runtime abstraction or PDO replacement architecture. A new
internal binding type is justified only if it enforces independent lifetime or
invariants more clearly than fields/private methods on the current owner.

### Worker-lifetime database connection reuse

When the passed RuntimeContext identifies a persistent worker, support keeping
healthy PDO handles open across multiple requests/tasks in that worker. The
host constructs and retains one configured PoolManager per worker generation,
after fork, and passes exclusive leases down the framework/library chain.
Do not construct a new pool per request. Reuse does not require enabling
`PDO::ATTR_PERSISTENT`: an ordinary live PDO object can remain in the worker's
pool. Existing Pool/PoolManager already retain healthy handles on lease release;
the Runwire feature must compose with them rather than add another pool.

- Sequential executions can reuse the same healthy connection. Concurrent
  executions acquire separate exclusive leases; no simultaneous PDO/transaction
  use by independent requests, tasks or iterators.
- Keep connection lifetime separate from request state. Before another borrower
  receives a handle, finish cursors, clear request cancellation/deadline/comment
  bindings and sticky state, and restore native timeout/session policy. Discard
  active/uncertain transactions or handles whose cleanup cannot be proven safe.
  Explicitly cover tenant-specific role/search-path/RLS settings, temporary
  tables, session variables and locks; reconnect when safe restoration is not
  available. D03 and D07-D12 remain prerequisites for certified reuse.
- Keep finite configurable idle timeout and maximum lifetime with bounded health
  checks and safe reconnect. Pool exhaustion remains bounded/fail-fast unless
  an explicit cancellation-aware, deadline-bound wait is supported. Health or
  maintenance work must not probe or rotate another execution's leased handle.
- Size capacity across all workers and connections, reserving server capacity
  for administrative work. The current `max_connections` bounds Connection
  wrappers across names, not necessarily physical sockets: a wrapper may hold
  both primary and replica PDO handles. Budget and measure actual database
  connections per endpoint as well as lease counts.
- Keep pools local to the owning PID/generation. Do not inherit open PDO sockets
  across fork or share them between processes. On host-invoked drain/shutdown
  or worker replacement, settle or discard DBLayer leases and close its pool;
  DBLayer does not stop the host worker or event loop.
- Normal nonpersistent operation retains its existing path. Selecting Runwire
  by package presence alone must not enable worker-lifetime pooling.

Acceptance: verify identical PDO/server connection identity across sequential
leases, distinct active identities for concurrent leases, tenant/session reset,
idle disconnect recovery, lifetime rotation, pool saturation, cancelled work,
partial streams and worker replacement. Measure reconnect-per-request versus
warm-worker reuse under the same request mix. Reuse should be accepted for
sustained successful RPM and stability, not inferred from fewer connects.

### Pre-opened worker pool

Support an explicitly warmed service pool: for example, open 10 connections at
worker startup and grow on demand up to 50. These are configurable example
values, not new universal defaults. A worker with sequential database work may
need fewer connections; measure representative sustained successful RPM and
database contention before selecting capacity.

Current `Pool::addConfig()` creates up to `min_connections` lazy Connection
wrappers subject to the pool-wide maximum. It does not establish PDO handles,
and later expiry/removal does not continuously replenish the minimum. Do not
describe this as ten pre-opened database connections. A local SQLite probe on
2026-10-05 verified zero initially open handles for min=10/max=50, ten distinct
handles after explicit opening, ten retained idle wrappers after release, and
reuse of an opened handle on the next lease. This verifies the existing
mechanism, not live-server warmup or Runwire integration.

- Add an explicit instance warmup operation on the existing pool owner, invoked
  by the host after worker/PID/generation creation and before readiness. Keep
  package loading and ordinary constructors free of eager database I/O.
- Warm distinct handles for the requested logical connection/endpoint; ten
  sequential checkout/release calls can repeatedly open the same handle.
  Primary and replica warm targets must be explicit and counted separately.
  Do not claim a warm replica target was met after falling back to primary.
- Reconcile a configured minimum of ready handles during bounded host-invoked
  maintenance, replacing expired/unhealthy idle handles within capacity and
  startup/maintenance budgets. Define the minimum as ready total handles,
  including active leases; it must not require ten spare idle handles in
  addition to the active ones. Never evict or probe another task's active lease.
- Validate that minima across configured endpoints fit the maximum physical
  connection budget. If one pool serves multiple names, make target allocation
  explicit; existing pool-wide capacity does not guarantee every name's minimum.
- Stagger or bound connection creation across workers. Cap attempts and honor
  passed cancellation/deadlines during warmup and replenishment. Report actual
  ready counts and failures; require the host to choose failed readiness or
  explicitly degraded startup when the target cannot be reached.
- Retain finite maximum lifetime and safe idle expiry. Replenishment maintains
  ready capacity without promising that any particular socket survives forever.
  The host may invoke maintenance using its existing lifecycle facilities;
  DBLayer must not install or own a background worker/event loop.

Capacity is per worker and database endpoint: four workers with 10 ready handles
use 40 connections; four workers with a maximum of 50 can use 200, before
replicas, other services and deployment overlap. Preserve server headroom.

Acceptance: verify live-server connection identities/counts before readiness,
growth from 10 to the configured cap under concurrent leases, bounded excess
demand, replenishment after expiry/server disconnect, failure recovery without
connection storms, and complete host-invoked drain. Compare cold/lazy startup
with pre-opened pools for first-request latency and sustained successful RPM;
include worker replacement and overlapping deployments in the capacity test.

### Connection lifecycle and timeout configuration

Extend the existing configuration owners rather than introduce a second
Runwire-only connection configuration. The host retains the worker-local pool;
connection policy also works on the normal path. Names marked proposed below
are design targets, not currently accepted bootstrap options.

| Owner | Setting | Current support and proposed contract |
| --- | --- | --- |
| Pool | `min_connections`, `max_connections` | Existing limits: defaults 1 and 10; account separately for physical primary/replica sockets. |
| Pool | `idle_timeout` | Existing seconds, default 60; retire an idle handle after this interval. Zero currently disables expiry. |
| Pool | `max_lifetime` | Existing seconds, default 3600; rotate old handles safely after release, never interrupt an active lease. Zero currently disables rotation. |
| Pool | `health_check_interval` | Existing seconds, default 30; interval for eligible idle-handle checks. This does not install a background timer or keep sockets alive indefinitely. |
| Pool | `acquire_timeout_ms` | Proposed; default 0 preserves fail-fast exhaustion. Positive values require bounded, cancellation-aware waiting without taking over the host scheduler. |
| Connection | `connect_timeout_ms` | Proposed explicit connection-establishment budget. Preserve the existing driver-dependent `timeout` option and document conflicts, supported precision and driver mappings. |
| Connection | `query_timeout_ms` | Proposed configuration default for existing query-budget APIs; scope overrides must restore the previous policy before reuse. State which drivers provide native interruption and which only observe elapsed time at checkpoints. |
| Connection | `lock_wait_timeout_ms` | Proposed driver-aware maximum lock wait, distinct from a query timeout or deadlock detection delay. Restore native session settings before returning a lease. |
| Transaction operation | Total budget and attempts | Proposed explicit operation budget; keep existing attempts=1 as the default. Only opt-in whole-transaction retries, capped by the existing maximum of 3 and the remaining request/operation budget. |

Idle expiry is a retention policy, not TCP keepalive. Do not add an ambiguous
`keep_alive_time` that promises either scheduled database pings or portable
socket keepalive. Document driver-native network options separately when
supported. Pool maintenance remains lazy or explicitly invoked by the host.

Validate units, numeric ranges and unknown options at the configuration
boundary. Specify disabled/unset semantics separately for each setting; do not
assume zero means the same thing for pool waiting and lifetime rotation.
Reject contradictory legacy/new timeout values and unsupported requests for
hard interruption. Do not silently turn a finite requested bound into an
unlimited native timeout when converting milliseconds to a driver's units.
Use monotonic elapsed-time budgets; the earliest applicable host deadline,
operation deadline and configured limit wins. Retries never replenish a budget.
Blocking PDO and user callbacks cannot be universally preempted by Runwire;
describe native bounds and cooperative checkpoints accurately.

Deadlocks require transaction recovery, not a `kill_on_deadlock` connection or
worker flag. The database detects the deadlock and selects a victim. InnoDB
rolls back the entire victim transaction; a lock-wait timeout usually rolls back
only the statement. PostgreSQL aborts one transaction to resolve a deadlock.
Preserve SQLSTATE/vendor error details and normalize the driver's resulting
transaction state before propagation or retry. Default to propagating the
failure after safe cleanup. An explicitly retryable transaction callback must
be safe to repeat, including effects outside the database; use bounded backoff
and cancellation checks. Retain a healthy connection after successful recovery;
discard it when rollback/session cleanup fails or its state remains uncertain.
Never kill the host's Runwire worker or automatically replay an autocommit
write whose commit outcome is ambiguous (D06).

Acceptance: validate configured and scoped policy composition, milliseconds to
native-unit conversion, unsupported-driver behavior, request expiry during pool
wait/retry, restored settings across sequential borrowers, and live two-session
deadlock/lock-wait tests on supported servers. Verify default fail-fast behavior,
opt-in retry limits, cleanup-failure discard, and no duplicate durable mutation.

References: [MySQL InnoDB error handling](https://dev.mysql.com/doc/refman/8.4/en/innodb-error-handling.html)
and [PostgreSQL explicit locking](https://www.postgresql.org/docs/current/explicit-locking.html).

## Execution order and acceptance gates

| Batch | Work | Required exit evidence |
| --- | --- | --- |
| A | D01-D06: policy, tenancy, cache isolation and durable mutation correctness | Regressions reproduce before fix, pass after; live PostgreSQL schema/RLS/native transactions; DDL owner tests |
| B | D07-D12: budgets, cursor/lease ownership, native reset, LIKE and memory | Direct and pooled regressions; all drivers' cursor/reset behavior; no expired-state reuse |
| C | D13 and CacheLayer 4/ArrayKit 5.3 compatibility | Valid unsuppressed full PHPForge gates; latest/minimum supported dependencies; full runtime and dev audit policy resolved |
| D | Optional passed Runwire integration | Present/absent and missing-capability paths; direct/transitive/nested composition; concurrent tasks and worker replacement; no host lifecycle takeover |
| E | Docs, representative performance, soak, consumer tests and final CI | Exact-final-SHA evidence and reproducible release record |

For each code batch run PHPForge processors sequentially, targeted regressions,
then required detailed/full checks. Do not change assertions or detectors to
hide unresolved findings. Test PHP 8.4 and 8.5 with lowest/latest supported
runtime dependencies and optimized production `--no-dev` installation.
Use real MySQL/MariaDB/PostgreSQL/SQL Server services, replicas/AG and SQLite;
verify expected service/topology participation and fail on unintended skips.

Runwire composition tests must cover identical object identity through an
intermediary, mismatch rejection, completed requests, pre-connect cancellation,
cache hit/miss, nested policy calls, retry wakeup cancellation, partial/abandoned
streams, pool exhaustion, stale leases and shutdown with an active transaction.
Use at least two concurrently active task scopes and tenant identities.
Run downstream smoke tests in Foundation and at least one consumer such as
Omnibus/ReqShield using their actual public DBLayer APIs and injected cache.

Establish a before-change baseline from the audited 5.1 SHA and its released
dependency ranges. Also measure a dependency-only candidate and the fixed
candidate to separate dependency and runtime-integration cost. Reuse existing
PHPBench subjects for diagnosis; add a representative host workload rather than
claiming RPM from their timings. Include indexed single/multi-row reads,
cache hit/miss, structured writes, transactions, relation loading, pagination,
bulk operations and bounded streams with realistic cardinality/skew.

Use production PHP/Composer/OPcache settings without Xdebug, matching server and
native client versions, warmed state, at least three sustained trials and a
measured concurrency curve. Record successful RPM/RPS, response correctness,
errors/timeouts, p50/p95/p99, CPU, peak/steady RSS, connections, query count,
cache hit rate, lock/transaction duration and queue depth/growth. Compare
ordinary 5.1 versus ordinary candidate first; report Runwire-bound mode separately.
For stable matched environments enforce at most 2% median successful-RPM
regression, with a documented variance envelope and workload-specific latency,
memory/error budgets. Do not accept throughput that changes correctness.

Soak persistent workers under sustained concurrency and distinct-key churn,
including cancellation/retry failures, tenant changes, deployment generation
replacement and abandoned iterators. Capture continuous process-tree memory and
connection peaks with worker lifecycle logs; replacement-worker RSS must not
mask earlier growth. Define host-specific duration and capacity budgets before
acceptance. No unbounded memory/queue growth or connection/cursor/transaction leak.

## Release recommendation

Target 6.0 for the combined release: the requested `^4.0` CacheLayer floor drops
the supported 3.x dependency line, and enforcing tenant/SQL/lifetime invariants
may reject previously accepted unsafe calls. A transitive dependency's major
number alone is not a SemVer rule; this recommendation accounts for consumer
Composer conflicts and the concrete contract tightening together.

If urgent security fixes must ship independently, use a narrowly scoped 5.1.1
patch on the old compatible dependency range, with its own regression and CI
evidence. An additive Runwire-only change could fit 5.2 if no compatibility is
dropped, but that is not the requested combined dependency-floor candidate.
The completed candidate is release-ready after all required batches and repository-owned acceptance gates pass.

Publish an upgrade guide covering CacheLayer 4 installation/configuration,
tenant payload conflicts, structured aggregate validation, cache identity
version/cold transition, native transaction constraints, iterator ownership,
late mutation outcomes and optional Runwire examples for both composition chains.
Tag only after every required batch/gate is closed on the immutable final SHA.
Keep open work/evidence in this plan; remove completed remediation history as
batches close. No commit, merge, release or deployment is part of this audit.
