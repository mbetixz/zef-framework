<?php

declare(strict_types=1);

/*
 * ZEF Framework — Mutation deep-dive #4 (v2.14.0): request-ID validation,
 * rate-limit/origin/CSRF decision matrix for SecurityRuntimeMiddleware, the
 * second-worst escaping file in the measured baseline.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zef\Framework\Http\Response;
use Zef\Framework\Http\ServerRequest;
use Zef\Framework\Http\Uri;
use Zef\Framework\Security\RateLimitDecision;
use Zef\Framework\Security\RateLimiterInterface;
use Zef\Framework\Security\SecurityContext;
use Zef\Framework\Security\SecurityPolicy;
use Zef\Framework\Security\SecurityRuntimeMiddleware;

/**
 * @internal
 */
final class MutationDeepSecurityTest extends TestCase
{
    public function testRequestIdIsValidatedOrRegenerated(): void
    {
        $middleware = $this->middleware();
        $response = $middleware->process($this->withHeader($this->request(), 'X-Request-ID', 'req-123'), $this->handler());
        self::assertSame('req-123', $response->getHeaderLine('X-Request-ID'), 'valid id is preserved verbatim');

        foreach (['', str_repeat('x', 129), 'bad$id', 'spa ce'] as $badId) {
            $response = $middleware->process($this->withHeader($this->request(), 'X-Request-ID', $badId), $this->handler());
            $regenerated = $response->getHeaderLine('X-Request-ID');
            self::assertSame(32, strlen($regenerated), "id '{$badId}' is regenerated as 16 random bytes");
            self::assertSame(1, preg_match('/^[0-9a-f]{32}$/', $regenerated));
        }

        // The regenerated id also lands in the security context attribute.
        $catcher = new MutationDeepContextCatcher();
        $middleware->process($this->withHeader($this->request(), 'X-Request-ID', 'bad$id'), $catcher);
        self::assertInstanceOf(SecurityContext::class, $catcher->ctx);
        self::assertNotSame('bad$id', $catcher->ctx->requestId);
    }

    public function testRateLimitHeadersOnSuccessAnd429OnExhaustion(): void
    {
        $policy = new SecurityPolicy(rateLimitEnabled: true, rateLimitMaxRequests: 60, csrfEnabled: false);
        $limiter = new MutationDeepFakeLimiter([
            new RateLimitDecision(true, 60, 59, 30),
            new RateLimitDecision(false, 60, 0, 30),
        ]);
        $middleware = new SecurityRuntimeMiddleware($policy, $limiter);

        $response = $middleware->process($this->request(), $this->handler());
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('60', $response->getHeaderLine('X-RateLimit-Limit'));
        self::assertSame('59', $response->getHeaderLine('X-RateLimit-Remaining'));

        $blocked = $middleware->process($this->request(), $this->handler());
        self::assertSame(429, $blocked->getStatusCode());
        self::assertSame('30', $blocked->getHeaderLine('Retry-After'));
        self::assertSame('60', $blocked->getHeaderLine('X-RateLimit-Limit'));
        self::assertSame('0', $blocked->getHeaderLine('X-RateLimit-Remaining'));
        self::assertNotSame('', $blocked->getHeaderLine('X-Request-ID'));
    }

    public function testRateLimiterCrashFailsClosedWith503(): void
    {
        $policy = new SecurityPolicy(rateLimitEnabled: true, csrfEnabled: false);
        $limiter = new MutationDeepFakeLimiter([], throws: 'store unreachable');
        $middleware = new SecurityRuntimeMiddleware($policy, $limiter);

        $response = $middleware->process($this->request(), $this->handler());
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('1', $response->getHeaderLine('Retry-After'));
    }

    public function testOriginPolicyAllowsListedAndBlocksOthers(): void
    {
        $policy = new SecurityPolicy(
            csrfEnabled: false,
            allowedOrigins: ['https://good.example'],
            originEnabled: true,
        );
        $middleware = new SecurityRuntimeMiddleware($policy, new MutationDeepFakeLimiter([]));

        $allowed = $middleware->process(
            $this->withHeader($this->request(), 'Origin', 'https://good.example'),
            $this->handler(),
        );
        self::assertSame(200, $allowed->getStatusCode());

        $denied = $middleware->process(
            $this->withHeader($this->request(), 'Origin', 'https://evil.example'),
            $this->handler(),
        );
        self::assertSame(403, $denied->getStatusCode());
    }

    public function testCsrfIssuesCookieOnSafeAndEnforcesOnUnsafeRequests(): void
    {
        $policy = new SecurityPolicy(csrfEnabled: true, csrfSecret: str_repeat('s', 32));
        $middleware = new SecurityRuntimeMiddleware($policy, new MutationDeepFakeLimiter([]));

        $safe = $middleware->process($this->request('/form', 'GET'), $this->handler());
        self::assertSame(200, $safe->getStatusCode());
        $cookie = $safe->getHeaderLine('Set-Cookie');
        self::assertStringContainsString('ZEF-XSRF-TOKEN=', $cookie);
        self::assertStringContainsString('Path=/', $cookie);
        self::assertStringContainsString('SameSite=Strict', $cookie);
        self::assertStringContainsString('Secure', $cookie);
        self::assertStringContainsString('HttpOnly', $cookie);

        $token = explode(';', $cookie)[0];
        $token = substr($token, strlen('ZEF-XSRF-TOKEN='));

        $mismatch = $middleware->process(
            $this->withHeader(
                $this->withHeader($this->request('/form', 'POST'), 'Cookie', 'ZEF-XSRF-TOKEN=' . $token),
                'X-CSRF-Token',
                'tampered-token',
            ),
            $this->handler(),
        );
        self::assertSame(403, $mismatch->getStatusCode());

        $missing = $middleware->process($this->request('/form', 'POST'), $this->handler());
        self::assertSame(403, $missing->getStatusCode());

        $valid = $middleware->process(
            $this->withHeader(
                $this->withHeader($this->request('/form', 'POST'), 'Cookie', 'other=1; ZEF-XSRF-TOKEN=' . $token . ' ; extra=2'),
                'X-CSRF-Token',
                $token,
            ),
            $this->handler(),
        );
        self::assertSame(200, $valid->getStatusCode(), 'cookie parsing trims spaces and finds the token');
    }

    public function testInvalidCsrfCookieOnSafeMethodIsReissued(): void
    {
        $policy = new SecurityPolicy(csrfEnabled: true, csrfSecret: str_repeat('s', 32));
        $middleware = new SecurityRuntimeMiddleware($policy, new MutationDeepFakeLimiter([]));

        $response = $middleware->process(
            $this->withHeader($this->request('/form', 'GET'), 'Cookie', 'ZEF-XSRF-TOKEN=tampered-value'),
            $this->handler(),
        );
        $cookie = $response->getHeaderLine('Set-Cookie');
        self::assertStringContainsString('ZEF-XSRF-TOKEN=', $cookie);
        self::assertStringNotContainsString('ZEF-XSRF-TOKEN=tampered-value', $cookie, 'stale cookie is replaced');
    }

    // -----------------------------------------------------------------

    private function request(string $path = '/', string $method = 'GET'): ServerRequest
    {
        return new ServerRequest($method, new Uri('http://localhost' . $path, ['localhost']));
    }

    private function withHeader(ServerRequest $request, string $name, string $value): ServerRequest
    {
        $with = $request->withHeader($name, $value);
        \assert($with instanceof ServerRequest);

        return $with;
    }

    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };
    }

    private function middleware(): SecurityRuntimeMiddleware
    {
        return new SecurityRuntimeMiddleware(
            new SecurityPolicy(csrfEnabled: false),
            new MutationDeepFakeLimiter([]),
        );
    }
}

/**
 * @internal
 */
final class MutationDeepContextCatcher implements RequestHandlerInterface
{
    public ?SecurityContext $ctx = null;

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $ctx = $request->getAttribute('zef.security.context');
        $this->ctx = $ctx instanceof SecurityContext ? $ctx : null;

        return new Response(200);
    }
}

/**
 * @internal
 */
final class MutationDeepFakeLimiter implements RateLimiterInterface
{
    private int $index = 0;

    /** @param list<RateLimitDecision> $decisions */
    public function __construct(
        private readonly array $decisions,
        private readonly ?string $throws = null,
    ) {}

    #[\Override]
    public function check(string $key, int $limit, int $windowSeconds): RateLimitDecision
    {
        if ($this->throws !== null) {
            throw new \RuntimeException($this->throws);
        }
        $decision = $this->decisions[$this->index] ?? null;
        if ($decision === null) {
            throw new \RuntimeException('MutationDeepFakeLimiter exhausted');
        }
        ++$this->index;

        return $decision;
    }
}
