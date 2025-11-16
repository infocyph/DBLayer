<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Pagination;

use ArrayIterator;
use Traversable;

/**
 * Common paginator plumbing.
 *
 * Handles items, per-page, page index, counting and iteration.
 */
abstract class AbstractPaginator implements PaginatorInterface
{
    /**
     * Current page items.
     *
     * @var list<mixed>
     */
    protected array $items;

    /**
     * Items per page (>=1).
     */
    protected int $perPage;

    /**
     * Current page (1-based).
     */
    protected int $currentPage;

    /**
     * @param list<mixed> $items
     */
    public function __construct(array $items, int $perPage, int $currentPage = 1)
    {
        $this->items       = $items;
        $this->perPage     = max(1, $perPage);
        $this->currentPage = max(1, $currentPage);
    }

    /**
     * @return list<mixed>
     */
    public function items(): array
    {
        return $this->items;
    }

    public function perPage(): int
    {
        return $this->perPage;
    }

    public function currentPage(): int
    {
        return $this->currentPage;
    }

    public function count(): int
    {
        return \count($this->items);
    }

    public function firstItem(): ?int
    {
        if ($this->items === []) {
            return null;
        }

        return (($this->currentPage - 1) * $this->perPage) + 1;
    }

    public function lastItem(): ?int
    {
        if ($this->items === []) {
            return null;
        }

        $first = $this->firstItem();

        return $first === null ? null : $first + $this->count() - 1;
    }

    /**
     * @return Traversable<int,mixed>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    /**
     * Concrete paginators must implement these.
     */
    abstract public function hasMorePages(): bool;

    abstract public function total(): ?int;

    abstract public function lastPage(): ?int;

    /**
     * @return array<string,mixed>
     */
    abstract public function toArray(): array;

    /**
     * @return array<string,mixed>
     */
    abstract public function meta(): array;
}
