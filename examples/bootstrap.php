<?php

// bootstrap.php

declare(strict_types=1);

use Infocyph\DBLayer\DB;

require __DIR__ . '/../vendor/autoload.php';

// Default MySQL connection
DB::addConnection([
    'driver'   => 'mysql',
    'host'     => '127.0.0.1',
    'port'     => 3306,
    'database' => 'app_db',
    'username' => 'app_user',
    'password' => 'secret',
    'charset'  => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',

    // Optional security overrides – otherwise Security::validateQuery() will
    // use global mode defaults.
    'security' => [
        'enabled'        => true,
        'max_sql_length' => 8000,
        'max_params'     => 500,
        'max_param_bytes' => 4096,
    ],
], 'mysql_main');

// Reporting / analytics PostgreSQL connection
DB::addConnection([
    'driver'   => 'pgsql',
    'host'     => '127.0.0.1',
    'port'     => 5432,
    'database' => 'reporting_db',
    'username' => 'report_user',
    'password' => 'secret',
], 'pgsql_reporting');

// Optional: explicitly mark default (if you didn’t give it in addConnection)
DB::setDefaultConnection('mysql_main');
