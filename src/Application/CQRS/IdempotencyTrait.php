<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Application layer (in-process orchestration)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\CQRS;

/**
 * Shared idempotency logic for CQRS and Job stores.
 * Max key length is parameterised (CQRS: 128, Job: 191).
 */
trait IdempotencyTrait
{
    /**
     * @var array<string,array{expiresAt:int,result:mixed}>
     */
    private array $idempotencyEntries = [];
    private int $idempotencyMaxEntries;
    private int $idempotencyMaxKeyLength;

    private function idempotencyRemember(
        string $key,
        callable $producer,
        int $ttlSeconds,
    ): mixed {
        if ($ttlSeconds < 1) {
            throw new \InvalidArgumentException('Idempotency TTL must be positive.');
        }
        if ($key === '' || strlen($key) > $this->idempotencyMaxKeyLength) {
            throw new \InvalidArgumentException('Invalid idempotency key.');
        }
        $this->idempotencyPurgeExpired();
        if (isset($this->idempotencyEntries[$key])) {
            return $this->idempotencyEntries[$key]['result'];
        }
        $result = $producer();
        $this->idempotencyPurgeExpired();
        if (count($this->idempotencyEntries) >= $this->idempotencyMaxEntries) {
            $oldest = array_key_first($this->idempotencyEntries);
            if ($oldest !== null) {
                unset($this->idempotencyEntries[$oldest]);
            }
        }
        $this->idempotencyEntries[$key] = [
            'expiresAt' => time() + $ttlSeconds,
            'result' => $result,
        ];

        return $result;
    }

    private function idempotencyPurgeExpired(): void
    {
        $now = time();
        foreach ($this->idempotencyEntries as $key => $entry) {
            if ($entry['expiresAt'] <= $now) {
                unset($this->idempotencyEntries[$key]);
            }
        }
    }
}
