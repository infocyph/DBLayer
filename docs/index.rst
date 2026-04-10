DBLayer Documentation
=====================

DBLayer is a high-performance PHP database layer for PHP ``^8.4`` focused on
predictable SQL behavior, safe defaults, and practical performance features.

This manual is written for backend engineers who want a lightweight library
that still provides:

- A fluent query builder
- Repository-level conveniences
- Multi-driver support (MySQL, PostgreSQL, SQLite)
- Read/write splitting with replica strategies
- Transaction and retry controls
- Security checks, telemetry, and profiling

How To Read This Documentation
------------------------------

If you are new to the project, follow this order:

1. ``installation`` for environment setup.
2. ``quickstart`` for a working end-to-end flow.
3. ``choosing-api`` to choose DB vs QueryBuilder vs Repository.
4. ``configuration`` and ``connections`` to move from local to production use.
5. ``table-model`` for model-like class workflows without ORM.
6. ``examples-cookbook`` for ready-to-use snippets across all layers.
7. ``query-builder`` and ``repository`` for day-to-day application code.
8. ``security`` and ``observability`` before deploying.

The API reference sections are intentionally method-oriented and are best used
as lookup pages after reading the guides.

.. toctree::
   :maxdepth: 2
   :caption: Getting Started

   installation
   quickstart
   architecture

.. toctree::
   :maxdepth: 2
   :caption: Guides

   configuration
   connections
   choosing-api
   table-model
   examples-cookbook
   query-builder
   repository
   transactions
   caching
   security
   observability
   events-helpers
   benchmarks

.. toctree::
   :maxdepth: 2
   :caption: API Reference

   api-facade
   api-table-model
   api-query-builder
   api-repository
   api-connection-config
   api-driver-capabilities

Source
------

- GitHub: https://github.com/infocyph/DBLayer
- Package: https://packagist.org/packages/infocyph/dblayer
