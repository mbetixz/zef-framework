<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Application layer (in-process orchestration)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Security\Distributed;

final class BoundedInMemoryReplayProtector implements ReplayProtectorInterface
{
    /**
     * @var array<string,int>
     */
    private array $seen = [];

    public function __construct(
        private readonly int $capacity = 1024,
        private readonly int $windowMs = 300_000,
    ) {
        if ($capacity < 1) {
            throw new \InvalidArgumentException('capacity must be >= 1.');
        }
        if ($windowMs < 1) {
            throw new \InvalidArgumentException('windowMs must be >= 1.');
        }
    }

    #[\Override]
    public function check(?string $replayId, int $nowMs): ReplayResult
    {
        if ($replayId === null) {
            return new ReplayResult(ReplayDecision::NOT_REQUIRED);
        }
        if ($replayId === '' || strlen($replayId) > SecurityRequest::MAX_REPLAY_ID_BYTES) {
            return new ReplayResult(ReplayDecision::REJECTED);
        }
        $cutoff = $nowMs - $this->windowMs;
        foreach ($this->seen as $id => $seenAt) {
            if ($seenAt < $cutoff) {
                unset($this->seen[$id]);
            }
        }
        if (isset($this->seen[$replayId])) {
            return new ReplayResult(ReplayDecision::DUPLICATE);
        }
        if (count($this->seen) >= $this->capacity) {
            return new ReplayResult(ReplayDecision::UNAVAILABLE);
        }
        $this->seen[$replayId] = $nowMs;

        return new ReplayResult(ReplayDecision::ACCEPT);
    }
}
