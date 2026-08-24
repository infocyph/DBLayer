<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Tests\Unit;

use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Query\Repository as QueryRepository;
use Infocyph\DBLayer\Repository\TableRepository;

final class RepositoryStaticSoftDeleteRecord extends TableRepository
{
    protected static string $table = 'repository_static_soft_delete_records';

    protected static function configureRepository(QueryRepository $repository): QueryRepository
    {
        return $repository->enableSoftDeletes();
    }
}

beforeEach(function (): void {
    RepositoryStaticSoftDeleteRecord::flushDefinition();
    DB::purge();
    DB::setSecurityDefaults([], false);
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]);

    DB::statement('create table repository_static_soft_delete_records (
        id integer primary key autoincrement,
        name text not null,
        deleted_at text null
    )');

    DB::table('repository_static_soft_delete_records')->insert([
        ['name' => 'visible', 'deleted_at' => null],
        ['name' => 'deleted', 'deleted_at' => '2026-08-24 00:00:00'],
    ]);
});

afterEach(function (): void {
    DB::purge();
});

it('routes static soft-delete modes through the fluent repository query', function (): void {
    $withTrashed = RepositoryStaticSoftDeleteRecord::withTrashed()
        ->where('id', '>', 0)
        ->orderBy('id')
        ->get()
        ->toArray();

    $onlyTrashed = RepositoryStaticSoftDeleteRecord::onlyTrashed()
        ->where('id', '>', 0)
        ->get()
        ->toArray();

    $visible = RepositoryStaticSoftDeleteRecord::withoutTrashed()
        ->where('id', '>', 0)
        ->get()
        ->toArray();

    expect(array_column($withTrashed, 'name'))->toBe(['visible', 'deleted'])
        ->and(array_column($onlyTrashed, 'name'))->toBe(['deleted'])
        ->and(array_column($visible, 'name'))->toBe(['visible']);
});
