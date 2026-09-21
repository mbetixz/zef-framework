<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Security\Distributed;

final readonly class SecurityContext
{
    use BoundedTrait;

    public const int MAX_PRINCIPAL_BYTES = 128;
    public const int MAX_AUTH_METHOD_BYTES = 64;
    public const int MAX_AUTHZ_CONTEXT_BYTES = 256;
    public const int MAX_CREDENTIAL_SCOPE_BYTES = 128;
    public const int MAX_PEER_IDENTITY_BYTES = 128;

    public function __construct(
        public string $principalId,
        public string $authenticationMethod,
        public string $authorizationContext,
        public string $credentialScope,
        public ?string $peerIdentity,
    ) {
        self::assertBounded($principalId, self::MAX_PRINCIPAL_BYTES, 'principalId');
        self::assertBounded($authenticationMethod, self::MAX_AUTH_METHOD_BYTES, 'authenticationMethod');
        self::assertBounded($authorizationContext, self::MAX_AUTHZ_CONTEXT_BYTES, 'authorizationContext');
        self::assertBounded($credentialScope, self::MAX_CREDENTIAL_SCOPE_BYTES, 'credentialScope');
        if ($peerIdentity !== null) {
            self::assertBounded($peerIdentity, self::MAX_PEER_IDENTITY_BYTES, 'peerIdentity');
        }
    }
}
