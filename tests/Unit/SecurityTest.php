<?php
declare(strict_types=1);

use Infocyph\DBLayer\Security;
use Infocyph\DBLayer\SecurityException;

test('validates identifiers correctly', function() {
    expect(Security::validateIdentifier('users'))->toBeTrue();
    expect(Security::validateIdentifier('user_accounts'))->toBeTrue();
    expect(Security::validateIdentifier('_private'))->toBeTrue();
    expect(Security::validateIdentifier('table123'))->toBeTrue();
    
    expect(Security::validateIdentifier('123invalid'))->toBeFalse();
    expect(Security::validateIdentifier('table-name'))->toBeFalse();
    expect(Security::validateIdentifier('table name'))->toBeFalse();
});

test('escapes MySQL identifiers', function() {
    $escaped = Security::escapeIdentifier('users', 'mysql');
    expect($escaped)->toBe('`users`');
    
    $escaped = Security::escapeIdentifier('my`table', 'mysql');
    expect($escaped)->toBe('`my``table`');
});

test('escapes PostgreSQL identifiers', function() {
    $escaped = Security::escapeIdentifier('users', 'pgsql');
    expect($escaped)->toBe('"users"');
    
    $escaped = Security::escapeIdentifier('my"table', 'pgsql');
    expect($escaped)->toBe('"my""table"');
});

test('validates SQL operators', function() {
    expect(Security::validateOperator('='))->toBe('=');
    expect(Security::validateOperator('>'))->toBe('>');
    expect(Security::validateOperator('LIKE'))->toBe('LIKE');
    expect(Security::validateOperator('IN'))->toBe('IN');
});

test('rejects invalid operators', function() {
    Security::validateOperator('INVALID');
})->throws(SecurityException::class);

test('detects SQL injection patterns', function() {
    expect(Security::detectInjection("SELECT * FROM users"))->toBeFalse();
    expect(Security::detectInjection("SELECT * FROM users WHERE id = 1"))->toBeFalse();
    
    expect(Security::detectInjection("'; DROP TABLE users; --"))->toBeTrue();
    expect(Security::detectInjection("UNION SELECT password FROM admins"))->toBeTrue();
    expect(Security::detectInjection("/* comment */ SELECT"))->toBeTrue();
});

test('validates query size', function() {
    Security::validateQuerySize("SELECT * FROM users", [1, 2, 3]);
})->throwsNoExceptions();

test('rejects oversized queries', function() {
    $hugeQuery = str_repeat('x', 2000000);
    Security::validateQuerySize($hugeQuery, []);
})->throws(SecurityException::class);
