<?php

declare(strict_types=1);

/*
 * ZEF Framework — Domain layer (ports, contracts, value objects)
 * Added in the v2.8.0 roadmap continuation (additive, no behavioural changes).
 */

namespace Zef\Framework\Resource;

/**
 * Pure pagination request (offset/limit and page/per-page styles).
 *
 * Accepts already-parsed scalar query values (strings from HTTP query strings
 * are fine) and normalizes them into a safe offset/limit pair with hard caps,
 * so neither clients nor handlers can drive unbounded reads. No I/O, no HTTP
 * types — wire parsing stays in the adapters.
 */
final readonly class PageRequest
{
    public const int MAX_LIMIT = 100;

    public function __construct(
        public int $offset,
        public int $limit,
    ) {
        if ($this->offset < 0) {
            throw new \InvalidArgumentException('Page offset must be >= 0.');
        }
        if ($this->limit < 1) {
            throw new \InvalidArgumentException('Page limit must be >= 1.');
        }
        if ($this->limit > self::MAX_LIMIT) {
            throw new \InvalidArgumentException('Page limit must be <= ' . self::MAX_LIMIT . ' (hard cap).');
        }
    }

    public static function limit(int $limit, int $offset = 0): self
    {
        return new self($offset, $limit);
    }

    /**
     * 1-based page + per-page style; page 1 starts at offset 0.
     */
    public static function page(int $page, int $perPage): self
    {
        if ($page < 1) {
            throw new \InvalidArgumentException('Page number must be >= 1.');
        }

        return new self(($page - 1) * $perPage, $perPage);
    }

    /**
     * Lenient normalization from a query-array (e.g. parsed query string).
     * Invalid or out-of-range values fall back to the given defaults/caps
     * instead of throwing — pagination must never 500 a listing endpoint.
     *
     * @param array<string,mixed> $query
     */
    public static function fromQuery(
        array $query,
        int $defaultLimit = 20,
        string $offsetKey = 'offset',
        string $limitKey = 'limit',
        string $pageKey = 'page',
        string $perPageKey = 'per_page',
    ): self {
        $defaultLimit = max(1, min(self::MAX_LIMIT, $defaultLimit));
        $limit = self::scalarToInt($query[$limitKey] ?? null);
        if ($limit === null || $limit < 1) {
            $perPage = self::scalarToInt($query[$perPageKey] ?? null);
            $limit = ($perPage !== null && $perPage >= 1) ? $perPage : $defaultLimit;
        }
        $limit = min(self::MAX_LIMIT, $limit);

        $offset = self::scalarToInt($query[$offsetKey] ?? null);
        if ($offset === null || $offset < 0) {
            $page = self::scalarToInt($query[$pageKey] ?? null);
            $offset = ($page !== null && $page >= 1) ? ($page - 1) * $limit : 0;
        }

        return new self(min($offset, PHP_INT_MAX - $limit), $limit);
    }

    private static function scalarToInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\d{1,12}$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }
}
