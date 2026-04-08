<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Pagination;

/**
 * Lightweight pagination without a known total.
 *
 * Suitable for:
 *  - "Show more" style UIs
 *  - Large datasets where COUNT(*) is too expensive
 */
final class SimplePaginator extends AbstractPaginator
{
    private bool $hasMore;

    /**
     * @param list<mixed> $items
     */
    public function __construct(
        array $items,
        int $perPage,
        int $currentPage = 1,
        bool $hasMore = false,
    ) {
        parent::__construct($items, $perPage, $currentPage);

        $this->hasMore = $hasMore;
    }

    #[\Override]
    public function hasMorePages(): bool
    {
        return $this->hasMore;
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
            'current_page' => $this->currentPage(),
            'per_page'     => $this->perPage(),
            'total'        => null,
            'last_page'    => null,
            'from'         => $this->firstItem(),
            'to'           => $this->lastItem(),
            'count'        => $this->count(),
            'has_more'     => $this->hasMorePages(),
        ];
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
