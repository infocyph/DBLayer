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
     * Current page (1-based).
     */
    protected int $currentPage;

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
     * @param list<mixed> $items
     */
    public function __construct(array $items, int $perPage, int $currentPage = 1)
    {
        $this->items       = $items;
        $this->perPage     = max(1, $perPage);
        $this->currentPage = max(1, $currentPage);
    }

    /**
     * Concrete paginators must implement these.
     */
    #[\Override]
    abstract public function hasMorePages(): bool;

    #[\Override]
    abstract public function lastPage(): ?int;

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    abstract public function meta(): array;

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    abstract public function toArray(): array;

    #[\Override]
    abstract public function total(): ?int;

    #[\Override]
    public function count(): int
    {
        return \count($this->items);
    }

    #[\Override]
    public function currentPage(): int
    {
        return $this->currentPage;
    }

    #[\Override]
    public function firstItem(): ?int
    {
        if ($this->items === []) {
            return null;
        }

        return (($this->currentPage - 1) * $this->perPage) + 1;
    }

    /**
     * @return Traversable<int, mixed>
     */
    #[\Override]
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    /**
     * @return list<mixed>
     */
    #[\Override]
    public function items(): array
    {
        return $this->items;
    }

    #[\Override]
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    #[\Override]
    public function lastItem(): ?int
    {
        if ($this->items === []) {
            return null;
        }

        $first = $this->firstItem();

        return $first === null ? null : $first + $this->count() - 1;
    }

    #[\Override]
    public function perPage(): int
    {
        return $this->perPage;
    }
}
