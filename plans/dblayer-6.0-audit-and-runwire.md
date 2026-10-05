# DBLayer 6.0 consolidated audit feedback and release plan

Updated: 2026-10-05 (Asia/Dhaka). **Release blocked.**

This file consolidates the initial library audit, updated-code review, Runwire
integration requirements, worker-pool/configuration proposals and release gates.
Initial baseline: `087f179ecac3e5555c346ce84cfc353050f8e3cb` (5.1).
Latest reviewed candidate: `a33cbe7b955a10add97170592394f101e4dfefce`, PR #32.
The latest review began with a clean working tree and changed no production
code or test assertions. Evidence is tied to these revisions; this consolidation
does not claim a new code verification run.

The audit is risk-based: repository-wide detectors, existing tests, Graphify
navigation, source inspection and focused adversarial probes. It does not claim
every source line or deployment was exhaustively verified.

The governing policy is
[PHPForge engineering-principles.md](../vendor/infocyph/phpforge/resources/engineering-principles.md)
and its [agent workflow](../vendor/infocyph/phpforge/resources/AGENTS.md).
Correctness, security, transaction integrity and bounded resource lifetimes
come before throughput. Keep fixes inside existing owners where practical;
justify every new production type. Do not weaken quality gates, add baselines,
exclude failing code or edit vendor files.

## Implementation tracker

Last synchronized: 2026-10-05. PR: #32. **Release blocked after rechecking
`a33cbe7b955a10add97170592394f101e4dfefce`.** Exact-revision CI is green, but
new adversarial probes reopen correctness and lifecycle gates. The open findings and reproducible evidence are consolidated below.

| Batch | Scope | Status | Evidence / next gate |
| --- | --- | --- | --- |
| A | D01-D06 — policy, tenancy, cache isolation and durable mutation correctness | **Reopened** | R01: scoped upsert modifies/reassigns foreign-tenant rows. R04-R05: cross-scope invalidation and native-commit cache policy need follow-up. Existing focused/schema tests still pass. |
| B | D07-D12 — cancellation, cursor/lease lifetime, native reset, LIKE and memory bounds | **Reopened** | R02-R03: live cursors survive lease release; iterator cancellation/binding coverage is incomplete. Existing focused/native-timeout tests still pass. |
| C | D13 — PHPForge/tooling and dependency compatibility | **Complete** | Skip scanner/configuration are fixed and the full PHPForge QA/analyzer matrix is green. Production audit is clean. The PHPBench 1.7.0 → `doctrine/annotations` development-tool warning is explicitly accepted for this release and is not a DBLayer release blocker. |
| D | Optional Runwire 2.1.1 integration | **Reopened** | Existing instance/capability integration passes; R03 iterator lifetimes and R06 warmup readiness require regressions/fixes. Proposed timeout/maintenance contracts below remain unimplemented or uncertified. |
| E | Docs, performance/soak, downstream consumers and final CI | **Open** | QA/analysis/component benchmarks/consumer smokes pass on `a33cbe7`. R07: representative benchmark validation/comparison steps are skipped; the 300-iteration unit regression is not the required sustained host soak. Revalidate exact final revision after fixes. |

### Batch A item tracker

| ID | Status | Exit requirement |
| --- | --- | --- |
| D01 | Complete | Structured aggregate identifiers validated; injection regressions pass on every compiler path. |
| D02 | Reopened | Payload conflicts are rejected, but scoped upsert can update/reassign another tenant's existing conflict row (R01). |
| D03 | Reopened | Visibility keys are isolated; mutation dependency tags must invalidate shared data across visibility scopes (R04). |
| D04 | Follow-up required | Native reads bypass cache and afterCommit rejects unmanaged ownership; a competing reader can refill before native commit (R05). Preserve/document the explicit caller-owned policy or provide enforceable integration. |
| D05 | Complete | Schema invalidation uses the exact passed Connection/cache owner. |
| D06 | Complete | Completed mutations record durable outcome before late budget failure and cannot leave stale cache/sticky state. |

Tracker statuses are updated only from committed code and verification evidence; a code change alone is not marked complete until its required regression/QA evidence exists.

### Batch B item tracker

| ID | Status | Exit requirement |
| --- | --- | --- |
| D07 | Reopened | Cache/nested cancellation regressions pass; row-level lazy cancellation and deferred stream binding remain incomplete (R03). |
| D08 | Complete | Active streaming statements are never reused from the prepared-statement cache; cleanup releases ownership. |
| D09 | Reopened | Scoped callbacks reject Traversable escape, but a live cursor can survive explicit lease release and unsafe wrapper reuse (R02). |
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
- Initial D01-D12 fixes are implemented with focused regressions; the current
  findings reopen the composition cases listed in the tracker above.
- Optional Runwire integration is implemented through passed host-owned runtime/request/scope
  objects; DBLayer never starts or stops the host runtime.
- Worker-local pools support explicit synchronous `warmUp()`; healthy PDO handles are
  reused across requests and request-local runtime state is sanitized on release.
- `docs/upgrade-6.0.rst` documents the CacheLayer 4 floor, changed tenancy/cache/
  transaction/lazy-lifetime contracts, worker pooling and Runwire composition.
- Persistent-worker lifecycle regressions and candidate downstream smokes for
  Foundation and ReqShield are part of the release branch. Sustained host soak
  remains open (R07).

## Verification evidence and limits

- Exact candidate [PHPForge CI](https://github.com/infocyph/DBLayer/actions/runs/37340922191)
  succeeds across PHP 8.4/8.5 stable/lowest QA and analysis, benchmark execution,
  and clean install. Job metadata confirms configured service startup steps;
  raw job-log retrieval returned empty files, so this recheck does not independently
  inspect live-service participation assertions inside those logs.
- Exact candidate [consumer smokes](https://github.com/infocyph/DBLayer/actions/runs/37340921121)
  succeed for Foundation and ReqShield. PR #32 remains open; no merge/tag was
  performed in this review.
- Focused local regression suite: 26 passed / 116 assertions, including Batch
  A/B, Runwire integration, pool runtime isolation and persistent lifecycle.
- `composer ic:tests`: 459 passed / 2323 assertions and 3 failed. The failures
  are explicit missing local PostgreSQL/MySQL service prerequisites in the
  cross-driver and migration integration tests. The host also lacks
  `pdo_sqlsrv`. They are not presented as product regressions or silently skipped.
  Skip scanner, normalize, syntax, references, duplicates, comments, Pint,
  PHPCS, Deptrac, PHPStan, Psalm and Rector all pass.
- `composer validate --strict` and platform requirements pass after refreshing
  the ignored local lock to the declared cognitive-complexity 1.2.0 pin.
  Production audit: zero advisories and zero abandoned packages. Full dev audit:
  zero advisories, accepted PHPBench → doctrine/annotations abandonment warning.
  That accepted warning is not reopened as a blocker.
- Eight adversarial SQLite probe outcomes are saved in
  [review-probes.jsonl](evidence/2026-10-05-update-review-probes.jsonl), with the
  [reproduction harness](evidence/reproduce-update-review.md). These are
  diagnostics, not new green-suite assertions or live-server certification.

Historical evidence for the original 5.1 audit is retained in
[original probe outcomes](evidence/2026-10-05-audit-probes.jsonl) and the
[original reproduction harness](evidence/reproduce-audit.md). Those records
predate the fixes; they do not describe the current candidate. Completed
remediation details are summarized in the tracker instead of repeated as open
findings.

## Open findings

Priorities describe remediation order and release risk, not CVSS scores.
Each finding includes its owner, observed behavior and required follow-up.

| ID | Priority | Remaining issue | Related audit item |
| --- | --- | --- | --- |
| R01 | High | Scoped upsert modifies or takes another tenant's row | D02 |
| R02 | High for worker reuse | Active stream survives lease release and unsafe pool reuse | D09 |
| R03 | Medium | Runwire binding/cancellation does not cover the full iterator lifetime | D07 |
| R04 | Medium | Security-scoped tags miss shared-table mutations | D03 |
| R05 | Medium | Native-transaction cache refill can stay stale after commit | D04 |
| R06 | Medium | Warmup reports expired handles as ready | Worker-pool contract |
| R07 | Release gate | Representative performance and sustained host soak remain uncertified | Batch E |

### R01 — High: scoped upsert can modify or take another tenant's row

Owner: [Repository::upsert](../src/Query/Repository.php#L976).
The new checks validate the incoming tenant attribute, but the SQL conflict
update is not constrained by the repository's tenant WHERE predicate.

Reproduction: seed row id=7, tenant_id=2. Call tenant 1's repository with
`upsert(['id'=>7,'value'=>'changed'], ['id'], ['value'])`: it accepts the call
and changes tenant 2's value. Omit the update-column list and it also changes
the row's tenant_id from 2 to 1. Both reproduced against real SQLite PDO.

Fix the conflict-update ownership boundary, not just the payload. The database
must enforce the tenant condition atomically, or the scoped API must reject
unsafe conflict targets/driver paths explicitly. A pre-read alone is insufficient
under concurrency. Including tenant_id in a declared conflict target does not
automatically make MySQL's unrelated unique-key conflicts safe. Test existing
foreign-tenant rows, both update-list forms, bulk calls and real driver-specific
conflict behavior, including MySQL/MariaDB and SQL Server. Never reassign the
tenant column in a scoped conflict update.

### R02 — High for worker reuse: an active stream survives pool release

Owner: [Connection::resetRuntimeStateForReuse](../src/Connection/Connection.php#L927).
The reset rejects transactions but does not reject or settle
`activeStatementCursors`. Start a stream, consume row 1, release its explicit
lease, then checkout another lease: SQLite returns the same Connection while
the old generator can fetch row 2 and the new borrower executes a query.

Callers should keep the lease for the iterator lifetime, as documented. The
library nevertheless knows this cursor remains active and must prevent unsafe
idle reuse. Refuse reuse/discard or explicitly close and fence live cursors;
cover partial and abandoned streams and native unbuffered-driver behavior.
This does not require claiming that arbitrary retained bare references can be
fully fenced.

### R03 — Medium: Runwire iterator lifetime is only partially enforced

Owners: [lazyByIdGenerator](../src/Query/Concerns/QueryBuilderResults.php#L191)
and [Connection::stream](../src/Connection/Connection.php#L1221).
`lazyById(chunkSize:3)` captures the passed binding for database batches, but
yielding buffered rows has no checkpoint. Cancel after row 1: row 2 is yielded.
Direct `stream()` is itself a deferred generator and does not capture the
binding at creation. Return it from `withRunwire()`, complete the request, then
iterate: all three rows are read with the restored, unbound connection policy.

Capture and validate the exact binding through supported iterator lifetimes,
check before each row/batch/fetch as required by the plan, or reject unsupported
deferred escape explicitly. Preserve nested policies and iterator/lease
ownership; cover both ordinary generators and ArrayKit collections, cancellation
inside a buffered batch and completion before first iteration. Review the
PostgreSQL server-cursor FETCH loop too: it currently lacks query checkpoints.

### R04 — Medium: security-scoped cache tags miss shared-table mutations

Owner: [Connection::cacheTableTag](../src/Connection/Connection.php#L408).
The table dependency tag includes the full result visibility fingerprint,
including username/cache_scope. Two same-database, same-name connections share
one cache but have scopes `reader` and `writer`. Warm the reader, mutate the
same row through the writer: reader cache returns `old`, direct SELECT returns
`new`. A write invalidates only the writer's visibility scope.

Keep visibility isolation in result keys while giving physical/logical table
dependencies a shared invalidation identity across roles/scopes that access
the same data. Preserve isolation between independent databases/deployments
and between actual schemas. Verify cross-role/RLS and explicitly qualified
cross-schema mutations on PostgreSQL as well as shared-backend cache adapters.

### R05 — Medium: pre-commit invalidation does not certify native transactions

Owner: [invalidateQueryCacheTagsAfterCommit](../src/Connection/Connection.php#L726).
With two connections sharing a cache, begin a native PDO transaction on A and
perform a structured update. Immediate tag invalidation lets B repopulate the
old committed row before A commits. After native commit the cached value stays
`old` while direct SELECT returns `committed`.

The upgrade guide correctly says native-transaction callers must own their
commit/cache policy. Keep that limitation explicit: the early invalidation is
not a substitute for a commit hook. Either provide explicit owner integration
or reject unsupported cache-aware native writes until that policy is supplied.
Tests must cover another reader filling during the transaction and commit/
rollback; the existing single-connection rollback test cannot prove this gate.

### R06 — Medium: warmup can report expired handles as ready

Owner: [Pool::warmUp](../src/Connection/Pool.php#L372).
The initial ready count checks only whether PDO is non-null and returns before
the pool's expiry/health rules run. An already-expired idle handle still makes
`warmUp(target:1)` return 1. The next checkout removes it and returns a new lazy
wrapper with no open handle. The diagnostic ages the existing idle timestamp
through Reflection to make expiry deterministic; it does not mock PDO.

Reconcile eligible idle expiry/health and actual ready counts before claiming
the warm target is reached. Never probe an active borrower. Verify cleanup and
capacity changes during warmup, failed health/release, server disconnect and
replenishment after expiry with real servers. Keep host startup/maintenance
budgets and explicit per-endpoint allocation open until implemented/verified.

### R07 — Release gate: representative performance and soak are not certified

Owner: [release tracker](#implementation-tracker).
The exact candidate's benchmark jobs pass, but both
`ic:benchmark:validate` and `ic:benchmark:compare` steps are SKIPPED on PHP 8.4
and 8.5 because representative result/baseline inputs are empty. PHPBench
completion proves component coverage, not the plan's 5.1-versus-candidate
sustained successful-RPM budget.

The persistent-worker unit test passes 300 sequential SQLite iterations; the
focused local suite, including that test, completes in about 0.15 seconds.
It is a valuable lifecycle regression, not the planned sustained concurrent
host soak with continuous process-tree RSS/socket/queue measurements,
cancellation, tenant changes and deployment overlap. No matching production-
equivalent baseline/candidate result artifacts or representative host soak
evidence were found in the repository or configured CI gates inspected here.
External results, if they exist, still need to be linked and checked against
the candidate and the stated budgets.

Retain the acceptance requirements, record reproducible results, and run the
comparison only on an explicitly stable matched environment. Do not weaken
the gates or equate green microbenchmarks with representative RPM acceptance.

## Runwire and worker-pool contracts

The passed-instance integration is implemented; R02-R03, R06 and the remaining
measurement/lifecycle gates must close before release acceptance. Runwire supplies execution context, cancellation/deadline and
coroutine primitives; its capability enum provides no asynchronous PDO API.
Do not promise nonblocking database I/O from a context binding or fiber alone.

The existing Connection API is
`withRunwire(RuntimeContext $runtime, callable $callback,
?RequestContext $request = null, ?CoroutineScope $scope = null): mixed`.
The host/intermediary passes the same binding into an exclusively leased
Connection. Repositories/builders
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
and later expiry/removal does not continuously replenish the minimum.
`Pool::warmUp()` and `PoolManager::warmUp()` now explicitly open distinct primary
handles. R06 remains open because their initial ready count includes expired
idle handles. Live-server warmup, replenishment and bounded maintenance still
require the acceptance evidence below.

- Use the existing instance warmup operation on the pool owner, invoked by
  the host after worker/PID/generation creation and before readiness. Keep
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

| Batch | Remaining work | Required exit evidence |
| --- | --- | --- |
| A | R01, R04, R05: conflict ownership and cache commit/invalidation boundaries | Failing-before/passing-after regressions; real conflict-update drivers; PostgreSQL role/RLS/schema and competing native-transaction readers |
| B | R02, R03: live cursors, leases and iterator context | Partial/abandoned streams, cancellation within batches, completed-request escape and safe reuse across supported drivers |
| C | Preserve completed tooling/dependency gates | Unsuppressed PHPForge checks, latest/lowest constraints, clean production install; accepted development warning stays visible |
| D | R06 and retained worker/configuration requirements | Accurate ready counts, expiry/disconnect recovery, bounded host-owned maintenance and configuration behavior documented/tested |
| E | R07, consumer checks and final CI | Matched representative baseline/candidate results, sustained host soak, current consumers and exact-final-SHA evidence |

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
Release remains blocked until the reopened findings and required acceptance
gates are closed with exact-final-revision evidence.

Update the existing upgrade guide as fixes close, covering CacheLayer 4 installation/configuration,
tenant payload conflicts, structured aggregate validation, cache identity
version/cold transition, native transaction constraints, iterator ownership,
late mutation outcomes and optional Runwire examples for both composition chains.
Tag only after every required batch/gate is closed on the immutable final SHA.
Keep open work/evidence in this plan; remove completed remediation history as
batches close. No commit, merge, release or deployment is part of this audit.
