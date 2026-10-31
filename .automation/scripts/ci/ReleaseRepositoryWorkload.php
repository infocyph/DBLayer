<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Query\QueryBuilder;
use Infocyph\DBLayer\Query\ResultProcessor;
use Infocyph\DBLayer\Repository\RelationLoader;
use Infocyph\DBLayer\Repository\RepositoryDefinition;
use Infocyph\DBLayer\Repository\TableQueryRepository;
use Infocyph\DBLayer\Repository\TableRepository;

/** Connection-bound repository fixtures reused by one release-workload worker. */
final readonly class ReleaseRepositoryWorkload
{
    private TableQueryRepository $repository;

    private RelationLoader $relations;

    public function __construct(Connection $connection)
    {
        $this->relations = new RelationLoader($connection);
        $this->repository = new TableQueryRepository(
            $connection,
            new RepositoryDefinition(TableRepository::class, 'release_perf_items', null, 'id', 25),
            new ResultProcessor(),
        );
        $this->repository->afterBulkUpdate(static function (array $context): void {
            if (($context['affected'] ?? null) !== 1) {
                throw new RuntimeException('Repository after-hook context mismatch.');
            }
        });
        $this->repository->afterCommit(static function (string $operation, array $context): void {
            if ($operation !== 'bulk_update' || ($context['affected'] ?? null) !== 1) {
                throw new RuntimeException('Repository commit-hook context mismatch.');
            }
        });
    }

    /** @param list<array<string,mixed>> $parents */
    public function execute(array $parents, int $worker, int $sequence): void
    {
        $loaded = $this->relations->one($parents, 'id', 'release_perf_items', 'id', 'related', ['tenant_id']);
        foreach ($loaded as $parent) {
            $related = $parent['related'];
            if (!is_array($related) || ($related['id'] ?? null) !== $parent['id']) {
                throw new RuntimeException('Batched relation projection mismatch.');
            }
        }

        $affected = $this->repository->updateWhere(
            ['score' => $sequence % 100_000],
            static function (QueryBuilder $query) use ($worker): void {
                $query->where('id', '=', $worker + 1);
            },
        );
        if ($affected !== 1) {
            throw new RuntimeException('Repository mutation correctness mismatch.');
        }
    }
}
