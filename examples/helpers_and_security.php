<?php

declare(strict_types=1);

use Infocyph\DBLayer\Events\Events;
use Infocyph\DBLayer\Exceptions\SecurityException;
use Infocyph\DBLayer\Security\QueryValidator;

require __DIR__ . '/../vendor/autoload.php';

$writeLine = static function (string $message): void {
    fwrite(STDOUT, $message . PHP_EOL);
};

// data_get / data_set helper usage
$payload = new stdClass();
data_set($payload, 'profile.name', 'Alice');

$writeLine('Name: ' . (string) data_get($payload, 'profile.name', 'unknown'));

// Event listener registration/removal
$listener = static function (): void {
    fwrite(STDOUT, "custom.event triggered\n");
};

Events::listen('custom.event', $listener);
Events::dispatch('custom.event');
Events::forget('custom.event', $listener);

// Query validation
$validator = new QueryValidator();

try {
    $validator->detectSqlInjection('select * from users where name = "x" or 1=1 union select password from admins');
} catch (SecurityException $e) {
    $writeLine('Blocked suspicious SQL: ' . $e->getMessage());
}
