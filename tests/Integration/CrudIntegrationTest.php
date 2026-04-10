<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Exceptions\QueryException;

it('covers query builder CRUD operations end-to-end', function (string $driver): void {
    dblayerAddConnectionForDriver($driver);

    DB::statement(
        sprintf(
            'create table users (
            %s,
            email %s unique,
            name %s,
            age integer
        )',
            dblayerAutoIncrementPrimaryKey($driver),
            dblayerStringType($driver, 191),
            dblayerStringType($driver),
        ),
    );

    expect(DB::table('users')->insert([
        'email' => 'a@example.com',
        'name' => 'Alice',
        'age' => 30,
    ]))->toBeTrue();

    expect(DB::table('users')->insert([
        ['email' => 'b@example.com', 'name' => 'Bob', 'age' => 25],
        ['email' => 'c@example.com', 'name' => 'Chris', 'age' => 20],
    ]))->toBeTrue();

    $insertedId = DB::table('users')->insertGetId([
        'email' => 'd@example.com',
        'name' => 'Dora',
        'age' => 18,
    ]);
    expect((int) $insertedId)->toBeGreaterThan(0);

    $returned = DB::table('users')->insertReturning([
        'email' => 'e@example.com',
        'name' => 'Eve',
        'age' => 19,
    ], 'id');
    expect($returned)->toBeArray();
    expect((int) ($returned['id'] ?? 0))->toBeGreaterThan(0);

    if (DB::connection()->supportsInsertIgnore()) {
        expect(DB::table('users')->insertIgnore([
            'email' => 'a@example.com',
            'name' => 'Duplicate',
            'age' => 99,
        ]))->toBeFalse();
    } else {
        expect(static function (): bool {
            return DB::table('users')->insertIgnore([
                'email' => 'a@example.com',
                'name' => 'Duplicate',
                'age' => 99,
            ]);
        })->toThrow(QueryException::class);
    }

    expect((int) DB::table('users')->count())->toBe(5);

    $updated = DB::table('users')
        ->where('email', 'b@example.com')
        ->update(['age' => 26]);
    expect($updated)->toBe(1);
    expect((int) DB::table('users')->where('email', 'b@example.com')->value('age'))->toBe(26);

    expect(DB::table('users')->upsert([
        'email' => 'f@example.com',
        'name' => 'Frank',
        'age' => 33,
    ], ['email'], ['name', 'age']))->toBeTrue();
    expect(DB::table('users')->where('email', 'f@example.com')->value('name'))->toBe('Frank');

    $deleted = DB::table('users')
        ->where('email', 'c@example.com')
        ->delete();
    expect($deleted)->toBe(1);
    expect((int) DB::table('users')->count())->toBe(5);

    expect(DB::table('users')->truncate())->toBeTrue();
    expect((int) DB::table('users')->count())->toBe(0);
})->with('dblayer_drivers');
