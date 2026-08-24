<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Query\QueryBuilder;
use Infocyph\DBLayer\Repository\TableRepository;

final class RepositoryEvolutionUser extends TableRepository
{
    protected static string $table = 'repository_evolution_users';

    protected static string $primaryKey = 'user_key';

    protected static function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    protected static function configureQuery(QueryBuilder $query): QueryBuilder
    {
        return $query->where('active', '=', 1);
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
        'create table repository_evolution_users (
            user_key integer primary key autoincrement,
            name text not null,
            active integer not null
        )',
    );

    DB::table('repository_evolution_users')->insert([
        ['name' => 'Active One', 'active' => 1],
        ['name' => 'Inactive', 'active' => 0],
        ['name' => 'Active Two', 'active' => 1],
    ]);
});

afterEach(function (): void {
    DB::purge();
});

it('applies query defaults consistently to repository and fluent static reads', function (): void {
    $direct = RepositoryEvolutionUser::get()->toArray();
    $fluent = RepositoryEvolutionUser::where('user_key', '>', 0)->get()->toArray();

    expect(array_column($direct, 'name'))->toBe(['Active One', 'Active Two'])
        ->and(array_column($fluent, 'name'))->toBe(['Active One', 'Active Two'])
        ->and($direct[0]['active'])->toBeTrue()
        ->and($fluent[0]['active'])->toBeTrue();
});

it('uses declarative primary-key metadata for repository identity helpers', function (): void {
    $row = RepositoryEvolutionUser::find(3);

    expect($row)->not->toBeNull()
        ->and($row['user_key'])->toBe(3)
        ->and($row['name'])->toBe('Active Two');
});

it('keeps raw builder access explicit', function (): void {
    $rawRows = RepositoryEvolutionUser::builder()
        ->orderBy('user_key')
        ->get();

    expect(array_column($rawRows, 'name'))->toBe(['Active One', 'Active Two']);
});
