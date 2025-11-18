<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Pagination;

use Countable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * Base paginator contract.
 *
 * Kept intentionally small and framework-agnostic so it can be
 * used from HTTP, CLI, queues, etc. without coupling.
 */
interface PaginatorInterface extends Countable, IteratorAggregate, JsonSerializable
{
    /**
     * Current page index (1-based).
     *
     * For cursor pagination this is mostly a compatibility number
     * and may always be 1.
     */
    public function currentPage(): int;

    /**
     * Index (1-based) of the first item in the current page, or null for empty.
     */
    public function firstItem(): ?int;

    /**
     * Iterate over items (IteratorAggregate).
     *
     * @return Traversable<int,mixed>
     */
    public function getIterator(): Traversable;

    /**
     * Whether there are more items after this page.
     */
    public function hasMorePages(): bool;
    /**
     * Get the current page's items.
     *
     * @return list<mixed>
     */
    public function items(): array;

    /**
     * Index (1-based) of the last item in the current page, or null for empty.
     */
    public function lastItem(): ?int;

    /**
     * Last page index, if known.
     *
     * - Length-aware paginator: integer last page
     * - Simple/cursor paginator: null (unknown)
     */
    public function lastPage(): ?int;

    /**
     * Pagination metadata only (no items).
     *
     * @return array<string,mixed>
     */
    public function meta(): array;

    /**
     * Items per page.
     */
    public function perPage(): int;

    /**
     * Array representation, typically for JSON resources.
     *
     * Recommended shape:
     *  [
     *      'data' => [...items...],
     *      'meta' => [...pagination meta...],
     *  ]
     *
     * @return array<string,mixed>
     */
    public function toArray(): array;

    /**
     * Total number of items across all pages, if known.
     *
     * - Length-aware paginator: integer total
     * - Simple/cursor paginator: null (unknown)
     */
    public function total(): ?int;
}
