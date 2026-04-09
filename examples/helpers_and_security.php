<?php

declare(strict_types=1);

use Infocyph\DBLayer\Events\Events;
use Infocyph\DBLayer\Exceptions\SecurityException;
use Infocyph\DBLayer\Security\QueryValidator;

require __DIR__ . '/../vendor/autoload.php';

// data_get / data_set helper usage
$payload = new stdClass();
data_set($payload, 'profile.name', 'Alice');

echo 'Name: ' . (string) data_get($payload, 'profile.name', 'unknown') . PHP_EOL;

// Event listener registration/removal
$listener = static function (): void {
    echo "custom.event triggered\n";
};

Events::listen('custom.event', $listener);
Events::dispatch('custom.event');
Events::forget('custom.event', $listener);

// Query validation
$validator = new QueryValidator();

try {
    $validator->detectSqlInjection('select * from users where name = "x" or 1=1 union select password from admins');
} catch (SecurityException $e) {
    echo 'Blocked suspicious SQL: ' . $e->getMessage() . PHP_EOL;
}
