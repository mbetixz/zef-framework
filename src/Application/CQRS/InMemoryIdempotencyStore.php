<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Application layer (in-process orchestration)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\CQRS;

use Zef\Framework\Validation\Identifier;

final class InMemoryIdempotencyStore implements IdempotencyStoreInterface
{
    use IdempotencyTrait;

    public function __construct(private readonly int $maxEntries = 10000)
    {
        if ($maxEntries < 1) {
            throw new \InvalidArgumentException('Idempotency store capacity must be positive.');
        }
        $this->idempotencyMaxEntries = $maxEntries;
        $this->idempotencyMaxKeyLength = 128;
    }

    #[\Override]
    public function remember(string $key, callable $producer, int $ttlSeconds = 3600): mixed
    {
        if ($key === '' || !Identifier::isValidOpaqueId($key)) {
            throw new \InvalidArgumentException('Invalid idempotency key.');
        }

        return $this->idempotencyRemember($key, $producer, $ttlSeconds);
    }
}
