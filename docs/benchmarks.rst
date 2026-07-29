Benchmarks
==========

DBLayer uses PHPBench for benchmark runs.

Purpose
-------

Benchmarks are intended to track relative change over time for hot paths
(builder SQL generation, primary-key lookup flow, transactional read patterns,
and focused update behavior). They are not an absolute cross-machine score.

Commands
--------

.. code-block:: bash

   composer ic:bench:run
   composer ic:bench:quick
   composer ic:bench:chart

Current benchmark subjects are defined in ``benchmarks/DBLayerBench.php``:

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

Report Interpretation
---------------------

- Prefer comparing results from the same machine and PHP version.
- Watch for drift in mode/mean and RSD.
- Use ``ic:bench:quick`` for local iteration and ``ic:bench:run`` for fuller runs.
- Record the PHP version, extensions, OPcache state, operating system, database
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

Do not assume statement cache or observability toggles always improve
throughput. Compare paired benchmark subjects on the same machine/run:

- ``benchStatementCacheOff`` vs ``benchStatementCacheOn``
- ``benchWithQueryCommentDisabled`` vs ``benchWithQueryCommentEnabled``
- ``benchEventDispatchOff`` vs ``benchEventDispatchOn``

Treat these as measured tradeoffs, not guaranteed improvements.

The schema subject measures compilation only because migrations are a
deploy/console path. The relation subject measures a 20-parent select plus one
bounded relation query. Keep these separate from ordinary query hot-path
subjects so opt-in features do not conceal regressions in common operations.

Chart Output
------------

``ic:bench:chart`` uses the configured console bar chart generator in
``phpbench.json`` to make regressions easier to spot visually.
