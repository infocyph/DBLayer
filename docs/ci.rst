CI and Code Scanning
====================

Primary workflow: ``.github/workflows/build.yml``.

Code Analysis Matrix
--------------------

Runs on PHP 8.4 and 8.5 with dependency modes:

- ``prefer-lowest``
- ``prefer-stable``

Includes syntax, tests, lint, sniff, refactor, static analysis, and security checks.

Security Analysis
-----------------

Includes:

- ``composer audit --abandoned=ignore``
- PHPStan SARIF upload
- Psalm SARIF upload

GitHub Hooks
------------

``captainhook.json`` pre-commit runs:

- ``composer validate --strict``
- ``composer audit --no-interaction --abandoned=ignore``
- ``composer tests``
