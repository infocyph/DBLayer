<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Pagination;

/**
 * Cursor-based pagination.
 *
 * Designed for:
 *  - Stable ordering on large tables
 *  - Infinite scroll / "Load more" UIs
 *
 * This paginator does NOT know the total or last page count.
 */
final class CursorPaginator extends AbstractPaginator
{
    /**
     * @param list<mixed> $items
     */
    public function __construct(
        array $items,
        int $perPage,
        /**
         * Cursor used to generate this page (opaque).
         */
        private readonly ?string $cursor,
        /**
         * Cursor for the next page (opaque).
         */
        private readonly ?string $nextCursor,
        /**
         * Whether there is a next page.
         */
        private readonly bool $hasMore,
        /**
         * Cursor for the previous page (opaque).
         */
        private readonly ?string $previousCursor = null,
        /**
         * Whether there is a previous page.
         */
        private readonly bool $hasPrevious = false,
    ) {
        // Page number is mostly meaningless for cursor-based pagination,
        // but we keep it as 1 for interface compatibility.
        parent::__construct($items, $perPage);
    }

    public function cursor(): ?string
    {
        return $this->cursor;
    }

    #[\Override]
    public function hasMorePages(): bool
    {
        return $this->hasMore;
    }

    public function hasPreviousPage(): bool
    {
        return $this->hasPrevious;
    }

    #[\Override]
    public function lastPage(): ?int
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function meta(): array
    {
        return [
            'cursor' => $this->cursor(),
            'next_cursor' => $this->nextCursor(),
            'previous_cursor' => $this->previousCursor(),
            'per_page' => $this->perPage(),
            'count' => $this->count(),
            'has_more' => $this->hasMorePages(),
            'has_previous' => $this->hasPreviousPage(),
        ];
    }

    public function nextCursor(): ?string
    {
        return $this->nextCursor;
    }

    public function previousCursor(): ?string
    {
        return $this->previousCursor;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        return [
            'data' => $this->items(),
            'meta' => $this->meta(),
        ];
    }

    #[\Override]
    public function total(): ?int
    {
        return null;
    }
}
