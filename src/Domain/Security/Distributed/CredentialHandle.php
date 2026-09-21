<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Security\Distributed;

final readonly class CredentialHandle
{
    use BoundedTrait;

    public const int MAX_ID_BYTES = 128;
    public const int MAX_SCOPE_BYTES = 128;

    public function __construct(
        public string $handleId,
        public string $scope,
        public int $expiresAtMs,
    ) {
        self::assertBounded($handleId, self::MAX_ID_BYTES, 'handleId');
        self::assertBounded($scope, self::MAX_SCOPE_BYTES, 'scope');
        if ($expiresAtMs < 0) {
            throw new \InvalidArgumentException('expiresAtMs must be >= 0.');
        }
    }
}
