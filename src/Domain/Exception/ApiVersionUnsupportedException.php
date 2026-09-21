<?php

declare(strict_types=1);

/*
 * ZEF Framework — Domain layer (exception taxonomy)
 * Added in the v2.10.0 roadmap continuation (additive, no behavioural changes).
 */

namespace Zef\Framework\Exception;

/**
 * Raised when an API version request cannot be honoured.
 * Callers typically map this to 400/406-level responses.
 */
final class ApiVersionUnsupportedException extends \RuntimeException
{
    /**
     * @param list<string> $supported
     */
    public function __construct(
        public readonly ?string $requested,
        public readonly array $supported,
        string $reason,
    ) {
        parent::__construct($reason);
    }
}
