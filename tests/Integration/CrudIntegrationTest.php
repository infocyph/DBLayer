<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Exceptions\QueryException;

it('covers query builder CRUD operations end-to-end', function (string $driver): void {
    dblayerAddConnectionForDriver($driver);
    $schemaDriver = dblayerConnectionDriver();
    $table = dblayerTable('users');

    DB::statement(
        sprintf(
            'create table %s (
            %s,
            email %s unique,
            name %s,
            age integer
        )',
            $table,
            dblayerAutoIncrementPrimaryKey($schemaDriver),
            dblayerStringType($schemaDriver, 191),
            dblayerStringType($schemaDriver),
        ),
    );

    expect(DB::table($table)->insert([
        'email' => 'a@example.com',
        'name' => 'Alice',
        'age' => 30,
    ]))->toBeTrue();

    expect(DB::table($table)->insert([
        ['email' => 'b@example.com', 'name' => 'Bob', 'age' => 25],
        ['email' => 'c@example.com', 'name' => 'Chris', 'age' => 20],
    ]))->toBeTrue();

    $insertedId = DB::table($table)->insertGetId([
        'email' => 'd@example.com',
        'name' => 'Dora',
        'age' => 18,
    ]);
    expect((int) $insertedId)->toBeGreaterThan(0);

    $returned = DB::table($table)->insertReturning([
        'email' => 'e@example.com',
        'name' => 'Eve',
        'age' => 19,
    ], 'id');
    expect($returned)->toBeArray();
    expect((int) ($returned['id'] ?? 0))->toBeGreaterThan(0);

    if (DB::connection()->supportsInsertIgnore()) {
        expect(DB::table($table)->insertIgnore([
            'email' => 'a@example.com',
            'name' => 'Duplicate',
            'age' => 99,
        ]))->toBeFalse();
    } else {
        expect(static function () use ($table): bool {
            return DB::table($table)->insertIgnore([
                'email' => 'a@example.com',
                'name' => 'Duplicate',
                'age' => 99,
            ]);
        })->toThrow(QueryException::class);
    }

    expect((int) DB::table($table)->count())->toBe(5);

    $updated = DB::table($table)
        ->where('email', 'b@example.com')
        ->update(['age' => 26]);
    expect($updated)->toBe(1);
    expect((int) DB::table($table)->where('email', 'b@example.com')->value('age'))->toBe(26);

    expect(DB::table($table)->upsert([
        'email' => 'f@example.com',
        'name' => 'Frank',
        'age' => 33,
    ], ['email'], ['name', 'age']))->toBeTrue();
    expect(DB::table($table)->where('email', 'f@example.com')->value('name'))->toBe('Frank');

    $deleted = DB::table($table)
        ->where('email', 'c@example.com')
        ->delete();
    expect($deleted)->toBe(1);
    expect((int) DB::table($table)->count())->toBe(5);

    expect(DB::table($table)->truncate())->toBeTrue();
    expect((int) DB::table($table)->count())->toBe(0);
})->with('dblayer_drivers');
