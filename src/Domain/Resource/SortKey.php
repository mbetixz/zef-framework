<?php

declare(strict_types=1);

/*
 * ZEF Framework — Domain layer (ports, contracts, value objects)
 * Added in the v2.10.0 roadmap continuation (additive, no behavioural changes).
 */

namespace Zef\Framework\Resource;

/**
 * One resolved sort key from a SortSpec (immutable).
 */
final readonly class SortKey
{
    public function __construct(
        public string $field,
        public bool $desc,
    ) {
        if ($this->field === '') {
            throw new \InvalidArgumentException('Sort key field must not be empty.');
        }
    }
}
