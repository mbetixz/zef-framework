<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Application layer (in-process orchestration)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Security\Distributed;

final class StaticCredentialProvider implements CredentialProviderInterface
{
    /**
     * @var list<string> SHA-256 digests of the accepted tokens
     */
    private readonly array $tokenDigests;

    /** @param list<string> $validTokens */
    public function __construct(
        array $validTokens,
        private readonly string $principalId = 'static-principal',
        private readonly string $scope = 'api',
        private readonly int $expiresAtMs = 0,
    ) {
        if ($validTokens === []) {
            throw new \InvalidArgumentException('StaticCredentialProvider requires at least one valid token.');
        }
        // Compare fixed-length digests instead of the raw secrets:
        // in_array() on raw tokens leaks timing (length + early-exit
        // memcmp) in the authentication path.
        $this->tokenDigests = array_map(static fn (string $t): string => hash('sha256', $t), $validTokens);
    }

    #[\Override]
    public function resolve(CredentialHandle $handle, int $nowMs): AuthenticationResult
    {
        if (!in_array(hash('sha256', $handle->handleId), $this->tokenDigests, true)) {
            return new AuthenticationResult(AuthenticationStatus::FAILED);
        }
        if ($this->expiresAtMs > 0 && $nowMs > $this->expiresAtMs) {
            return new AuthenticationResult(AuthenticationStatus::EXPIRED);
        }

        return new AuthenticationResult(
            AuthenticationStatus::AUTHENTICATED,
            new SecurityContext(
                principalId: $this->principalId,
                authenticationMethod: 'static-bearer',
                authorizationContext: 'route',
                credentialScope: $this->scope,
                peerIdentity: null,
            ),
        );
    }
}
