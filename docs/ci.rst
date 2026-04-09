CI and Code Scanning
====================

Introduction
------------

Primary workflow: ``.github/workflows/build.yml``.

The pipeline separates quality verification from code-scanning publication so
you get both fast feedback and security visibility.

.. contents:: On This Page
   :depth: 2
   :local:

Code Analysis Matrix
--------------------

Runs on PHP 8.4 and 8.5 with dependency modes:

- ``prefer-lowest``
- ``prefer-stable``

Includes syntax, tests, lint, sniff, refactor, static analysis, and security checks.

Matrix intent:

- ``prefer-lowest`` catches compatibility regressions.
- ``prefer-stable`` validates normal install behavior.

Security Analysis
-----------------

Includes:

- ``composer audit --abandoned=ignore``
- PHPStan SARIF upload
- Psalm SARIF upload

SARIF uploads appear in GitHub Code Scanning and can be tracked over time per
PHP version category.

GitHub Hooks
------------

``captainhook.json`` pre-commit runs:

- ``composer validate --strict``
- ``composer audit --no-interaction --abandoned=ignore``
- ``composer tests``

If pre-commit fails, fix locally with ``composer test:all`` and rerun commit.
