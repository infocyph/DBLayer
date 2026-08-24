<?php

// examples/complete_repository.php

declare(strict_types=1);

namespace Infocyph\DBLayer\Examples\Repository;

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Query\QueryBuilder;
use Infocyph\DBLayer\Query\Repository as QueryRepository;
use Infocyph\DBLayer\Repository\Relation;
use Infocyph\DBLayer\Repository\RelationDefinition;
use Infocyph\DBLayer\Repository\RepositoryQuery;
use Infocyph\DBLayer\Repository\TableQueryRepository;
use Infocyph\DBLayer\Repository\TableRepository;

require __DIR__ . '/../vendor/autoload.php';

enum PostStatus: string
{
    case Archived = 'archived';

    case Draft = 'draft';

    case Published = 'published';
}

/** A minimal related repository used by PostRepository::author. */
final class AuthorRepository extends TableRepository
{
    protected static ?string $connection = 'repository_example';

    protected static string $table = 'example_authors';
}

/** Related rows retain their own casts and repository policy when eager loaded. */
final class CommentRepository extends TableRepository
{
    protected static ?string $connection = 'repository_example';

    protected static string $table = 'example_comments';

    protected static function casts(): array
    {
        return [
            'approved' => 'boolean',
            'score' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}

final class TagRepository extends TableRepository
{
    protected static ?string $connection = 'repository_example';

    protected static string $table = 'example_tags';
}

/** Application services can replace this recorder with an event bus or outbox. */
final class RepositoryEvents
{
    /** @var list<string> */
    public static array $recorded = [];

    public static function record(string $event): void
    {
        self::$recorded[] = $event;
    }
}

/**
 * A complete table-oriented repository definition.
 *
 * Rows remain arrays: this class declares policy, casts, relations, and query
 * defaults without introducing Active Record state or implicit lazy loading.
 */
final class PostRepository extends TableRepository
{
    protected static ?string $connection = 'repository_example';

    /** @var list<string> */
    protected static array $creatable = [
        'author_id',
        'title',
        'status',
        'featured',
        'rating',
        'metadata',
        'published_at',
        'version',
    ];

    /** @var array<string,mixed> */
    protected static array $defaults = [
        'status' => PostStatus::Draft,
        'featured' => false,
        'rating' => '0.00',
        'metadata' => [],
        'version' => 1,
    ];

    protected static int $maxRelationDepth = 2;

    protected static int $perPage = 10;

    protected static string $primaryKey = 'post_id';

    protected static string $table = 'example_posts';

    protected static bool $timestamps = true;

    /** @var list<string> */
    protected static array $updatable = [
        'author_id',
        'title',
        'status',
        'featured',
        'rating',
        'metadata',
        'published_at',
    ];

    protected static function casts(): array
    {
        return [
            'post_id' => 'integer',
            'author_id' => 'integer',
            'status' => PostStatus::class,
            'featured' => 'boolean',
            'rating' => 'decimal:2',
            'metadata' => 'json',
            'published_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    protected static function configureRepository(QueryRepository $repository): QueryRepository
    {
        $repository
            ->enableSoftDeletes()
            ->enableOptimisticLocking()
            ->setDefaultOrder('post_id', 'desc');

        if ($repository instanceof TableQueryRepository) {
            $repository
                ->afterBulkUpdate(static function (
                    array $context,
                    TableQueryRepository $tableRepository,
                ): void {
                    unset($context, $tableRepository);
                    RepositoryEvents::record('bulk-update-finished');
                })
                ->afterCommit(static function (
                    string $operation,
                    array $context,
                    TableQueryRepository $tableRepository,
                ): void {
                    unset($context, $tableRepository);
                    RepositoryEvents::record('committed:' . $operation);
                });
        }

        return $repository;
    }

    /** @return array<string,callable(QueryBuilder):void> */
    protected static function globalScopes(): array
    {
        return [
            'not_archived' => static function (QueryBuilder $query): void {
                $query->where('status', '!=', PostStatus::Archived->value);
            },
        ];
    }

    /** @return array<string,RelationDefinition> */
    protected static function relations(): array
    {
        return [
            'author' => Relation::belongsTo(
                AuthorRepository::class,
                foreignKey: 'author_id',
            ),
            'comments' => Relation::hasMany(
                CommentRepository::class,
                foreignKey: 'post_id',
                localKey: 'post_id',
            )->select(['id', 'post_id', 'body', 'approved', 'score', 'created_at']),
            'latest_comment' => Relation::hasMany(
                CommentRepository::class,
                foreignKey: 'post_id',
                localKey: 'post_id',
            )->latestOfMany('created_at'),
            'tags' => Relation::belongsToMany(
                TagRepository::class,
                pivot: 'example_post_tag',
                foreignPivotKey: 'post_id',
                relatedPivotKey: 'tag_id',
                parentKey: 'post_id',
            )->withPivot('assigned_by', 'priority')->asPivot('assignment'),
        ];
    }
}

DB::purge();
DB::setSecurityDefaults([], false);
DB::addConnection([
    'driver' => 'sqlite',
    'database' => ':memory:',
], 'repository_example');

DB::statement('create table example_authors (
    id integer primary key autoincrement,
    name text not null
)', connection: 'repository_example');

DB::statement('create table example_posts (
    post_id integer primary key autoincrement,
    author_id integer not null,
    title text not null,
    status text not null,
    featured integer not null,
    rating text not null,
    metadata text not null,
    published_at text null,
    version integer not null,
    deleted_at text null,
    created_at text not null,
    updated_at text not null
)', connection: 'repository_example');

DB::statement('create table example_comments (
    id integer primary key autoincrement,
    post_id integer not null,
    body text not null,
    approved integer not null,
    score integer not null,
    created_at text not null
)', connection: 'repository_example');

DB::statement('create table example_tags (
    id integer primary key autoincrement,
    name text not null
)', connection: 'repository_example');

DB::statement('create table example_post_tag (
    post_id integer not null,
    tag_id integer not null,
    assigned_by integer not null,
    priority integer not null
)', connection: 'repository_example');

DB::table('example_authors', 'repository_example')->insert(['name' => 'Ada']);
DB::table('example_tags', 'repository_example')->insert(['name' => 'database']);

$post = PostRepository::create([
    'author_id' => 1,
    'title' => 'Repository policies in practice',
    'status' => PostStatus::Published,
    'featured' => true,
    'rating' => '4.875',
    'metadata' => ['audience' => 'maintainers'],
    'published_at' => '2026-08-24 09:30:00',
]);
$postId = (int) $post['post_id'];

DB::table('example_comments', 'repository_example')->insert([
    'post_id' => $postId,
    'body' => 'Explicit policies make repository code predictable.',
    'approved' => 1,
    'score' => 10,
    'created_at' => '2026-08-24 10:00:00',
]);
DB::table('example_post_tag', 'repository_example')->insert([
    'post_id' => $postId,
    'tag_id' => 1,
    'assigned_by' => 7,
    'priority' => 1,
]);

$posts = PostRepository::repositoryQuery()
    ->with('author', 'latest_comment', 'tags')
    ->withCount('comments')
    ->whereRelation('comments', 'approved', '=', 1)
    ->get();

$optimisticUpdate = PostRepository::updateByIdWithVersion(
    $postId,
    ['rating' => '5.00'],
    expectedVersion: 1,
);

PostRepository::transaction(static function (Connection $connection) use ($postId): void {
    unset($connection);

    PostRepository::repositoryQuery()
        ->where('post_id', '=', $postId)
        ->update(['title' => 'Repository policies, finalized']);
});

PostRepository::repositoryQuery()->where('post_id', '=', $postId)->delete();
$trashed = PostRepository::repositoryQuery()->onlyTrashed()->count();
PostRepository::repositoryQuery()->onlyTrashed()->where('post_id', '=', $postId)->restore();

PostRepository::create([
    'author_id' => 1,
    'title' => 'Expired draft',
    'status' => PostStatus::Draft,
]);
$pruned = PostRepository::pruner()->prune(
    static function (RepositoryQuery $query): void {
        $query->where('title', '=', 'Expired draft');
    },
    chunkSize: 100,
    force: true,
);

$page = PostRepository::repositoryQuery()->paginate(page: 1);

// query()/builder()/rawQuery() deliberately expose the raw QueryBuilder boundary.
$rawCount = PostRepository::query()->count();

$summary = [
    'created_post_id' => $postId,
    'eager_loaded_posts' => $posts->count(),
    'optimistic_update' => $optimisticUpdate,
    'page_items' => count($page->items()),
    'temporarily_trashed' => $trashed,
    'pruned_stale_rows' => $pruned,
    'raw_count' => $rawCount,
    'events' => RepositoryEvents::$recorded,
];

$encoded = json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
fwrite(STDOUT, $encoded . PHP_EOL);

DB::purge();
