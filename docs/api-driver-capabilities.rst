API: Driver Capabilities
========================

Class: ``Infocyph\DBLayer\Driver\Support\Capabilities``

Capabilities make SQL feature branching explicit. Instead of guessing by driver
name in application code, query capability flags and choose the correct path.

Read capabilities via:

.. code-block:: php

   $caps = DB::capabilities();

Fields
------

- ``supportsReturning``
- ``supportsInsertIgnore``
- ``supportsUpsert``
- ``supportsSavepoints``
- ``supportsSchemas``
- ``supportsJson``
- ``supportsWindowFunctions``

Built-in Driver Matrix
----------------------

.. list-table::
   :header-rows: 1

   * - Driver
     - Returning
     - Insert Ignore
     - Upsert
     - Savepoints
     - Schemas
     - JSON
     - Window Functions
   * - MySQL
     - no
     - yes
     - yes
     - yes
     - yes
     - yes
     - yes
   * - PostgreSQL
     - yes
     - no
     - yes
     - yes
     - yes
     - yes
     - yes
   * - SQLite
     - no
     - yes
     - yes
     - yes
     - no
     - yes
     - yes
