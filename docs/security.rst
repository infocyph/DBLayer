Security
========

Introduction
------------

DBLayer includes layered SQL safety checks for query text and bindings. Security
is configured globally by mode and optionally overridden per connection.

.. contents:: On This Page
   :depth: 2
   :local:

Security Mode
-------------

Available modes:

- ``SecurityMode::OFF``
- ``SecurityMode::NORMAL``
- ``SecurityMode::STRICT``

.. code-block:: php

   use Infocyph\DBLayer\Security\Security;
   use Infocyph\DBLayer\Security\SecurityMode;

   Security::setMode(SecurityMode::STRICT);

Mode Semantics
--------------

- ``OFF``: disables automatic SQL validation.
- ``NORMAL``: injection/binding validation with practical defaults.
- ``STRICT``: adds more aggressive pattern checks and tighter policies.

Validation Coverage
-------------------

- SQL injection pattern checks
- Query length checks
- Parameter count and size checks
- Additional dangerous-pattern scans in strict mode

The validator is defense-in-depth. You should still use parameterized queries
and avoid concatenating untrusted input into SQL fragments.

Per-Connection Security Config
------------------------------

.. code-block:: php

   'security' => [
       'enabled' => true,
       'max_sql_length' => 8000,
       'max_params' => 500,
       'max_param_bytes' => 4096,
       'raw_sql_policy' => 'allow',
       'raw_sql_allowlist' => [],
   ]

Raw SQL Fragment Policy
-----------------------

Raw entry points (for example ``whereRaw()``, ``selectRaw()``, string
``fromSub()``, and string CTE bodies) are controlled by:

- ``raw_sql_policy = allow``: default behavior.
- ``raw_sql_policy = deny``: block all raw fragments.
- ``raw_sql_policy = allowlist``: only allow fragments matching
  ``raw_sql_allowlist`` patterns.

Allowlist rules support plain substring rules and regex rules such as
``'/^id\\s*=\\s*\\?$/i'``.

Facade-Level Defaults
---------------------

For consistent policy across many connections, you can apply global defaults
through the facade:

.. code-block:: php

   use Infocyph\DBLayer\DB;

   DB::setSecurityDefaults([
       'strict_identifiers' => true,
       'queries_per_second' => 250,
   ]);

   // Convenience profile (enables strict identifiers; requires TLS in production).
   DB::hardenProduction();

These values are applied as enforced facade policy across registered and future
connections.

Rate Limiting and Confirmation
------------------------------

Security utilities also include rate-limit checks and dangerous-operation
confirmation gates:

.. code-block:: php

   Security::checkRateLimit('tenant:42');
   Security::requireConfirmation('drop table users', confirmed: true);

.. warning::

   Security mode ``OFF`` disables automatic SQL validation. Use it only in
   controlled internal scenarios, never as a default in shared environments.
