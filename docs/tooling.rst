Tooling and Composer Scripts
============================

Script Namespaces
-----------------

- ``test:*`` for verification
- ``process:*`` for actionable refactor/lint/fix
- ``bench:*`` for benchmarks

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
