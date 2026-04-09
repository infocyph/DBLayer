<?php

declare(strict_types=1);

namespace Infocyph\DBLayer\Pagination;

/**
 * Pagination with a known total count and page count.
 *
 * Suitable for:
 *  - classic OFFSET/LIMIT queries with COUNT(*)
 *  - admin listings, reporting UIs
 */
final class LengthAwarePaginator extends AbstractPaginator
{
    /**
     * Total matching items.
     */
    private readonly int $total;

    /**
     * @param list<mixed> $items
     */
    public function __construct(array $items, int $total, int $perPage, int $currentPage = 1)
    {
        parent::__construct($items, $perPage, $currentPage);

        $this->total = max(0, $total);
    }

    #[\Override]
    public function hasMorePages(): bool
    {
        return $this->currentPage < $this->lastPage();
    }

    #[\Override]
    public function lastPage(): int
    {
        if ($this->total === 0) {
            return 1;
        }

        return (int) \ceil($this->total / $this->perPage);
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
            'total'        => $this->total(),
            'last_page'    => $this->lastPage(),
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
    public function total(): int
    {
        return $this->total;
    }
}
