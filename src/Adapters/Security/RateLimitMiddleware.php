<?php

declare(strict_types=1);

/*
 * ZEF Framework — Security (Adapters layer: inbound HTTP adapters)
 * Added in v2.25.0 (Rate Limiting: algorithms, tiers, standard headers).
 */

namespace Zef\Framework\Security;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zef\Framework\Http\JsonResponse;

/**
 * Tiered PSR-15 rate-limiting middleware over {@see TieredRateLimiter}.
 *
 * Composition model: this middleware is INDEPENDENT of
 * {@see SecurityRuntimeMiddleware}'s global per-IP limiter. When both are
 * wired, the global cap (outer) and the per-tier caps (inner) stack — that
 * is the intended layered shape (a global safety net plus route/tenant
 * quotas), not a double-charge of the same bucket.
 *
 * Identity resolution chain (first hit wins, source-prefixed so values from
 * different sources can never collide in one bucket):
 *  1. the `zef.auth.identity` request attribute (set by authentication
 *     middleware) — hashed;
 *  2. the API-key header — hashed;
 *  3. the resolved client IP (trusted-proxy aware) — used verbatim.
 * Values from sources 1-2 are sha256-truncated so arbitrary-length
 * credentials cannot bloat limiter storage and never leak into keys.
 *
 * Headers: IETF draft-ietf-httpapi-ratelimit-headers (`RateLimit-Limit`,
 * `RateLimit-Remaining`, `RateLimit-Reset`) on every verdict, plus the
 * legacy `X-RateLimit-*` pair for older clients, and `Retry-After` on 429.
 *
 * Failure policy: a limiter storage failure either fails CLOSED (default,
 * 503 + Retry-After: 1, mirroring SecurityRuntimeMiddleware) or fails OPEN
 * (`$failOpen`, the request proceeds WITHOUT rate-limit headers — never
 * without auth semantics).
 */
final readonly class RateLimitMiddleware implements MiddlewareInterface
{
    public const string REQUEST_ATTRIBUTE = 'zef.security.rate_limit';

    private const string IDENTITY_ATTRIBUTE = 'zef.auth.identity';

    /**
     * @param list<RateLimitRule> $rules
     * @param list<string>        $trustedProxies
     */
    public function __construct(
        private TieredRateLimiter $tiered,
        private array $rules,
        private array $trustedProxies = [],
        private bool $failOpen = false,
        private string $identityHeader = 'X-API-Key',
    ) {
        foreach ($rules as $rule) {
            if (!$rule instanceof RateLimitRule) {
                throw new \InvalidArgumentException('Rate limit middleware rules must be RateLimitRule instances.');
            }
        }
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $requestTrustedProxies = $request->getAttribute('__zef_trusted_proxies', $this->trustedProxies);
        $trustedProxies = is_array($requestTrustedProxies)
            ? array_values(array_filter($requestTrustedProxies, is_string(...)))
            : $this->trustedProxies;
        $matched = $this->matchingRules($request);
        if ($matched === []) {
            return $handler->handle($request);
        }

        $identity = $this->resolveIdentity($request, $trustedProxies);

        try {
            $verdict = $this->tiered->evaluateAll($matched, $identity);
        } catch (\Throwable) {
            if ($this->failOpen) {
                return $handler->handle($request);
            }

            return JsonResponse::error(503, 'Service Unavailable', [], ['Retry-After' => '1']);
        }

        if (!$verdict->allowed) {
            return JsonResponse::error(429, 'Too Many Requests', [], $this->headers($verdict) + ['Retry-After' => (string) $verdict->retryAfter]);
        }

        $response = $handler->handle($request->withAttribute(self::REQUEST_ATTRIBUTE, $verdict));
        foreach ($this->headers($verdict) as $headerName => $headerValue) {
            $response = $response->withHeader($headerName, $headerValue);
            if (!$response instanceof ResponseInterface) {
                throw new \LogicException('withHeader must preserve the response type.');
            }
        }

        return $response;
    }

    /**
     * @return list<RateLimitRule>
     */
    private function matchingRules(ServerRequestInterface $request): array
    {
        $path = $request->getUri()->getPath();
        $method = $request->getMethod();
        $matched = [];
        foreach ($this->rules as $rule) {
            if ($rule->matchesPath($path) && $rule->matchesMethod($method)) {
                $matched[] = $rule;
            }
        }

        return $matched;
    }

    /**
     * @param list<string> $trustedProxies
     */
    private function resolveIdentity(ServerRequestInterface $request, array $trustedProxies): string
    {
        $attribute = $request->getAttribute(self::IDENTITY_ATTRIBUTE);
        if (is_string($attribute) && $attribute !== '') {
            return 'identity:' . $this->fingerprint($attribute);
        }
        $apiKey = $request->getHeaderLine($this->identityHeader);
        if ($apiKey !== '') {
            return 'apikey:' . $this->fingerprint($apiKey);
        }

        return 'ip:' . ClientAddressResolver::resolve($request, $trustedProxies);
    }

    private function fingerprint(string $value): string
    {
        // Full 64-hex sha256: deterministic, bounded, and credentials never
        // appear verbatim in limiter storage keys.
        return hash('sha256', $value);
    }

    /**
     * @return array<string, string>
     */
    private function headers(RateLimitVerdict $verdict): array
    {
        // Remaining is validated >= 0 at RateLimitRuleOutcome construction,
        // so no clamping is needed here.
        return [
            'RateLimit-Limit' => (string) $verdict->limit,
            'RateLimit-Remaining' => (string) $verdict->remaining,
            'RateLimit-Reset' => (string) $verdict->resetAfter,
            'X-RateLimit-Limit' => (string) $verdict->limit,
            'X-RateLimit-Remaining' => (string) $verdict->remaining,
        ];
    }
}
