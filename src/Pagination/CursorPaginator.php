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
     * Cursor used to generate this page (opaque).
     */
    private ?string $cursor;

    /**
     * Whether there is a next page.
     */
    private bool $hasMore;

    /**
     * Cursor for the next page (opaque).
     */
    private ?string $nextCursor;

    /**
     * @param list<mixed> $items
     */
    public function __construct(
      array $items,
      int $perPage,
      ?string $cursor,
      ?string $nextCursor,
      bool $hasMore
    ) {
        // Page number is mostly meaningless for cursor-based pagination,
        // but we keep it as 1 for interface compatibility.
        parent::__construct($items, $perPage, 1);

        $this->cursor     = $cursor;
        $this->nextCursor = $nextCursor;
        $this->hasMore    = $hasMore;
    }

    public function cursor(): ?string
    {
        return $this->cursor;
    }

    public function hasMorePages(): bool
    {
        return $this->hasMore;
    }

    public function lastPage(): ?int
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return [
          'cursor'      => $this->cursor(),
          'next_cursor' => $this->nextCursor(),
          'per_page'    => $this->perPage(),
          'count'       => $this->count(),
          'has_more'    => $this->hasMorePages(),
        ];
    }

    public function nextCursor(): ?string
    {
        return $this->nextCursor;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
          'data' => $this->items(),
          'meta' => $this->meta(),
        ];
    }

    public function total(): ?int
    {
        return null;
    }
}
