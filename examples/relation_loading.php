<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;

require dirname(__DIR__) . '/vendor/autoload.php';

DB::addConnection(['driver' => 'sqlite', 'database' => ':memory:']);

// Parents are loaded by the application. Related rows are fetched explicitly
// in bounded WHERE IN batches; no property access can trigger a query.
$users = DB::table('users')->select(['id', 'name'])->get();
$users = DB::relations(batchSize: 500)->many(
    $users,
    parentKey: 'id',
    relatedTable: 'posts',
    relatedKey: 'user_id',
    as: 'posts',
);

var_export($users);
