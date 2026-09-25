<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.30.0 — Domain layer (ports, contracts, value objects)
 * Ecosystem Ports: immutable object metadata returned by the storage port.
 */

namespace Zef\Framework\Storage;

/**
 * Immutable metadata snapshot for one stored object.
 */
final readonly class ObjectStat
{
    public function __construct(
        public string $key,
        public int $sizeBytes,
        public int $lastModifiedUnixNano,
    ) {
        if ($key === '' || strlen($key) > 1024) {
            throw new \InvalidArgumentException('Object stat key must be 1..1024 bytes.');
        }
        if ($sizeBytes < 0) {
            throw new \InvalidArgumentException('Object size cannot be negative.');
        }
        if ($lastModifiedUnixNano < 0) {
            throw new \InvalidArgumentException('Object last-modified cannot be negative.');
        }
    }
}
