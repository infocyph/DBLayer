<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Tests\Unit;

use DateTimeImmutable;
use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Repository\Casts\AttributeCast;
use Infocyph\DBLayer\Repository\TableRepository;
use stdClass;

enum RepositoryCastingStatus: string
{
    case Draft = 'draft';

    case Published = 'published';
}

final class RepositoryPrefixCast implements AttributeCast
{
    #[\Override]
    public function get(mixed $value, array $row): mixed
    {
        unset($row);

        return is_string($value) && str_starts_with($value, 'db:')
            ? substr($value, 3)
            : $value;
    }

    #[\Override]
    public function set(mixed $value, array $attributes): mixed
    {
        unset($attributes);

        return is_string($value) ? 'db:' . $value : $value;
    }
}

final class RepositoryCastingRecord extends TableRepository
{
    protected static array $creatable = [
        'status',
        'token',
        'published_at',
        'amount',
        'payload',
        'scheduled_date',
    ];

    protected static string $table = 'repository_casting_records';

    protected static function casts(): array
    {
        return [
            'status' => RepositoryCastingStatus::class,
            'token' => new RepositoryPrefixCast(),
            'published_at' => 'immutable_datetime',
            'amount' => 'decimal:2',
            'payload' => 'object',
            'scheduled_date' => 'immutable_date',
        ];
    }
}

beforeEach(function (): void {
    DB::purge();
    DB::setSecurityDefaults([], false);
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]);

    DB::statement(
        'create table repository_casting_records (
            id integer primary key autoincrement,
            status text not null,
            token text not null,
            published_at text null,
            amount text null,
            payload text null,
            scheduled_date text null
        )',
    );
});

afterEach(function (): void {
    RepositoryCastingRecord::flushDefinition();
    DB::purge();
});

it('persists backed enum values and hydrates enum cases', function (): void {
    $record = RepositoryCastingRecord::create([
        'status' => RepositoryCastingStatus::Published,
        'token' => 'secret',
        'published_at' => new DateTimeImmutable('2026-08-24 12:30:00'),
    ]);

    $raw = DB::table('repository_casting_records')->first();

    expect($raw['status'])->toBe('published')
        ->and($record['status'])->toBe(RepositoryCastingStatus::Published);
});

it('uses custom bidirectional casts for repository reads and writes', function (): void {
    $record = RepositoryCastingRecord::create([
        'status' => RepositoryCastingStatus::Draft,
        'token' => 'secret',
        'published_at' => null,
    ]);

    $raw = DB::table('repository_casting_records')->first();

    expect($raw['token'])->toBe('db:secret')
        ->and($record['token'])->toBe('secret');
});

it('hydrates immutable datetime values while keeping repository writes driver-formatted', function (): void {
    $date = new DateTimeImmutable('2026-08-24 12:30:00');

    $record = RepositoryCastingRecord::create([
        'status' => RepositoryCastingStatus::Draft,
        'token' => 'secret',
        'published_at' => $date,
    ]);

    expect($record['published_at'])->toBeInstanceOf(DateTimeImmutable::class)
        ->and($record['published_at']->format('Y-m-d H:i:s'))->toBe('2026-08-24 12:30:00');
});

it('compiles fixed-scale decimal casts once in repository metadata', function (): void {
    $record = RepositoryCastingRecord::create([
        'status' => RepositoryCastingStatus::Draft,
        'token' => 'secret',
        'amount' => '12345678901234567890.126',
    ]);

    $raw = DB::table('repository_casting_records')->first();

    expect($raw['amount'])->toBe('12345678901234567890.13')
        ->and($record['amount'])->toBe('12345678901234567890.13');
});

it('casts json payloads to objects and persists object values as json', function (): void {
    $payload = (object) ['name' => 'DBLayer', 'version' => 6];

    $record = RepositoryCastingRecord::create([
        'status' => RepositoryCastingStatus::Draft,
        'token' => 'secret',
        'payload' => $payload,
    ]);

    $raw = DB::table('repository_casting_records')->first();

    expect($raw['payload'])->toBe('{"name":"DBLayer","version":6}')
        ->and($record['payload'])->toBeInstanceOf(stdClass::class)
        ->and($record['payload']->name)->toBe('DBLayer');
});

it('supports immutable date aliases with date-only persistence', function (): void {
    $record = RepositoryCastingRecord::create([
        'status' => RepositoryCastingStatus::Draft,
        'token' => 'secret',
        'scheduled_date' => new DateTimeImmutable('2026-08-24 18:45:11'),
    ]);

    $raw = DB::table('repository_casting_records')->first();

    expect($raw['scheduled_date'])->toBe('2026-08-24')
        ->and($record['scheduled_date'])->toBeInstanceOf(DateTimeImmutable::class)
        ->and($record['scheduled_date']->format('Y-m-d H:i:s'))->toBe('2026-08-24 00:00:00');
});

it('applies repository write casts to set-based fluent updates', function (): void {
    $record = RepositoryCastingRecord::create([
        'status' => RepositoryCastingStatus::Draft,
        'token' => 'secret',
        'amount' => '1.00',
        'payload' => (object) ['stage' => 'before'],
    ]);

    $affected = RepositoryCastingRecord::repositoryQuery()
        ->where('id', '=', $record['id'])
        ->update([
            'status' => RepositoryCastingStatus::Published,
            'token' => 'rotated',
            'amount' => '12.345',
            'payload' => (object) ['stage' => 'after'],
            'scheduled_date' => new DateTimeImmutable('2026-08-25 22:15:00'),
        ]);

    $raw = DB::table('repository_casting_records')->where('id', '=', $record['id'])->first();
    $updated = RepositoryCastingRecord::find($record['id']);

    expect($affected)->toBe(1)
        ->and($raw['status'])->toBe('published')
        ->and($raw['token'])->toBe('db:rotated')
        ->and($raw['amount'])->toBe('12.35')
        ->and($raw['payload'])->toBe('{"stage":"after"}')
        ->and($raw['scheduled_date'])->toBe('2026-08-25')
        ->and($updated['status'])->toBe(RepositoryCastingStatus::Published)
        ->and($updated['token'])->toBe('rotated')
        ->and($updated['amount'])->toBe('12.35')
        ->and($updated['payload'])->toBeInstanceOf(stdClass::class)
        ->and($updated['payload']->stage)->toBe('after');
});
