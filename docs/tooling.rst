Tooling and Composer Scripts
============================

Introduction
------------

The scripts are grouped by intent so local workflow and CI usage stay
predictable.

.. contents:: On This Page
   :depth: 2
   :local:

Script Namespaces
-----------------

- ``test:*`` for verification
- ``process:*`` for actionable refactor/lint/fix
- ``bench:*`` for benchmarks

Use ``test:*`` in CI and pre-commit checks. Use ``process:*`` when you want the
tool to apply code changes.

Key Test Commands
-----------------

- ``composer test:syntax``
- ``composer test:code``
- ``composer test:lint``
- ``composer test:sniff``
- ``composer test:static``
- ``composer test:security``
- ``composer test:refactor``
- ``composer test:all``

``test:code`` is intentionally detailed for local debugging.
``test:all`` uses a parallel Pest invocation for faster aggregated checks.

Key Process Commands
--------------------

- ``composer process:refactor``
- ``composer process:lint``
- ``composer process:sniff:fix``
- ``composer process:all``

Responsibility Split
--------------------

- Pint (PER preset) handles formatting style.
- PHPCS handles semantic and policy sniffs not covered by Pint.
- PHPStan handles static analysis and complexity.
- Psalm handles security/taint analysis.
- Rector handles dead code and upgrade refactors.

Recommended Developer Loop
--------------------------

1. ``composer test:code`` while implementing features.
2. ``composer process:all`` before final review.
3. ``composer test:all`` before commit.
