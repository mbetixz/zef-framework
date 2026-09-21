<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Demo application
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Middleware;

use Zef\Framework\Config\ConfigProviderInterface;
use Zef\Framework\Foundation\Env;
use Zef\Framework\Http\JsonResponse;
use Zef\Framework\Http\Response;
use Zef\Framework\Security\InMemoryRateLimiter;
use Zef\Framework\Security\OriginPolicy;
use Zef\Framework\Security\SecurityPolicy;
use Zef\Framework\Security\SecurityRuntimeMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    /** @param array{hsts?:bool,csp?:bool,contentSecurityPolicy?:string,permissionsPolicy?:bool} $policy */
    public function __construct(private readonly array $policy = []) {}

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('X-Permitted-Cross-Domain-Policies', 'none')
            ->withHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->withHeader('Cross-Origin-Resource-Policy', 'same-origin');
        if (($this->policy['permissionsPolicy'] ?? true) === true) {
            $response = $response->withHeader(
                'Permissions-Policy',
                'camera=(), microphone=(), geolocation=(), payment=()',
            );
        }
        if (($this->policy['csp'] ?? false) === true) {
            $response = $response->withHeader(
                'Content-Security-Policy',
                (string) ($this->policy['contentSecurityPolicy'] ?? "default-src 'self'; frame-ancestors 'none'; base-uri 'self'"),
            );
        }
        if (
            ($this->policy['hsts'] ?? false) === true
            && strtolower($request->getUri()->getScheme()) === 'https'
        ) {
            $response = $response->withHeader(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains',
            );
        }
        return $response;
    }
}
