Security
========

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

Validation Coverage
-------------------

- SQL injection pattern checks
- Query length checks
- Parameter count and size checks
- Additional dangerous-pattern scans in strict mode

Per-Connection Security Config
------------------------------

.. code-block:: php

   'security' => [
       'enabled' => true,
       'max_sql_length' => 8000,
       'max_params' => 500,
       'max_param_bytes' => 4096,
   ]
