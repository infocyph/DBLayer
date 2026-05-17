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

   composer bench:run
   composer bench:quick
   composer bench:chart

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

Report Interpretation
---------------------

- Prefer comparing results from the same machine and PHP version.
- Watch for drift in mode/mean and RSD.
- Use ``bench:quick`` for local iteration and ``bench:run`` for fuller runs.

Do Not Assume Feature Speedups
------------------------------

Do not assume statement cache or observability toggles always improve
throughput. Compare paired benchmark subjects on the same machine/run:

- ``benchStatementCacheOff`` vs ``benchStatementCacheOn``
- ``benchWithQueryCommentDisabled`` vs ``benchWithQueryCommentEnabled``
- ``benchEventDispatchOff`` vs ``benchEventDispatchOn``

Treat these as measured tradeoffs, not guaranteed improvements.

Chart Output
------------

``bench:chart`` uses the configured console bar chart generator in
``phpbench.json`` to make regressions easier to spot visually.
