<?php

declare(strict_types=1);

/*
 * ZEF Framework — Domain layer (ports, contracts, value objects)
 * Added in the v2.8.0 roadmap continuation (additive, no behavioural changes).
 */

namespace Zef\Framework\Resource;

/**
 * Immutable result slice of a paginated read.
 *
 * `total` is optional (cursor-style sources often cannot count cheaply);
 * pass null to omit total-based navigation helpers.
 *
 * @template T of mixed
 */
final readonly class PageSlice
{
    /**
     * @param list<mixed> $items
     */
    public function __construct(
        public array $items,
        public int $offset,
        public int $limit,
        public ?int $total = null,
    ) {
        if ($this->offset < 0) {
            throw new \InvalidArgumentException('Slice offset must be >= 0.');
        }
        if ($this->limit < 1) {
            throw new \InvalidArgumentException('Slice limit must be >= 1.');
        }
        if ($this->total !== null && $this->total < 0) {
            throw new \InvalidArgumentException('Slice total must be >= 0.');
        }
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function hasItems(): bool
    {
        return $this->items !== [];
    }

    /** A next page exists when this slice was full and returned items. */
    public function hasNext(): bool
    {
        if ($this->items === []) {
            return false;
        }

        return $this->total !== null ? $this->offset + $this->limit < $this->total : true;
    }

    public function hasPrevious(): bool
    {
        return $this->offset > 0;
    }

    public function nextOffset(): ?int
    {
        return $this->hasNext() ? $this->offset + $this->limit : null;
    }

    public function previousOffset(): ?int
    {
        $offset = $this->offset - $this->limit;

        return $this->hasPrevious() ? max(0, $offset) : null;
    }

    /** Stable metadata payload, suitable for embedding in API responses. */
    public function meta(): array
    {
        $meta = [
            'offset' => $this->offset,
            'limit' => $this->limit,
            'count' => count($this->items),
        ];
        if ($this->total !== null) {
            $meta['total'] = $this->total;
        }
        $next = $this->nextOffset();
        $prev = $this->previousOffset();
        if ($next !== null) {
            $meta['next_offset'] = $next;
        }
        if ($prev !== null) {
            $meta['previous_offset'] = $prev;
        }

        return $meta;
    }
}
