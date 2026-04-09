Benchmarks
==========

DBLayer uses PHPBench for benchmark runs.

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
