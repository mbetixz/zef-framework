<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Adapters layer (inbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Security;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zef\Framework\Http\JsonResponse;
use Zef\Framework\Security\Distributed\AuthorizationPolicyInterface;
use Zef\Framework\Security\Distributed\CredentialHandle;
use Zef\Framework\Security\Distributed\CredentialProviderInterface;
use Zef\Framework\Security\Distributed\ReplayProtectorInterface;
use Zef\Framework\Security\Distributed\SecurityBoundaryInterface;
use Zef\Framework\Security\Distributed\SecurityRequest;

final readonly class AuthenticationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private CredentialProviderInterface $credentialProvider,
        private AuthorizationPolicyInterface $authorization,
        private ReplayProtectorInterface $replayProtector,
        private SecurityBoundaryInterface $boundary,
    ) {}

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = strtoupper($request->getMethod());
        $safe = in_array($method, ['GET', 'HEAD', 'OPTIONS', 'TRACE'], true);
        $credential = $this->extractCredential($request);
        if (!$safe && !$credential instanceof CredentialHandle) {
            return $this->deny(401, 'Authentication required', $request);
        }
        $authentication = $this->credentialProvider->resolve(
            $credential ?? new CredentialHandle('anonymous', 'public', 0),
            (int) (microtime(true) * 1000),
        );
        // SecurityRequest bounds every field to MAX_OPERATION_BYTES
        // (128): an attacker-controlled URL path can exceed that, and an
        // uncaught InvalidArgumentException here would surface as a 500.
        // Truncate defensively, mirroring resourceFromRequest().
        $operationClass = substr($method . ' ' . $request->getUri()->getPath(), 0, SecurityRequest::MAX_OPERATION_BYTES);
        $securityRequest = new SecurityRequest(
            operationClass: $operationClass,
            resource: $this->resourceFromRequest($request),
            action: $method,
            // Defensive bound: an oversized X-Replay-Id previously threw
            // inside SecurityRequest (uncaught → 500 on attacker input).
            // Truncation keeps replay protection active (fail-closed).
            replayId: $this->boundedReplayId($request->getHeaderLine('X-Replay-Id')),
        );
        $admission = $this->boundary->admit(
            $authentication,
            $securityRequest,
            $this->authorization,
            $this->replayProtector,
            (int) (microtime(true) * 1000),
        );
        if (!$admission->allows()) {
            $status = $authentication->context instanceof Distributed\SecurityContext ? 403 : 401;

            return $this->deny($status, $admission->failure->value, $request);
        }
        $request = $request->withAttribute(
            'zef.security.principal',
            $authentication->context->principalId ?? 'anonymous',
        );

        return $handler->handle($request);
    }

    /** Truncates an attacker-controlled replay id to the security bound. */
    private function boundedReplayId(string $header): ?string
    {
        if ($header === '') {
            return null;
        }

        return substr($header, 0, SecurityRequest::MAX_REPLAY_ID_BYTES);
    }

    private function extractCredential(ServerRequestInterface $request): ?CredentialHandle
    {
        $authorization = $request->getHeaderLine('Authorization');
        if ($authorization === '' || preg_match('/^Bearer\s+(.+)$/i', $authorization, $m) !== 1) {
            return null;
        }
        $token = trim($m[1]);
        if ($token === '' || strlen($token) > CredentialHandle::MAX_ID_BYTES) {
            return null;
        }

        return new CredentialHandle($token, 'bearer', PHP_INT_MAX);
    }

    private function resourceFromRequest(ServerRequestInterface $request): string
    {
        $path = $request->getUri()->getPath();
        if (strlen($path) > 128) {
            $path = substr($path, 0, 128);
        }

        return $path === '' ? '/' : $path;
    }

    private function deny(int $status, string $reason, ServerRequestInterface $request): ResponseInterface
    {
        $requestId = $request->getAttribute('zef.security.context') instanceof SecurityContext
            ? $request->getAttribute('zef.security.context')->requestId
            : bin2hex(random_bytes(8));

        return JsonResponse::error($status, $reason, ['correlation_id' => $requestId]);
    }
}
