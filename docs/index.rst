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
3. ``configuration`` and ``connections`` to move from local to production use.
4. ``query-builder`` and ``repository`` for day-to-day application code.
5. ``security`` and ``observability`` before deploying.

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
   :caption: Tooling

   tooling
   ci

.. toctree::
   :maxdepth: 2
   :caption: API Reference

   api-facade
   api-query-builder
   api-repository
   api-connection-config
   api-driver-capabilities

Source
------

- GitHub: https://github.com/infocyph/DBLayer
- Package: https://packagist.org/packages/infocyph/dblayer
