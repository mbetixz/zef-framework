<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Application layer (in-process orchestration)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Security\Distributed;

final class AllowScopeAuthorizationPolicy implements AuthorizationPolicyInterface
{
    public function __construct(
        private readonly string $requiredScope,
        private readonly bool $allowAnonymous = false,
    ) {
        if ($requiredScope === '') {
            throw new \InvalidArgumentException('requiredScope must not be empty.');
        }
    }

    #[\Override]
    public function authorize(SecurityContext $context, SecurityRequest $request): AuthorizationResult
    {
        $principal = strtolower($context->principalId);
        if ($principal === 'anonymous' && $this->allowAnonymous) {
            return new AuthorizationResult(SecurityVerdict::ALLOW, 'anonymous-allowed');
        }
        $granted = array_filter(
            array_map(trim(...), explode(',', $context->credentialScope)),
            static fn (string $s): bool => $s !== '',
        );
        if (in_array($this->requiredScope, $granted, true)) {
            return new AuthorizationResult(SecurityVerdict::ALLOW, 'scope-granted');
        }

        return new AuthorizationResult(SecurityVerdict::DENY, 'scope-denied');
    }
}
