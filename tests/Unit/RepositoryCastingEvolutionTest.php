<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Tests\Unit;

use DateTimeImmutable;
use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Repository\Casts\AttributeCast;
use Infocyph\DBLayer\Repository\TableRepository;

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
    protected static string $table = 'repository_casting_records';

    protected static array $creatable = [
        'status',
        'token',
        'published_at',
    ];

    protected static function casts(): array
    {
        return [
            'status' => RepositoryCastingStatus::class,
            'token' => new RepositoryPrefixCast(),
            'published_at' => 'immutable_datetime',
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
            published_at text null
        )',
    );
});

afterEach(function (): void {
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
