Benchmarks
==========

DBLayer uses PHPBench for benchmark runs.

Purpose
-------

Benchmarks track relative change over time for hot paths and runtime lifecycle
choices. They are not absolute cross-machine scores and they are not a reason
to enable an optional feature without measuring the target workload.

Commands
--------

.. code-block:: bash

   composer ic:bench:run
   composer ic:bench:quick
   composer ic:bench:chart

Benchmark Suites
----------------

Current subjects are defined across:

- ``benchmarks/DBLayerBench.php``
- ``benchmarks/DBLayerCompilerBench.php``
- ``benchmarks/RuntimeLifecycleBench.php``

Core/query subjects include:

- ``benchBuildSelectSql``
- ``benchSelectByPrimaryKey``
- ``benchTransactionTwoPointReads``
- ``benchUpdateSingleColumn``
- ``benchExecuteRaw``
- ``benchTypedRunCompiled``
- ``benchStatementCacheOff`` / ``benchStatementCacheOn``
- ``benchWithQueryCommentDisabled`` / ``benchWithQueryCommentEnabled``
- ``benchEventDispatchOff`` / ``benchEventDispatchOn``
- ``benchSelectRowsBuffered`` / ``benchStreamRows``
- ``benchWithLeastLatencyCachedReplica`` / ``benchWithLeastLatencyUncachedReplica``
- ``benchRelationLoadTwentyParents``
- ``benchSchemaCompileCreate``
- ``benchArrayResultFiftyRows`` / ``benchCollectFiftyRows``
- ``benchLazyByIdRows`` / ``benchLazyCollectionRows``
- ``benchQueryCacheDisabled`` / ``benchQueryCacheMiss``
- memory-cache hits for 1, 50, 500, and 5000 rows
- ``benchRepositoryCachedFind``
- ``benchBulkInsertCompileHundredRows`` / ``benchBulkInsertCompileThousandRows``
- ``benchUpsertChunkingHundredRows`` / ``benchFindManyHundredIds``
- ``benchAfterCommitCacheInvalidation``

Runtime lifecycle subjects include:

- ``benchConstructConnection``
- ``benchOpenUseDisconnect``
- ``benchWarmDedicatedSelect``
- ``benchWarmPreparedStatementReuse``
- ``benchPoolCheckoutRelease``
- ``benchPoolCheckoutSelectRelease``
- ``benchPoolUsingSelect``
- ``benchPoolPreparedStatementReuse``
- ``benchInstanceQueryCacheHit``
- ``benchInstanceQueryCacheMiss``

Why Runtime Lifecycle Has Its Own Suite
---------------------------------------

Persistent runtimes face a different choice from ordinary request-bound PHP:
create/use/disconnect, keep a dedicated warm connection, or check out/release a
pooled connection. ``RuntimeLifecycleBench`` keeps these choices visible rather
than burying them inside unrelated query microbenchmarks.

Use it to compare:

- connection object construction cost
- full open/query/disconnect cost
- warm dedicated query cost
- tokenized pool checkout/release overhead
- pool checkout + query + release
- prepared-statement reuse on dedicated versus pooled connections
- connection-owned query-cache hit/miss behavior

Do not enable pooling merely because the API exists. A persistent host runtime
should adopt pooling only when measured reuse benefit is meaningful relative to
lease/reset overhead and the ownership model is correct for its concurrency.

Report Interpretation
---------------------

- Prefer comparing results from the same machine and PHP version.
- Watch for drift in mode/mean and RSD.
- Use ``ic:bench:quick`` for local iteration and ``ic:bench:run`` for fuller runs.
- Record PHP version, extensions, OPcache state, operating system, database
  engine/version, hardware class, and command with every comparison.
- Compare repeated median results on the same environment. Treat a median
  sustained-throughput regression above 2% as a reason to investigate; adjust
  that tolerance only when measured variance justifies it.
- A component microbenchmark is not an application RPM claim. Validate material
  changes in a representative host application with correct responses, stable
  queues and memory, bounded connections, and acceptable error/timeout rates.

PHPBench reports time per operation. For a supporting component-throughput
estimate, convert consistently using ``operations/second = 1,000,000 / µs`` and
``operations/minute = operations/second × 60``. Do not present that number as
end-to-end successful application RPM.

Do Not Assume Feature Speedups
------------------------------

Do not assume statement cache, pooling, query caching, or observability toggles
always improve throughput. Compare paired subjects on the same machine/run:

- ``benchStatementCacheOff`` vs ``benchStatementCacheOn``
- ``benchWithQueryCommentDisabled`` vs ``benchWithQueryCommentEnabled``
- ``benchEventDispatchOff`` vs ``benchEventDispatchOn``
- ``benchArrayResultFiftyRows`` vs ``benchCollectFiftyRows``
- ``benchLazyByIdRows`` vs ``benchLazyCollectionRows``
- ``benchQueryCacheDisabled`` vs cache hit/miss subjects
- ``benchOpenUseDisconnect`` vs ``benchPoolCheckoutSelectRelease``
- ``benchWarmDedicatedSelect`` vs ``benchPoolCheckoutSelectRelease``
- ``benchWarmPreparedStatementReuse`` vs ``benchPoolPreparedStatementReuse``

Treat these as measured tradeoffs, not guaranteed improvements.

The schema subject measures compilation only because migrations are a
deploy/console path. The relation subject measures a 20-parent select plus one
bounded relation query. Keep these separate from ordinary query hot-path
subjects so opt-in features do not conceal regressions in common operations.

Use memory CacheLayer for query-cache microbenchmarks. Network adapters belong
in integration/load tests where serialization, server, and transport behavior
can be measured honestly. Record peak memory alongside latency for collection,
bulk compilation, cached-result, and persistent-runtime comparisons.

Execution subjects are conditionally skipped when PDO SQLite is unavailable;
they are never replaced with compile-only work under an execution-oriented
name. Compiler subjects remain available without PDO SQLite and are explicitly
named as compilation measurements.

Host Runtime Acceptance
-----------------------

When DBLayer is integrated into a higher-level runtime, component benchmarks
should be paired with host-level measurements for:

- direct DBLayer query versus the host's scoped database bridge
- first connection/open versus warm execution
- dedicated create/use/disconnect versus lease checkout/use/release
- prepared-statement reuse
- transaction begin/commit/rollback
- result-cache hit/miss/post-commit invalidation
- repeated request/job/Fiber executions with stable memory and connection count

The goal is to measure the bridge and lifecycle policy, not to duplicate DBLayer
mechanisms in the host just to optimize around an unmeasured assumption.

Chart Output
------------

``ic:bench:chart`` uses the configured console bar chart generator in
``phpbench.json`` to make regressions easier to spot visually.
