<?php
declare(strict_types=1);

if (!function_exists('db')) {
    function db(?string $connection = null): Infocyph\DBLayer\Connection {
        return Infocyph\DBLayer\DB::connection($connection);
    }
}

if (!function_exists('collect')) {
    function collect(array $items = []): Infocyph\DBLayer\Collection {
        return new Infocyph\DBLayer\Collection($items);
    }
}
