<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Application layer (in-process orchestration)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Security\Distributed;

final class DefaultSecurityBoundary implements SecurityBoundaryInterface
{
    #[\Override]
    public function admit(
        AuthenticationResult $authentication,
        SecurityRequest $request,
        AuthorizationPolicyInterface $authorization,
        ReplayProtectorInterface $replayProtector,
        int $nowMs,
    ): SecurityAdmissionDecision {
        if ($authentication->status !== AuthenticationStatus::AUTHENTICATED) {
            return match ($authentication->status) {
                AuthenticationStatus::EXPIRED => new SecurityAdmissionDecision(SecurityVerdict::DENY, SecurityFailure::CREDENTIAL_EXPIRED, false),
                AuthenticationStatus::UNAVAILABLE => new SecurityAdmissionDecision(SecurityVerdict::DENY, SecurityFailure::AUTHENTICATION_UNAVAILABLE, false),
                default => new SecurityAdmissionDecision(SecurityVerdict::DENY, SecurityFailure::AUTHENTICATION_FAILED, false),
            };
        }
        $context = $authentication->context;
        if (!$context instanceof SecurityContext) {
            return new SecurityAdmissionDecision(SecurityVerdict::DENY, SecurityFailure::MALFORMED_METADATA, false);
        }
        $authorizationResult = $authorization->authorize($context, $request);
        if ($authorizationResult->verdict !== SecurityVerdict::ALLOW) {
            return new SecurityAdmissionDecision(SecurityVerdict::DENY, SecurityFailure::AUTHORIZATION_DENIED, false);
        }
        $replay = $replayProtector->check($request->replayId, $nowMs);
        if (!$replay->allows()) {
            $failure = match ($replay->decision) {
                ReplayDecision::UNAVAILABLE => SecurityFailure::REPLAY_UNAVAILABLE,
                default => SecurityFailure::REPLAY_REJECTED,
            };

            return new SecurityAdmissionDecision(SecurityVerdict::DENY, $failure, false);
        }

        return new SecurityAdmissionDecision(SecurityVerdict::ALLOW, SecurityFailure::NONE, false);
    }
}
