<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Examples\ModelBinding;

use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Exceptions\RecordNotFoundException;
use Infocyph\DBLayer\Query\Repository;
use Infocyph\DBLayer\Repository\TableRepository;
use Infocyph\InterMix\DI\ContainerBuilder;

require __DIR__ . '/../vendor/autoload.php';

/** One table definition supports static queries and inherited model binding. */
final class User extends TableRepository
{
    protected static ?string $connection = 'model_binding_example';

    protected static array $creatable = ['user_key', 'name', 'active'];

    protected static string $primaryKey = 'user_key';

    protected static string $table = 'example_bound_users';

    protected static array $updatable = ['name', 'active'];

    protected static function casts(): array
    {
        return ['active' => 'boolean'];
    }

    protected static function configureRepository(Repository $repository): Repository
    {
        return $repository->forTenant(10)->enableSoftDeletes();
    }
}

// Applications can register a normal server/proxy connection instead. Binding
// uses this named connection; it never creates workers or event loops.
DB::addConnection(['driver' => 'sqlite', 'database' => ':memory:'], 'model_binding_example');
DB::statement('create table example_bound_users (user_key text primary key, tenant_id integer, name text, active integer, deleted_at text)', [], 'model_binding_example');
User::create(['user_key' => 'user-42', 'name' => 'Alice', 'active' => true]);

// Works directly, through a framework, or through another library forwarding
// the parameter. No container package is required for direct binding.
$user = User::fromBoundValue('user-42');
$user->name = 'Alice Updated'; // Changes memory only.
$user->save();                // Applies normal scopes, casts and write rules.

$handler = static fn(User $user): array => $user->toArray();
$resolved = $handler($user);
if (class_exists(ContainerBuilder::class)) {
    // Enable this on the host's container, then forward its route/task values.
    // InterMix 11.2 discovers the inherited fromBoundValue() automatically.
    $container = new ContainerBuilder()->enableAutoBind()->input('user')->build();
    $resolved = $container->withinScope(
        'request',
        static fn($scope): mixed => $scope->invoke($handler),
        ['user' => 'user-42'],
    );
}

$user->delete(); // Existing soft-delete policy applies.
$missing = false;

try {
    User::fromBoundValue('user-42');
} catch (RecordNotFoundException) {
    // An HTTP framework can map this domain exception to its normal 404 reply.
    $missing = true;
}

fwrite(STDOUT, json_encode(['bound' => $resolved, 'deleted' => !$user->exists, 'missing' => $missing], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);
