<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\DBLayer\Query\ConnectionRepository;
use Infocyph\DBLayer\Query\Executor;
use Infocyph\DBLayer\Query\ResultProcessor;

function dblayerConnectionRepositoryConnection(): Connection
{
    $connection = new Connection(ConnectionConfig::fromArray([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]), 'repository-instance');
    $connection->statement('create table repository_items (id integer primary key, value text not null)');
    $connection->table('repository_items')->insert([
        ['id' => 1, 'value' => 'one'],
        ['id' => 2, 'value' => 'two'],
    ]);

    return $connection;
}

it('builds a repository from only its scoped connection', function (): void {
    $connection = dblayerConnectionRepositoryConnection();
    $repository = new class ($connection) extends ConnectionRepository {
        protected function table(): string
        {
            return 'repository_items';
        }
    };

    expect($repository->find(1))->toBe(['id' => 1, 'value' => 'one'])
        ->and($repository->pluck('value'))->toBe([1 => 'one', 2 => 'two']);
});

it('still accepts explicit executor and result processor overrides', function (): void {
    $connection = dblayerConnectionRepositoryConnection();
    $executor = new Executor($connection);
    $results = new ResultProcessor();
    $repository = new class ($connection, $executor, $results) extends ConnectionRepository {
        protected function table(): string
        {
            return 'repository_items';
        }
    };

    expect($repository->count())->toBe(2);
});
