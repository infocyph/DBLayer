<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Repository;

use BadMethodCallException;
use Infocyph\ArrayKit\Collection\Collection;
use Infocyph\DBLayer\Query\QueryBuilder;
use Infocyph\DBLayer\Query\Repository;

/**
 * Repository-aware fluent query wrapper.
 *
 * SQL-shaping calls are delegated to QueryBuilder while repository-aware
 * terminal reads preserve configured casts and result processing. Use raw()
 * when intentionally opting out of repository result semantics.
 */
final class RepositoryQuery
{
    public function __construct(
        private readonly Repository $repository,
        private readonly QueryBuilder $builder,
    ) {}

    /**
     * Delegate fluent query-builder operations while preserving this wrapper.
     *
     * @param array<int,mixed> $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        if (!method_exists($this->builder, $method)) {
            throw new BadMethodCallException(sprintf(
                'Method %s::%s() does not exist on the underlying query builder.',
                self::class,
                $method,
            ));
        }

        $result = $this->builder->$method(...$arguments);

        return $result === $this->builder ? $this : $result;
    }

    /**
     * Get repository-processed rows as a Collection.
     */
    public function get(): Collection
    {
        return $this->repository->processQueryRows($this->builder->get());
    }

    /**
     * Get the first repository-processed row.
     *
     * @return array<string,mixed>|null
     */
    public function first(): ?array
    {
        return $this->repository->processQueryRow($this->builder->first());
    }

    /**
     * Access the underlying repository instance.
     */
    public function repository(): Repository
    {
        return $this->repository;
    }

    /**
     * Escape hatch for intentionally raw QueryBuilder semantics.
     */
    public function raw(): QueryBuilder
    {
        return $this->builder;
    }
}
