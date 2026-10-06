# DBLayer 6.0 consolidated audit feedback and release plan

Updated: 2026-10-06 (Asia/Dhaka). **Required remediation implemented; runtime certification recorded. Current branch checks govern release acceptance.**

This file consolidates the initial library audit, updated-code review, Runwire
integration requirements, worker-pool/configuration proposals and release gates.
Initial baseline: `087f179ecac3e5555c346ce84cfc353050f8e3cb` (5.1).
Committed base: `0493ea56fe7978e7233be156032097afa0f27dc6`, PR #32.
Remediation implementation: `244a4b71e06bfa0efb139bf5dd4c2d04a42e7064`.
The subsequent scanner correction changes a test prerequisite guard and this
tracker. Runtime evidence below applies to the immutable implementation; the
[current PR checks](https://github.com/infocyph/DBLayer/pull/32/checks) govern
the final branch revision. Earlier hosted results on the base do not certify it.

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

Last synchronized: 2026-10-06. PR: #32. Certified implementation:
`244a4b71e06bfa0efb139bf5dd4c2d04a42e7064`.

The original adversarial cases remain covered. R08-R10 are fixed and R07's
corrected host comparison and actual five-minute soak pass on the certified
implementation. The scanner correction passes the full local release guard.
All current PR workflows must pass before release. No merge, tag or deployment
has been performed.

| Batch | Scope | Status | Evidence / next gate |
| --- | --- | --- | --- |
| A | Tenant ownership and cache correctness | **Implemented; verified locally** | R09 qualified/unqualified/joined PostgreSQL invalidation and warm-cache identity migration pass. |
| B | Budgets, cursors and lease lifetime | **Implemented; verified locally** | R08 direct/pooled/unbuffered transitions, nested policies, post-fetch cancellation and restoration pass. |
| C | Tooling/dependency compatibility | **Release guard passes; current matrix enforced** | Zero advisories and no skip directives. Current PHP 8.4/8.5 stable/lowest QA and clean install are mandatory. Accepted development-only doctrine/annotations warning remains non-blocking. |
| D | Passed Runwire and worker pool | **Implemented; sustained host checks pass** | Five-minute soak proves idle/maximum-age expiry, bounded native retries and iterator fencing under load. Proposed extra configuration below is deferred, not required by these fixes. |
| E | Performance, soak, consumer and final CI | **Implementation certified; current branch CI enforced** | Revised evidence/native RSS/duration and 5.1 budgets pass; Foundation/ReqShield pass. Require current PR workflows on the final branch revision. |

### Batch A item tracker

| ID | Status | Exit requirement |
| --- | --- | --- |
| D01 | Complete | Structured aggregate identifiers validated; injection regressions pass on every compiler path. |
| D02 | Complete | Tenant-scoped upsert rejects unsafe conflict targets, forbids tenant-column reassignment and rejects MySQL/MariaDB scoped upsert where unrelated unique-key conflicts cannot be constrained safely. |
| D03 | Implemented; verified locally | Qualified dependencies share tags across default schemas; unqualified tables remain schema-specific. Result identity rotates with dependency identity to bypass legacy cached entries. |
| D04 | Complete | Native reads bypass result cache and cache-aware DBLayer writes inside externally owned native PDO transactions are rejected before mutation; callers retaining raw PDO ownership must own their cache policy. |
| D05 | Complete | Schema invalidation uses the exact passed Connection/cache owner. |
| D06 | Complete | Completed mutations record durable outcome before late budget failure and cannot leave stale cache/sticky state. |

### Batch B item tracker

| ID | Status | Exit requirement |
| --- | --- | --- |
| D07 | Implemented; verified locally | Captured bindings and currently active row/fetch policies compose; direct, pooled and unbuffered cancellation/deadline transition regressions pass. |
| D08 | Complete | Active streaming statements are never reused from the prepared-statement cache; cleanup releases ownership. |
| D09 | Complete | Scoped callbacks reject Traversable escape; active/deferred streams fence their wrapper from unsafe pool reuse after lease release. |
| D10 | Complete | Pool reset restores native timeout state; SQLite and live PostgreSQL regressions pass. |
| D11 | Complete | LIKE escaping is single-pass and literal wildcard/backslash semantics are verified. |
| D12 | Complete | DBLayer-owned private caches are discarded on reuse; caller-owned shared caches survive reset. |

Implementation certification is distinct from branch/release acceptance.
R07's runtime evidence is recorded below; all workflows must pass on the branch
revision selected for release. Tag/release remains a maintainer action.

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
- Initial D01-D12 and R01-R06 fixes retain regressions. The subsequent R08-R10
  fixes and R07 instrumentation are summarized below.
- Optional Runwire integration is implemented through passed host-owned runtime/request/scope
  objects; DBLayer never starts or stops the host runtime.
- Worker-local pools support explicit synchronous `warmUp()`; healthy PDO handles are
  reused across requests and request-local runtime state is sanitized on release.
- `docs/upgrade-6.0.rst` documents the CacheLayer 4 floor, changed tenancy/cache/
  transaction/lazy-lifetime contracts, worker pooling and Runwire composition.
- Persistent-worker lifecycle regressions and candidate downstream smokes for
  Foundation and ReqShield are part of the release branch. R07's corrected
  sustained host certification passes on the implementation candidate.

## Verification evidence and limits

The final remediation and scanner correction, checked with PHP 8.5.4, real temporary
MySQL 9.7/PostgreSQL 18 services and SQLite:

- `composer ic:process`: passes; generated changes were reviewed.
- `composer ic:tests:details`: **728 passed / 3612 assertions**; all configured
  detectors pass.
- `composer ic:release:guard`: passes, including manifest validation, stable
  runtime constraints, audit and the complete quality suite. Audit reports zero
  advisories; the accepted development-only abandoned package stays non-blocking.
- `composer ic:skipper`: passes across all **254 PHP files**. The fork ownership
  regression is mandatory, matching the existing Linux/pcntl test prerequisites.
- Explicit PHPStan analysis of `.automation/scripts/ci` passes with the active
  maximum-level type checks and complexity limits. This check is now also a
  required release-performance workflow step.
- Local before/after diagnostic versus `0493ea56`: seven matched eight-second
  trials at concurrency 1/2/4 pass unchanged throughput/latency/native-RSS
  budgets; paired median regressions are **-1.85%, 0.62%, 0.44%**. No workload
  errors. The earlier three-second diagnostic failed at concurrency 2
  (**2.017%**); longer trials assess that near-threshold short-run result rather
  than changing the budget. Both results remain in temporary review evidence.
  These measurements do not certify 6.0 against the 5.1 release baseline.
- The local five-minute soak passed **342,984 requests** (339,449 successes,
  3,535 expected cancellations), 1,714 iterator fences, zero unexpected errors,
  no active lease leaks and zero socket growth. Actual load duration was
  **300.005 seconds**; sampled process-tree RSS peaked at **52.64 MiB**,
  native process high-water RSS at **54.03 MiB**, and RSS growth was **5.92 MiB**.
  Every recorded acceptance check passed. The subsequent direct maximum-age
  expiry probe also passes its integration regression and is required in final
  CI alongside idle expiry; it preserves a live lease beyond its age limit,
  then verifies replacement on release.
- PHP 8.4, lowest dependencies, MariaDB, SQL Server, physical replication,
  production clean install and current downstream consumers require the final
  hosted matrix. The local host still lacks `pdo_sqlsrv`.

The first remediation commit, `c8e583ce77fdb564d7e08a936951642d84c862c3`,
passed [hosted QA](https://github.com/infocyph/DBLayer/actions/runs/37430127904)
and [both consumers](https://github.com/infocyph/DBLayer/actions/runs/37430127226).
Its [performance run](https://github.com/infocyph/DBLayer/actions/runs/37430127232)
correctly failed: concurrency 4 had **7.07%** paired median throughput regression
against the unchanged 2% budget, while latency, memory and correctness passed.
The hosted soak was skipped after that failure. A local seven-pair 5.1 diagnostic
also failed at concurrency 4 (**3.93%**), so a blind retry is not acceptance.

Stage profiling isolated the cost to cached reads. Mean cached-read CPU was
**121.66 microseconds** for 5.1/CacheLayer 3.4, **145.09** for the diagnostic
5.1/CacheLayer 4 dependency-only variant, and **142.89** for `c8e583c`.
CacheLayer 4's filesystem trust checks explain the default file-lock cost for
DBLayer's private ArrayCacheAdapter. The follow-up uses CacheLayer's existing
adapter and lock interface with a bounded `PrivateQueryCacheLockProvider`;
there is no shared storage to coordinate through filesystem locks in that
private cache. This type owns instance-local lease identity/expiry/fork fencing
and immediate generation-checked fallback for reentrant resolvers. It replaces
no caller-supplied backend or provider and starts no worker/event loop.
Ownership, stale/forged handles, bounds, expiry, invalid durations, reentrant
invalidation and child-process isolation have regressions. Its cached-read
profile is **76.95 microseconds CPU / 124.86 microseconds wall time**, compared
with **142.89 / 228.28** before this follow-up. This isolated profile does not
certify complete request throughput. The subsequent full local comparison
against 5.1's released dependency ranges passes all unchanged budgets: seven
eight-second matched pairs at each concurrency 1/2/4 have median throughput
regressions **0.45%, 1.71%, -0.51%**, with no workload errors. Source hashes
match the committed implementation `244a4b71e06bfa0efb139bf5dd4c2d04a42e7064`.
Substantial per-trial variance remains in the retained diagnostic samples;
the local release guard passes. Hosted implementation evidence is recorded below;
final branch acceptance requires the current PR workflows.

The follow-up's hosted Pest, style, dependency, reference and duplicate-code
checks passed, but QA correctly rejected a conditional `markTestSkipped()`
directive added to the fork regression. The directive has been removed;
the actual fork test remains mandatory and the local detector/release guard
pass. The final branch must pass the same complete hosted QA matrix.

The corrected [host performance and soak](https://github.com/infocyph/DBLayer/actions/runs/37432735601)
passes on implementation `244a4b7`, with [both consumers](https://github.com/infocyph/DBLayer/actions/runs/37432735606)
also passing. Artifact candidate `57d2e56b2553148b6ba6702561799018b275616a`
is the PR merge with parents 5.1/`244a4b7`; its tree
`400d5d75aabd0ea8ea5524061601f95d173e75f3` exactly matches the implementation.
All **21 matched trials** against `087f179e` pass unchanged throughput/latency/
native-RSS budgets; paired median throughput regressions are
**-0.32%, -0.95%, -1.34%** at concurrency 1/2/4, with no workload errors.

The hosted soak performed **425,668 requests** over **300.004 seconds**,
including 421,280 successes, 4,388 expected cancellations and 2,128 iterator
fences. Every resource/lifecycle check passed: zero unexpected errors, no
active lease leaks, zero socket growth, maximum queue depth 4, native high-water
RSS **66.88 MiB**, and RSS growth **4.46 MiB**. Both generations prove idle
expiry, held-lease maximum-age replacement after release and native retry
recovery. The result explicitly satisfies release duration.

Earlier green hosted runs on committed base `0493ea56` are historical evidence:
[QA](https://github.com/infocyph/DBLayer/actions/runs/37419888853),
[consumers](https://github.com/infocyph/DBLayer/actions/runs/37419888441) and
[performance](https://github.com/infocyph/DBLayer/actions/runs/37419888403).
Their candidate merge tree matched that base, with throughput regression below
2%. Their four-second soak and PHP-heap RSS field are superseded by the corrected
instrumentation and must not certify the remediated branch.

Historical diagnostic records remain in
[the 2026-10-06 recheck](evidence/2026-10-06-recheck-probes.jsonl),
[5.1 outcomes](evidence/2026-10-05-audit-probes.jsonl),
[5.1 harness](evidence/reproduce-audit.md),
[previous review outcomes](evidence/2026-10-05-update-review-probes.jsonl) and
[previous harness](evidence/reproduce-update-review.md).
Those recorded failures predate remediation and are not current expected results.

## Implemented remediation

- **R08:** the ordinary fetch loop tests the current budget before and after
  each native fetch. The captured/unbuffered path also consults the current
  budget rather than freezing its presence at creation. Common direct/pooled
  logic is consolidated while cursor ownership and captured bindings remain.
  Regressions: `tests/Unit/StreamPolicyTransitionTest.php`.
- **R09:** dependency identity excludes the default schema; `cacheTableTag()`
  resolves unqualified tables into their configured schema and preserves an
  explicit qualified schema. Result visibility remains role/schema/scope-specific.
  Dependency identity moves to v2 and result identity to v3 together, preventing
  warm entries bearing old tags from surviving the transition. Deployment
  isolation and bounded tag memoization remain. Regressions:
  `tests/Unit/CacheDependencySchemaTest.php` and
  `tests/Integration/InstanceQueryCacheIntegrationTest.php`.
- **R10:** evidence requires seven unique matched trials at each of concurrency
  1/2/4, exact expected revisions, matching environment/workload metadata and
  complete finite metrics with positive successful work. Trials pair by
  concurrency/trial identity. Empty, malformed, missing, duplicate and mismatched
  inputs fail. Existing throughput/latency/native-RSS budgets remain unchanged.
  Regressions: `tests/Unit/ReleaseHostComparatorTest.php`.
- **R07 tooling:** host trials sample live controller/descendant OS RSS every
  50 ms and retain each worker's PID/native high-water RSS and CPU usage separately. PHP allocator
  measurements are explicitly heap measurements. The Linux resource sampler
  records process identities and sockets, handles exited workers/descriptors,
  and fails on unavailable native memory metrics rather than substituting heap.
  The soak keeps load running until both request and elapsed duration targets
  are met. CI requires at least **300 seconds** of load, peak RSS at most
  **256 MiB**, RSS growth at most **32 MiB**, bounded sockets/queue/connections,
  zero unexpected errors and no active lease leaks. Idle expiry, 30-second
  maximum connection lifetime, native lock-timeout retry recovery, cancellations,
  tenant changes, iterator fencing and generation overlap are exercised. Short
  smoke runs explicitly do not satisfy release duration. Regressions:
  `tests/Unit/ReleaseHostResourcesTest.php` and
  `tests/Integration/ReleaseRunwireHarnessTest.php`.
- **R07 performance:** private in-memory result caches use bounded local
  coordination through CacheLayer's existing lock interface. Shared caches
  retain their own providers. Regressions:
  `tests/Unit/PrivateQueryCacheLockProviderTest.php`.

## Release acceptance checks

Run the updated QA/consumer/performance workflows on the branch revision
selected for release. Require valid revised evidence, the 5.1
matched throughput/latency/actual-RSS comparison within existing budgets, and
five-minute Runwire host load with every resource/lifecycle check passing.
Local before/after measurements against `0493ea56` are implementation diagnostics;
they do not replace the final 5.1 release comparison or the hosted matrix.

## Runwire and worker-pool contracts

Passed-instance integration and the original R02-R03/R06 cases are implemented
and tested. The remediation preserves these contracts; final committed-revision
release acceptance requires current branch checks. Runwire supplies execution context, cancellation/deadline and
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
handles. R06's expired-idle case is fixed and its regression passes. Live-server
warmup, replenishment and bounded maintenance still require the acceptance
evidence below when those contracts are retained for this release.

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
| A | Implemented R09 | Keep qualified/unqualified/joined PostgreSQL and legacy-cache migration regressions green on the final revision |
| B | Implemented R08 | Keep direct/pooled/unbuffered/nested policy and cursor/lease regressions green on the final revision |
| C | Preserve completed tooling/dependency gates | Unsuppressed PHPForge checks, latest/lowest constraints, clean production install; accepted development warning stays visible |
| D | Implemented lifecycle contracts | Preserve context, warmup and lease isolation; extra proposed configuration is deferred from this remediation |
| E | R07 certification and final CI | Run corrected evidence validation/RSS/duration gates, matched 5.1 comparison, current consumers and exact-final-SHA CI |

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
R08-R10 are fixed and R07's matched 5.1 workload and five-minute Runwire evidence
pass on the implementation candidate. The corrected full local release guard
passes. Release acceptance requires all current PR workflows on the final
branch revision; earlier green checks on `0493ea56` do not certify it.

The upgrade guide covers the dependency, tenancy, cache, native-transaction,
iterator, worker-pool and optional Runwire contracts, including cold cache
identity migration and policies activated during iteration. No merge, tag,
release or deployment has been performed by this remediation.

## Reproduce current verification

Use the declared development dependencies, Linux native process telemetry,
PDO SQLite and provisioned PostgreSQL/MySQL services with the documented test
environment variables. Run PHPForge's doctor/config commands, processors,
detailed tests and final release guard. Focused files for the new cases are:

```sh
php vendor/bin/pest \
  --configuration vendor/infocyph/phpforge/resources/pest.xml \
  --bootstrap vendor/autoload.php \
  tests/Unit/StreamPolicyTransitionTest.php \
  tests/Unit/CacheDependencySchemaTest.php \
  tests/Unit/ReleaseHostComparatorTest.php \
  tests/Unit/ReleaseHostResourcesTest.php \
  tests/Integration/InstanceQueryCacheIntegrationTest.php \
  tests/Integration/ReleaseRunwireHarnessTest.php
```

The updated `.github/workflows/release-performance.yml` contains the canonical
matched comparison and five-minute soak commands. Expected revisions are
mandatory comparator inputs. Retain artifacts/lifecycle telemetry from the
final candidate in CI; do not replace missing native RSS with PHP heap or
accept a short smoke as sustained certification.
