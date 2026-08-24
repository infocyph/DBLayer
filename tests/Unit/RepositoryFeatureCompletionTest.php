<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Tests\Unit;

use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Exceptions\UnwritableAttributeException;
use Infocyph\DBLayer\Query\QueryBuilder;
use Infocyph\DBLayer\Query\Repository as QueryRepository;
use Infocyph\DBLayer\Repository\Relation;
use Infocyph\DBLayer\Repository\RelationDefinition;
use Infocyph\DBLayer\Repository\RepositoryQuery;
use Infocyph\DBLayer\Repository\TableQueryRepository;
use Infocyph\DBLayer\Repository\TableRepository;
use InvalidArgumentException;

final class CompletionAuthor extends TableRepository
{
    protected static string $table = 'completion_authors';

    /** @return array<string,RelationDefinition> */
    protected static function relations(): array
    {
        return [
            'posts' => Relation::hasMany(CompletionPost::class, 'author_id'),
            'comments' => Relation::hasMany(CompletionComment::class, 'author_id'),
        ];
    }
}

final class CompletionPost extends TableRepository
{
    /** @var list<string> */
    public static array $commitEvents = [];

    /** @var list<string> */
    public static array $operationEvents = [];

    protected static array $creatable = ['author_id', 'title', 'status'];

    protected static int $maxRelationDepth = 2;

    protected static string $table = 'completion_posts';

    protected static bool $timestamps = true;

    protected static array $updatable = ['title', 'status'];

    protected static function configureRepository(QueryRepository $repository): QueryRepository
    {
        $repository->enableSoftDeletes();

        if ($repository instanceof TableQueryRepository) {
            $repository
                ->beforeBulkUpdate(static function (): void {
                    self::$operationEvents[] = 'before_bulk_update';
                })
                ->afterBulkUpdate(static function (): void {
                    self::$operationEvents[] = 'after_bulk_update';
                })
                ->afterCommit(static function (
                    string $operation,
                    array $context,
                    TableQueryRepository $repository,
                ): void {
                    unset($context, $repository);
                    self::$commitEvents[] = $operation;
                });
        }

        return $repository;
    }

    /** @return array<string,RelationDefinition> */
    protected static function relations(): array
    {
        return [
            'author' => Relation::belongsTo(CompletionAuthor::class, 'author_id'),
            'comments' => Relation::hasMany(CompletionComment::class, 'post_id'),
            'tags' => Relation::belongsToMany(
                CompletionTag::class,
                'completion_post_tag',
                'post_id',
                'tag_id',
            )->withPivot('assigned_by', 'priority')->asPivot('assignment'),
            'images' => Relation::morphMany(
                CompletionImage::class,
                'imageable',
                'post',
            ),
            'labels' => Relation::morphToMany(
                CompletionTag::class,
                'completion_taggables',
                'taggable_id',
                'tag_id',
                'taggable_type',
                'post',
            )->withPivot('context')->asPivot('tagging'),
        ];
    }
}

final class CompletionComment extends TableRepository
{
    protected static string $table = 'completion_comments';

    protected static function casts(): array
    {
        return ['score' => 'integer'];
    }

    /** @return array<string,RelationDefinition> */
    protected static function relations(): array
    {
        return [
            'post' => Relation::belongsTo(CompletionPost::class, 'post_id'),
            'author' => Relation::belongsTo(CompletionAuthor::class, 'author_id'),
            'images' => Relation::morphMany(
                CompletionImage::class,
                'imageable',
                'comment',
            ),
        ];
    }
}

final class CompletionTag extends TableRepository
{
    protected static string $table = 'completion_tags';

    /** @return array<string,RelationDefinition> */
    protected static function relations(): array
    {
        return [
            'posts' => Relation::morphedByMany(
                CompletionPost::class,
                'completion_taggables',
                'tag_id',
                'taggable_id',
                'taggable_type',
                'post',
            )->withPivot('context')->asPivot('tagging'),
        ];
    }
}

final class CompletionImage extends TableRepository
{
    protected static string $table = 'completion_images';
}

final class CompletionActivity extends TableRepository
{
    protected static int $maxRelationDepth = 2;

    protected static string $table = 'completion_activities';

    /** @return array<string,RelationDefinition> */
    protected static function relations(): array
    {
        return [
            'subject' => Relation::morphTo(
                'subject_type',
                'subject_id',
                [
                    'post' => CompletionPost::class,
                    'comment' => CompletionComment::class,
                ],
            ),
        ];
    }
}

beforeEach(function (): void {
    CompletionPost::$commitEvents = [];
    CompletionPost::$operationEvents = [];

    foreach ([
        CompletionAuthor::class,
        CompletionPost::class,
        CompletionComment::class,
        CompletionTag::class,
        CompletionImage::class,
        CompletionActivity::class,
    ] as $repository) {
        $repository::flushDefinition();
    }

    DB::purge();
    DB::setSecurityDefaults([], false);
    DB::addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]);

    DB::statement('create table completion_authors (
        id integer primary key autoincrement,
        name text not null
    )');

    DB::statement('create table completion_posts (
        id integer primary key autoincrement,
        author_id integer not null,
        title text not null,
        status text not null,
        deleted_at text null,
        created_at text null,
        updated_at text null
    )');

    DB::statement('create table completion_comments (
        id integer primary key autoincrement,
        post_id integer not null,
        author_id integer not null,
        body text not null,
        score integer not null
    )');

    DB::statement('create table completion_tags (
        id integer primary key autoincrement,
        name text not null
    )');

    DB::statement('create table completion_post_tag (
        post_id integer not null,
        tag_id integer not null,
        assigned_by integer null,
        priority integer null
    )');

    DB::statement('create table completion_taggables (
        taggable_id integer not null,
        tag_id integer not null,
        taggable_type text not null,
        context text null
    )');

    DB::statement('create table completion_images (
        id integer primary key autoincrement,
        imageable_type text not null,
        imageable_id integer not null,
        url text not null
    )');

    DB::statement('create table completion_activities (
        id integer primary key autoincrement,
        subject_type text not null,
        subject_id integer not null,
        label text not null
    )');

    DB::table('completion_authors')->insert([
        ['name' => 'Alice'],
        ['name' => 'Bob'],
    ]);

    DB::table('completion_posts')->insert([
        [
            'author_id' => 1,
            'title' => 'First',
            'status' => 'old',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ],
        [
            'author_id' => 2,
            'title' => 'Second',
            'status' => 'active',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ],
    ]);

    DB::table('completion_comments')->insert([
        ['post_id' => 1, 'author_id' => 2, 'body' => 'A', 'score' => 5],
        ['post_id' => 1, 'author_id' => 1, 'body' => 'B', 'score' => 3],
        ['post_id' => 2, 'author_id' => 1, 'body' => 'C', 'score' => 7],
    ]);

    DB::table('completion_tags')->insert([
        ['name' => 'red'],
        ['name' => 'blue'],
    ]);

    DB::table('completion_post_tag')->insert([
        ['post_id' => 1, 'tag_id' => 1, 'assigned_by' => 20, 'priority' => 2],
        ['post_id' => 1, 'tag_id' => 2, 'assigned_by' => 21, 'priority' => 1],
        ['post_id' => 2, 'tag_id' => 2, 'assigned_by' => 22, 'priority' => 3],
    ]);

    DB::table('completion_taggables')->insert([
        ['taggable_id' => 1, 'tag_id' => 1, 'taggable_type' => 'post', 'context' => 'featured'],
        ['taggable_id' => 2, 'tag_id' => 2, 'taggable_type' => 'post', 'context' => 'regular'],
        ['taggable_id' => 1, 'tag_id' => 2, 'taggable_type' => 'comment', 'context' => 'ignored'],
    ]);

    DB::table('completion_images')->insert([
        ['imageable_type' => 'post', 'imageable_id' => 1, 'url' => 'post-1.jpg'],
        ['imageable_type' => 'comment', 'imageable_id' => 1, 'url' => 'comment-1.jpg'],
    ]);

    DB::table('completion_activities')->insert([
        ['subject_type' => 'post', 'subject_id' => 1, 'label' => 'post activity'],
        ['subject_type' => 'comment', 'subject_id' => 1, 'label' => 'comment activity'],
    ]);
});

afterEach(function (): void {
    DB::purge();
});

it('supports bounded nested eager loading and enforces configured depth', function (): void {
    $post = CompletionPost::repositoryQuery()
        ->with('comments.author')
        ->where('id', '=', 1)
        ->first();

    expect($post)->not->toBeNull()
        ->and(array_column($post['comments'], 'body'))->toBe(['A', 'B'])
        ->and($post['comments'][0]['author']['name'])->toBe('Bob')
        ->and($post['comments'][1]['author']['name'])->toBe('Alice');

    expect(fn() => CompletionPost::repositoryQuery()->with('comments.author.posts'))
        ->toThrow(InvalidArgumentException::class);
});

it('projects normal pivot attributes under a configurable accessor', function (): void {
    $post = CompletionPost::repositoryQuery()
        ->with('tags')
        ->where('id', '=', 1)
        ->first();

    expect(array_column($post['tags'], 'name'))->toBe(['red', 'blue'])
        ->and($post['tags'][0]['assignment'])->toBe([
            'assigned_by' => 20,
            'priority' => 2,
        ]);
});

it('loads morph relations through explicit discriminators only', function (): void {
    $post = CompletionPost::repositoryQuery()
        ->with('images')
        ->where('id', '=', 1)
        ->first();

    $comment = CompletionComment::repositoryQuery()
        ->with('images')
        ->where('id', '=', 1)
        ->first();

    expect(array_column($post['images'], 'url'))->toBe(['post-1.jpg'])
        ->and(array_column($comment['images'], 'url'))->toBe(['comment-1.jpg']);
});

it('loads morph-to relations and nested relations for each mapped repository', function (): void {
    $activities = CompletionActivity::repositoryQuery()
        ->with('subject.author')
        ->orderBy('id')
        ->get()
        ->toArray();

    expect($activities[0]['subject']['title'])->toBe('First')
        ->and($activities[0]['subject']['author']['name'])->toBe('Alice')
        ->and($activities[1]['subject']['body'])->toBe('A')
        ->and($activities[1]['subject']['author']['name'])->toBe('Bob');
});

it('loads polymorphic many-to-many relations and inverse mappings with pivot data', function (): void {
    $post = CompletionPost::repositoryQuery()
        ->with('labels')
        ->where('id', '=', 1)
        ->first();

    $tag = CompletionTag::repositoryQuery()
        ->with('posts')
        ->where('id', '=', 1)
        ->first();

    expect(array_column($post['labels'], 'name'))->toBe(['red'])
        ->and($post['labels'][0]['tagging'])->toBe(['context' => 'featured'])
        ->and(array_column($tag['posts'], 'title'))->toBe(['First'])
        ->and($tag['posts'][0]['tagging'])->toBe(['context' => 'featured']);
});

it('projects direct and pivot relation aggregates without hydrating relation graphs', function (): void {
    $post = CompletionPost::repositoryQuery()
        ->withCount('comments', 'tags', 'labels')
        ->withExists('comments')
        ->withSum('comments', 'score')
        ->withAvg('comments', 'score')
        ->withMin('comments', 'score')
        ->withMax('comments', 'score')
        ->where('id', '=', 1)
        ->first();

    expect($post['comments_count'])->toBe(2)
        ->and($post['tags_count'])->toBe(2)
        ->and($post['labels_count'])->toBe(1)
        ->and($post['comments_exists'])->toBeTrue()
        ->and((float) $post['comments_sum_score'])->toBe(8.0)
        ->and((float) $post['comments_avg_score'])->toBe(4.0)
        ->and((int) $post['comments_min_score'])->toBe(3)
        ->and((int) $post['comments_max_score'])->toBe(5);
});

it('filters by direct, polymorphic, and pivot relation existence', function (): void {
    $highScore = CompletionPost::repositoryQuery()
        ->whereRelation('comments', 'score', '>', 5)
        ->get()
        ->toArray();

    $withoutVeryHigh = CompletionPost::repositoryQuery()
        ->whereDoesntHave(
            'comments',
            static fn(QueryBuilder $query) => $query->where('score', '>', 6),
        )
        ->get()
        ->toArray();

    $withImages = CompletionPost::repositoryQuery()->whereHas('images')->get()->toArray();
    $withLabels = CompletionPost::repositoryQuery()->whereHas('labels')->get()->toArray();

    expect(array_column($highScore, 'title'))->toBe(['Second'])
        ->and(array_column($withoutVeryHigh, 'title'))->toBe(['First'])
        ->and(array_column($withImages, 'title'))->toBe(['First'])
        ->and(array_column($withLabels, 'title'))->toBe(['First', 'Second']);
});

it('keeps fluent mutations inside repository write policy and soft-delete semantics', function (): void {
    $inserted = CompletionPost::repositoryQuery()->insert([
        'author_id' => 1,
        'title' => 'Third',
        'status' => 'draft',
    ]);

    expect($inserted)->toBeTrue();

    $affected = CompletionPost::repositoryQuery()
        ->where('status', '=', 'draft')
        ->update(['status' => 'published']);

    expect($affected)->toBe(1)
        ->and(CompletionPost::$operationEvents)->toBe([
            'before_bulk_update',
            'after_bulk_update',
        ])
        ->and(CompletionPost::repositoryQuery()->where('status', '=', 'published')->count())->toBe(1);

    expect(fn() => CompletionPost::repositoryQuery()
        ->where('id', '=', 1)
        ->update(['author_id' => 2]))
        ->toThrow(UnwritableAttributeException::class);

    expect(CompletionPost::repositoryQuery()->where('id', '=', 1)->delete())->toBe(1)
        ->and(CompletionPost::repositoryQuery()->where('id', '=', 1)->count())->toBe(0)
        ->and(CompletionPost::repositoryQuery()->withTrashed()->where('id', '=', 1)->count())->toBe(1)
        ->and(CompletionPost::repositoryQuery()->onlyTrashed()->where('id', '=', 1)->restore())->toBe(1)
        ->and(CompletionPost::repositoryQuery()->where('id', '=', 1)->count())->toBe(1);
});

it('fires after-commit callbacks only after top-level commit and discards them on rollback', function (): void {
    $connection = CompletionPost::connection();

    $connection->begin();
    CompletionPost::create([
        'author_id' => 1,
        'title' => 'Committed',
        'status' => 'draft',
    ]);

    expect(CompletionPost::$commitEvents)->toBe([]);

    $connection->commit();

    expect(CompletionPost::$commitEvents)->toBe(['create']);

    CompletionPost::$commitEvents = [];
    $connection->begin();
    CompletionPost::create([
        'author_id' => 1,
        'title' => 'Rolled back',
        'status' => 'draft',
    ]);
    $connection->rollBack();

    expect(CompletionPost::$commitEvents)->toBe([])
        ->and(CompletionPost::repositoryQuery()->where('title', '=', 'Rolled back')->count())->toBe(0);
});

it('promotes after-commit callbacks through nested savepoints to the top-level commit', function (): void {
    $connection = CompletionPost::connection();

    $connection->begin();
    $connection->begin();
    CompletionPost::create([
        'author_id' => 1,
        'title' => 'Nested',
        'status' => 'draft',
    ]);

    $connection->commit();
    expect(CompletionPost::$commitEvents)->toBe([]);

    $connection->commit();
    expect(CompletionPost::$commitEvents)->toBe(['create']);
});

it('prunes in bounded repository-aware batches using soft or force deletion', function (): void {
    CompletionPost::repositoryQuery()->insert([
        ['author_id' => 1, 'title' => 'Old A', 'status' => 'prune'],
        ['author_id' => 1, 'title' => 'Old B', 'status' => 'prune'],
        ['author_id' => 1, 'title' => 'Old C', 'status' => 'prune'],
    ]);

    $scope = static function (RepositoryQuery $query): void {
        $query->where('status', '=', 'prune');
    };

    $softDeleted = CompletionPost::pruner()->prune($scope, chunkSize: 2);

    expect($softDeleted)->toBe(3)
        ->and(CompletionPost::repositoryQuery()->where('status', '=', 'prune')->count())->toBe(0)
        ->and(CompletionPost::repositoryQuery()->onlyTrashed()->where('status', '=', 'prune')->count())->toBe(3);

    $forceDeleted = CompletionPost::pruner()->prune(
        static function (RepositoryQuery $query): void {
            $query->withTrashed()->where('status', '=', 'prune');
        },
        chunkSize: 2,
        force: true,
    );

    expect($forceDeleted)->toBe(3)
        ->and(CompletionPost::repositoryQuery()->withTrashed()->where('status', '=', 'prune')->count())->toBe(0);
});
