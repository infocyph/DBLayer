<?php

// examples/filtering.php

declare(strict_types=1);

use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Query\QueryBuilder;

require __DIR__ . '/bootstrap.php';

$filters = [
    'search'     => $_GET['search']    ?? null,
    'activeOnly' => isset($_GET['only_active']),
    'role'       => $_GET['role']      ?? null,
];

// Base query
$query = DB::table('users')
  ->select(['id', 'name', 'email', 'role', 'active'])
  ->whereNull('deleted_at');

// Conditionally add "active" filter
$query = $query->when(
    $filters['activeOnly'],
    static function (QueryBuilder $q): QueryBuilder {
        return $q->where('active', '=', 1);
    },
);

// Conditionally add "role" filter
$query = $query->when(
    $filters['role'] !== null,
    static function (QueryBuilder $q) use ($filters): QueryBuilder {
        return $q->where('role', '=', $filters['role']);
    },
);

// Conditionally add full-text-ish search on name/email
$query = $query->when(
    isset($filters['search']) && $filters['search'] !== '',
    static function (QueryBuilder $q) use ($filters): QueryBuilder {
        $term = '%' . $filters['search'] . '%';

        return $q->where(function (QueryBuilder $inner) use ($term): QueryBuilder {
            return $inner
              ->where('name', 'like', $term)
              ->orWhere('email', 'like', $term);
        });
    },
);

// Final ordering + pagination
$users = $query
  ->orderBy('id', 'desc')
  ->forPage(page: 1, perPage: 20)
  ->get();

var_dump($users);
