<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Exceptions\RecordNotFoundException;
use Infocyph\DBLayer\Tests\Fixtures\BoundRepositoryConsumer;
use Infocyph\DBLayer\Tests\Fixtures\TableRepositoryBoundUser;
use Infocyph\InterMix\DI\Build\StaticRuntimeGenerator;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\RuntimeContainerInterface;
use Infocyph\InterMix\DI\Support\LifetimeEnum;

/** Exercise the published converter through each production container mode. */
function boundRepositoryRuntime(string $mode, string $path): RuntimeContainerInterface
{
    $builder = new ContainerBuilder()->enableAutoBind()->input('user')
        ->autowire('consumer', BoundRepositoryConsumer::class, lifetime: LifetimeEnum::Scoped);
    if ($mode === 'dynamic') {
        return $builder->build();
    }
    $report = $builder->compile($path, strict: true);

    return $mode === 'compiled'
        ? ContainerBuilder::loadProductionArtifact($path, $report['graph'])
        : new StaticRuntimeGenerator()->loadPrevalidated($path, $report['digest']);
}

it('binds real InterMix constructor and invocation parameters without leaking request data', function (string $mode): void {
    DB::setSecurityDefaults([], false);
    dblayerAddConnectionForDriver('sqlite', 'table_repository_conn');
    DB::statement('create table users (id integer primary key, tenant_id integer, email text, name text, active integer, deleted_at text)', [], 'table_repository_conn');
    DB::table('users', 'table_repository_conn')->insert([
        ['id' => 0, 'tenant_id' => 10, 'email' => 'zero@example.test', 'name' => 'Zero', 'active' => 1, 'deleted_at' => null],
        ['id' => 1, 'tenant_id' => 10, 'email' => 'one@example.test', 'name' => 'One', 'active' => 1, 'deleted_at' => null],
        ['id' => 2, 'tenant_id' => 20, 'email' => 'hidden@example.test', 'name' => 'Hidden', 'active' => 1, 'deleted_at' => null],
    ]);
    $path = sys_get_temp_dir() . '/dblayer-model-binding-' . bin2hex(random_bytes(8)) . '.php';
    try {
        $runtime = boundRepositoryRuntime($mode, $path);
        $first = $runtime->withinScope('request', fn($scope) => $scope->get('consumer'), ['user' => '0']);
        $second = $runtime->withinScope('request', fn($scope) => $scope->get('consumer'), ['user' => 1]);
        expect($first->user->name)->toBe('Zero')->and($second->user->name)->toBe('One')
            ->and($first->user)->not->toBe($second->user);
        $callback = static fn(TableRepositoryBoundUser $user): TableRepositoryBoundUser => $user;
        expect($runtime->invoke($callback, ['user' => '0'])->name)->toBe('Zero')
            ->and($runtime->invoke($callback, [$first->user]))->toBe($first->user)
            ->and($runtime->invoke(static fn(?TableRepositoryBoundUser $user) => $user, ['user' => null]))->toBeNull();
        expect(fn() => $runtime->invoke($callback, ['user' => 2]))->toThrow(RecordNotFoundException::class);
        $one = new Fiber(fn() => $runtime->withinScope('request', function ($scope) {
            $model = $scope->get('consumer')->user;
            Fiber::suspend($model->name);

            return $model->name;
        }, ['user' => 0]));
        $two = new Fiber(fn() => $runtime->withinScope('request', fn($scope) => $scope->get('consumer')->user->name, ['user' => 1]));
        expect($one->start())->toBe('Zero');
        $two->start();
        $one->resume();
        expect($one->getReturn())->toBe('Zero')->and($two->getReturn())->toBe('One');
    } finally {
        dblayerRemoveTestPath($path);
        dblayerRemoveTestPath($path . '.builds');
    }
    expect(file_exists($path))->toBeFalse()->and(file_exists($path . '.builds'))->toBeFalse();
})->with(['dynamic', 'compiled', 'prevalidated']);
