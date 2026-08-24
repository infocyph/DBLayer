<?php

declare(strict_types=1);

use Infocyph\ArrayKit\Collection\Collection;
use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Query\QueryBuilder;
use Infocyph\DBLayer\Repository\Relation;
use Infocyph\DBLayer\Repository\RelationDefinition;
use Infocyph\DBLayer\Repository\RepositoryQuery;
use Infocyph\DBLayer\Repository\TableRepository;

final class RepositoryMatrixPost extends TableRepository
{
    protected static ?string $connection = 'repository_evolution_matrix';

    protected static array $creatable = ['title', 'active'];

    protected static string $createdAt = 'created_on';

    protected static array $defaults = ['active' => true];

    protected static int $perPage = 1;

    protected static string $primaryKey = 'post_key';

    protected static string $table = 'repository_matrix_posts';

    protected static bool $timestamps = true;

    protected static array $updatable = ['title', 'active'];

    protected static string $updatedAt = 'updated_on';

    protected static function casts(): array
    {
        return ['active' => 'bool'];
    }

    /** @return array<string,callable(QueryBuilder):void> */
    protected static function globalScopes(): array
    {
        return [
            'active' => static function (QueryBuilder $query): void {
                $query->where('active', '=', 1);
            },
        ];
    }

    /** @return array<string,RelationDefinition> */
    protected static function relations(): array
    {
        return [
            'comments' => Relation::hasMany(
                RepositoryMatrixComment::class,
                foreignKey: 'post_id',
                localKey: 'post_key',
            ),
        ];
    }
}

final class RepositoryMatrixComment extends TableRepository
{
    protected static ?string $connection = 'repository_evolution_matrix';

    protected static array $creatable = ['post_id', 'score'];

    protected static string $table = 'repository_matrix_comments';

    protected static array $updatable = ['score'];
}

final readonly class RepositoryMatrixPostData
{
    public function __construct(
        public int $post_key,
        public string $title,
        public bool $active,
    ) {}
}

function setupRepositoryEvolutionMatrix(string $driver): void
{
    DB::setSecurityDefaults([], false);
    dblayerAddConnectionForDriver($driver, 'repository_evolution_matrix');
    $schemaDriver = dblayerConnectionDriver('repository_evolution_matrix');

    dblayerDropTable('repository_matrix_comments', 'repository_evolution_matrix');
    dblayerDropTable('repository_matrix_posts', 'repository_evolution_matrix');

    DB::statement(
        sprintf(
            'create table repository_matrix_posts (
                %s,
                title %s not null unique,
                active integer not null,
                created_on %s not null,
                updated_on %s not null
            )',
            dblayerAutoIncrementPrimaryKey($schemaDriver, 'post_key'),
            dblayerStringType($schemaDriver, 191),
            dblayerDateTimeType($schemaDriver),
            dblayerDateTimeType($schemaDriver),
        ),
        [],
        'repository_evolution_matrix',
    );
    DB::statement(
        sprintf(
            'create table repository_matrix_comments (
                %s,
                post_id integer not null,
                score integer not null
            )',
            dblayerAutoIncrementPrimaryKey($schemaDriver),
        ),
        [],
        'repository_evolution_matrix',
    );
}

it('keeps repository query semantics and relation features portable across drivers', function (string $driver): void {
    setupRepositoryEvolutionMatrix($driver);

    DB::table('repository_matrix_posts', 'repository_evolution_matrix')->insert([
        'title' => 'Hidden',
        'active' => 0,
        'created_on' => '2026-01-01 00:00:00',
        'updated_on' => '2026-01-01 00:00:00',
    ]);
    $post = RepositoryMatrixPost::create(['title' => 'Published']);
    $postKey = (int) $post['post_key'];

    RepositoryMatrixComment::bulkInsert([
        ['post_id' => $postKey, 'score' => 3],
        ['post_id' => $postKey, 'score' => 7],
    ]);

    $direct = RepositoryMatrixPost::get()->toArray();
    $fluent = RepositoryMatrixPost::query()->get()->toArray();
    $found = RepositoryMatrixPost::query()->find($postKey);
    $many = RepositoryMatrixPost::query()->findMany([$postKey]);
    $cursorRows = iterator_to_array(RepositoryMatrixPost::query()->cursor(), false);
    $streamRows = iterator_to_array(RepositoryMatrixPost::query()->stream(), false);

    expect(RepositoryMatrixPost::query())->toBeInstanceOf(RepositoryQuery::class)
        ->and(RepositoryMatrixPost::builder())->toBeInstanceOf(QueryBuilder::class)
        ->and(RepositoryMatrixPost::definition()->connection)->toBe('repository_evolution_matrix')
        ->and($direct)->toBe($fluent)
        ->and($direct)->toHaveCount(1)
        ->and($direct[0]['active'])->toBeTrue()
        ->and($found['post_key'] ?? null)->toBe($postKey)
        ->and($many)->toBeInstanceOf(Collection::class)
        ->and($many->count())->toBe(1)
        ->and($cursorRows[0]['active'] ?? null)->toBeTrue()
        ->and($streamRows[0]['active'] ?? null)->toBeTrue()
        ->and(RepositoryMatrixPost::query()->pluck('title'))->toBe(['Published'])
        ->and(RepositoryMatrixPost::query()->map(
            static fn(array $row): string => $row['title'],
        )->toArray())->toBe(['Published']);

    $dto = RepositoryMatrixPost::query()->firstInto(RepositoryMatrixPostData::class);
    $dtos = RepositoryMatrixPost::query()->mapInto(RepositoryMatrixPostData::class);

    expect($dto)->toBeInstanceOf(RepositoryMatrixPostData::class)
        ->and($dto?->post_key)->toBe($postKey)
        ->and($dtos->count())->toBe(1);

    $aggregate = RepositoryMatrixPost::query()
        ->with('comments')
        ->withCount('comments')
        ->withExists('comments')
        ->withSum('comments', 'score')
        ->withAvg('comments', 'score')
        ->withMin('comments', 'score')
        ->withMax('comments', 'score')
        ->first();

    expect($aggregate['comments'] ?? null)->toHaveCount(2)
        ->and($aggregate['comments_count'] ?? null)->toBe(2)
        ->and($aggregate['comments_exists'] ?? null)->toBeTrue()
        ->and((int) ($aggregate['comments_sum_score'] ?? 0))->toBe(10)
        ->and((float) ($aggregate['comments_avg_score'] ?? 0))->toBe(5.0)
        ->and((int) ($aggregate['comments_min_score'] ?? 0))->toBe(3)
        ->and((int) ($aggregate['comments_max_score'] ?? 0))->toBe(7)
        ->and(RepositoryMatrixPost::query()->whereHas('comments')->count())->toBe(1)
        ->and(RepositoryMatrixPost::query()->whereRelation('comments', 'score', '>', 5)->count())->toBe(1)
        ->and(RepositoryMatrixPost::query()->paginate()->perPage())->toBe(1)
        ->and(RepositoryMatrixPost::query()->simplePaginate()->perPage())->toBe(1)
        ->and(RepositoryMatrixPost::query()->cursorPaginate()->perPage())->toBe(1);

    expect(RepositoryMatrixPost::query()->withoutGlobalScope('active')->count())->toBe(2)
        ->and(RepositoryMatrixPost::query()->count())->toBe(1);

    expect(RepositoryMatrixPost::upsert(
        ['title' => 'Published', 'active' => true],
        ['title'],
        ['active'],
    ))->toBeTrue();

    $connection = RepositoryMatrixPost::connection();
    $connection->begin();
    RepositoryMatrixPost::create(['title' => 'Rolled back']);
    $connection->rollBack();

    expect(RepositoryMatrixPost::query()->where('title', '=', 'Rolled back')->exists())->toBeFalse();
})->with('dblayer_drivers');
