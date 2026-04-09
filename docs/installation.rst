Installation
============

Requirements
------------

- PHP ``^8.4``
- ``ext-pdo``
- Optional per driver:
  - ``ext-pdo_mysql``
  - ``ext-pdo_pgsql``
  - ``ext-pdo_sqlite``

Driver Notes
------------

DBLayer can be installed once and used across multiple engines, but each
runtime environment must have the extension for the active driver enabled.

- Local/dev usually uses ``pdo_sqlite`` for fast setup.
- Production MySQL requires ``pdo_mysql``.
- Production PostgreSQL requires ``pdo_pgsql``.

Install
-------

.. code-block:: bash

   composer require infocyph/dblayer

Quick Verification
------------------

After install, verify autoload + extension availability:

.. code-block:: bash

   composer show infocyph/dblayer
   php -m | findstr /I pdo

If you see only ``PDO`` but not a driver module, connection creation will fail
for that specific driver.

Development Dependencies
------------------------

.. code-block:: bash

   composer install

Useful local quality checks:

.. code-block:: bash

   composer test:syntax
   composer test:code
   composer test:all
