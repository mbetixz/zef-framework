<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Application layer (in-process orchestration)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Security\Distributed;

/**
 * Shared assertBounded() helper extracted from CredentialHandle,
 * SecurityContext, SecurityRequest.
 */
trait BoundedTrait
{
    private static function assertBounded(string $value, int $maxBytes, string $field): void
    {
        if ($value === '' || strlen($value) > $maxBytes) {
            throw new \InvalidArgumentException($field . ' exceeds its bound.');
        }
    }
}
