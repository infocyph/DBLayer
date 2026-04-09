Architecture
============

Core Components
---------------

- ``DB`` facade: static entrypoint.
- ``Connection``: PDO lifecycle, driver, execution controls.
- ``QueryBuilder``: fluent SQL builder.
- ``Repository``: table-oriented abstraction.

Driver Stack
------------

- MySQL, PostgreSQL, SQLite drivers.
- Grammar/compiler per dialect.
- ``Capabilities`` flags for feature checks.

Runtime Modules
---------------

- Transactions and savepoints
- Read replicas and strategies
- Pooling and health checks
- Security validation
- Events, logger, profiler, telemetry
- Caching strategies
