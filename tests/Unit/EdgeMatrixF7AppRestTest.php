<?php

declare(strict_types=1);

/*
 * Edge-Case Matrix fase 7b — Application/Cache, Security, Resource (ronde 3).
 *
 * Kurikulum chunk f7-rest-a (62 escape baseline): lock-store expiry eksklusif
 * (> vs >=, < vs <=) dengan clock injectable per detik, guard argumen kunci/
 * owner/TTL (256/257, 0/1, 86400/86401), CSRF token grammar (caret/dollar
 * pada value, HMAC forged), scope-policy anonymous semantika (default deny,
 * case-insensitive, || vs &&, reason exact), replay protector (kapasitas
 * default 1024 tepat, window ±1ms pada boundary, MAX_REPLAY_ID_BYTES ±1),
 * rate limiter (maxKeys 10.000, reset boundary per detik, retryAfter floor),
 * origin normalization (trim, IPv6 bracket, port 1 & default-port strip),
 * admission controller counters (±1 dua arah + guard LogicException),
 * static credential expiry boundary (== exp tetap authenticated), boundary
 * verdict mapping + retryAllowed flag, resolver XFF (trim, ||/&&, early
 * return), BoundedTrait grammar via SecurityContext/SecurityRequest.
 *
 * Setiap test membunuh mutan spesifik dari build/infection-f7-rest-a.log.
 */

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Zef\Framework\Cache\InMemoryLockStore;
use Zef\Framework\Exception\InvalidConfigurationException;
use Zef\Framework\Resource\InMemoryAdmissionController;
use Zef\Framework\Resource\ResourceBudget;
use Zef\Framework\Security\ClientAddressResolver;
use Zef\Framework\Security\CsrfTokenManager;
use Zef\Framework\Security\Distributed\AllowScopeAuthorizationPolicy;
use Zef\Framework\Security\Distributed\AuthenticationResult;
use Zef\Framework\Security\Distributed\AuthenticationStatus;
use Zef\Framework\Security\Distributed\AuthorizationPolicyInterface;
use Zef\Framework\Security\Distributed\AuthorizationResult;
use Zef\Framework\Security\Distributed\BoundedInMemoryReplayProtector;
use Zef\Framework\Security\Distributed\CredentialHandle;
use Zef\Framework\Security\Distributed\DefaultSecurityBoundary;
use Zef\Framework\Security\Distributed\ReplayDecision;
use Zef\Framework\Security\Distributed\ReplayProtectorInterface;
use Zef\Framework\Security\Distributed\ReplayResult;
use Zef\Framework\Security\Distributed\SecurityContext;
use Zef\Framework\Security\Distributed\SecurityFailure;
use Zef\Framework\Security\Distributed\SecurityRequest;
use Zef\Framework\Security\Distributed\SecurityVerdict;
use Zef\Framework\Security\Distributed\StaticCredentialProvider;
use Zef\Framework\Security\InMemoryRateLimiter;
use Zef\Framework\Security\OriginPolicy;

/**
 * @internal
 */
final class EdgeMatrixF7AppRestTest extends TestCase
{
    public function testLockStoreLifecycleWithFakeClock(): void
    {
        $store = $this->lockStore(1000);
        self::assertTrue($store->acquire('k-1', 'alice', 60));
        self::assertFalse($store->acquire('k-1', 'bob', 60), 'held lock must reject a second owner');
        self::assertTrue($store->acquire('k-1', 'alice', 60), 'same owner re-acquire is idempotent');
        self::assertSame('alice', $store->holder('k-1'));
        self::assertFalse($store->release('k-1', 'bob'), 'non-owner cannot release');
        self::assertTrue($store->release('k-1', 'alice'));
        self::assertNull($store->holder('k-1'));
        self::assertFalse($store->release('k-1', 'alice'), 'double release fails');
        self::assertNull($store->holder('k-unknown'));
    }

    public function testLockStoreExpiryIsExclusiveGreaterThan(): void
    {
        // acquire @1000 (expiresAt 1060), lalu bob @1060 tepat: asli
        // expired (strictly >) sehingga owner baru MENANG.
        $store = $this->lockStoreAt([1000, 1060]);
        $store->acquire('k-1', 'alice', 60);
        self::assertTrue($store->acquire('k-1', 'bob', 60), 'expiresAt == now must be expired');
        self::assertSame('bob', $store->holder('k-1'));
    }

    public function testLockStoreHolderExpiryIsInclusiveLessOrEqual(): void
    {
        $store = $this->lockStoreAt([1000, 1060]);
        $store->acquire('k-1', 'alice', 60); // expiresAt = 1060
        self::assertNull($store->holder('k-1'), 'expiresAt == now must purge the lock');
    }

    public function testLockStoreRefreshRejectsExpiredLock(): void
    {
        $store = $this->lockStoreAt([1000, 1060]);
        $store->acquire('k-1', 'alice', 60); // expiresAt = 1060
        self::assertFalse($store->refresh('k-1', 'alice', 30), 'expiresAt == now cannot be refreshed');
    }

    public function testLockStoreRefreshExtendsAndNewBoundaryIsHonoured(): void
    {
        $store = $this->lockStoreAt([1000, 1059, 1089]);
        $store->acquire('k-1', 'alice', 60); // exp 1060
        self::assertTrue($store->refresh('k-1', 'alice', 30), 'one second before expiry still refreshable'); // exp baru 1089
        self::assertNull($store->holder('k-1'), 'refreshed expiry == now must purge');
    }

    public function testLockStoreArgumentGuards(): void
    {
        $store = $this->lockStore();
        $key256 = str_repeat('k', 256);
        $owner256 = str_repeat('o', 256);
        self::addToAssertionCount(1); // ttl 1 & 86400 valid
        $store->acquire($key256, $owner256, 1);
        $store->acquire('k-x', 'o-x', 86_400);

        $this->assertGuardThrows(fn (): bool => $store->acquire('', 'o', 60));
        $this->assertGuardThrows(fn (): bool => $store->acquire(str_repeat('k', 257), 'o', 60));
        $this->assertGuardThrows(fn (): bool => $store->acquire('k', '', 60));
        $this->assertGuardThrows(fn (): bool => $store->acquire('k', str_repeat('o', 257), 60));
        $this->assertGuardThrows(fn (): bool => $store->acquire('k', 'o', 0));
        $this->assertGuardThrows(fn (): bool => $store->acquire('k', 'o', 86_401));
        $this->assertGuardThrows(fn (): bool => $store->release('', 'o'));
        $this->assertGuardThrows(fn (): bool => $store->release('k', ''));
        $this->assertGuardThrows(fn (): bool => $store->refresh('', 'o', 1));
        $this->assertGuardThrows(fn (): bool => $store->refresh('k', '', 1));
        $this->assertGuardThrows(fn (): bool => $store->refresh('k', 'o', 0));
        $this->assertGuardThrows(fn (): ?string => $store->holder(''));
        $this->assertGuardThrows(fn (): ?string => $store->holder(str_repeat('k', 257)));
    }

    // --------------------------------------------- InMemoryAdmissionController

    public function testAdmissionControllerCountersAndGuards(): void
    {
        $controller = new InMemoryAdmissionController(new ResourceBudget(maxInFlight: 2, maxQueue: 2));

        try {
            $controller->complete();
            self::fail('Expected LogicException when completing without admission.');
        } catch (\LogicException) {
            self::addToAssertionCount(1);
        }

        try {
            $controller->dequeue();
            self::fail('Expected LogicException when dequeuing an empty queue.');
        } catch (\LogicException) {
            self::addToAssertionCount(1);
        }

        $a1 = $controller->admit();
        $a2 = $controller->admit();
        self::assertTrue($a1->admitted);
        self::assertTrue($a2->admitted);
        $a3 = $controller->admit();
        self::assertFalse($a3->admitted, 'third admit must hit maxInFlight=2');
        self::assertSame('in_flight_limit', $a3->reason);
        self::assertSame(2, $a3->inFlight);

        $q1 = $controller->queue();
        $q2 = $controller->queue();
        $q3 = $controller->queue();
        self::assertTrue($q1->admitted);
        self::assertTrue($q2->admitted);
        self::assertFalse($q3->admitted);
        self::assertSame('queue_limit', $q3->reason);

        $controller->dequeue();
        $controller->complete();
        $controller->complete();
        // inFlight kembali 0: admit keempat harus diterima lagi (bukan 3).
        self::assertTrue($controller->admit()->admitted, 'completed slots must be reusable');
        $snapshot = $controller->snapshot();
        self::assertSame(3, $snapshot->admitted);
        self::assertSame(2, $snapshot->rejected, 'one in-flight reject + one queue reject');
        self::assertSame(1, $snapshot->queued);
        self::assertSame(2, $snapshot->completed);
    }

    // --------------------------------------------- CsrfTokenManager

    public function testCsrfIssueAndValidate(): void
    {
        $manager = new CsrfTokenManager(str_repeat('s', 32));
        $token = $manager->issue();
        self::assertTrue($manager->isValid($token), 'issued token must validate');
        self::assertFalse($manager->isValid($token . 'x'), 'tampered token fails');
        self::assertFalse($manager->isValid(''));
        self::assertFalse($manager->isValid('no-dot-here'));
        self::assertFalse($manager->isValid('.' . hash('sha256', 'x')));
        self::assertFalse($manager->isValid('val.'));

        try {
            new CsrfTokenManager('short');
            self::fail('Expected InvalidArgumentException for short secret.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        try {
            new CsrfTokenManager(str_repeat('s', 32), 15);
            self::fail('Expected InvalidArgumentException for tokenBytes < 16.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        self::addToAssertionCount(1); // tokenBytes = 16 valid
        new CsrfTokenManager(str_repeat('s', 32), 16)->issue();
    }

    public function testCsrfForgedValueWithValidSignatureFormatIsRejected(): void
    {
        $secret = str_repeat('s', 32);
        $manager = new CsrfTokenManager($secret);
        $token = $manager->issue();
        [$value, $signature] = explode('.', $token, 2);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $signature);

        // Value dengan karakter terlarang TETAP ditolak meski signature
        // adalah HMAC sah dari value tersebut — grammar value wajib penuh.
        foreach ([$value . '!', '$' . $value, $value . "\0", ' ' . $value] as $badValue) {
            $forged = $badValue . '.' . hash_hmac('sha256', $badValue, $secret);
            self::assertFalse($manager->isValid($forged), "malformed value '{$badValue}' must be rejected");
        }
    }

    public function testCsrfSignatureGrammarIsStrictHex64(): void
    {
        $manager = new CsrfTokenManager(str_repeat('s', 32));
        $value = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        self::assertFalse($manager->isValid($value . '.' . str_repeat('g', 64)), 'non-hex signature');
        self::assertFalse($manager->isValid($value . '.' . str_repeat('a', 63)), 'too-short signature');
        self::assertFalse($manager->isValid($value . '.' . str_repeat('a', 65)), 'too-long signature');
    }

    public function testScopePolicyAnonymousIsDeniedByDefault(): void
    {
        $policy = new AllowScopeAuthorizationPolicy('api:read');
        $result = $policy->authorize($this->ctx('anonymous', 'none'), $this->request());
        self::assertSame(SecurityVerdict::DENY, $result->verdict, 'anonymous must be denied when allowAnonymous defaults to false');
        self::assertSame('scope-denied', $result->policyCode);
    }

    public function testScopePolicyAnonymousAllowIsCaseInsensitive(): void
    {
        $policy = new AllowScopeAuthorizationPolicy('api:read', true);
        $result = $policy->authorize($this->ctx('ANONYMOUS', 'none'), $this->request());
        self::assertSame(SecurityVerdict::ALLOW, $result->verdict);
        self::assertSame('anonymous-allowed', $result->policyCode);
    }

    public function testScopePolicyAllowAnonymousWinsOnlyForAnonymousPrincipal(): void
    {
        $policy = new AllowScopeAuthorizationPolicy('api:read', true);
        // Principal non-anonymous dengan allowAnonymous=true TETAP lewat
        // pemeriksaan scope (bukan otomatis diizinkan).
        $denied = $policy->authorize($this->ctx('user-1', 'other'), $this->request());
        self::assertSame(SecurityVerdict::DENY, $denied->verdict);

        $allowed = $policy->authorize($this->ctx('anonymous', 'none'), $this->request());
        self::assertSame(SecurityVerdict::ALLOW, $allowed->verdict);
        self::assertSame('anonymous-allowed', $allowed->policyCode);
    }

    public function testScopePolicyGrantsScopeAfterTrimAndEmptyRemoval(): void
    {
        $policy = new AllowScopeAuthorizationPolicy('api:read');
        self::assertSame(SecurityVerdict::ALLOW, $policy->authorize($this->ctx('user-1', ' api:read '), $this->request())->verdict);
        self::assertSame(SecurityVerdict::DENY, $policy->authorize($this->ctx('user-1', 'other,other2'), $this->request())->verdict);

        try {
            new AllowScopeAuthorizationPolicy('');
            self::fail('Expected InvalidArgumentException for empty requiredScope.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    // --------------------------------------------- BoundedInMemoryReplayProtector

    public function testReplayProtectorGuardsAndLengthBoundary(): void
    {
        $p = new BoundedInMemoryReplayProtector();
        self::assertSame(ReplayDecision::NOT_REQUIRED, $p->check(null, 1000)->decision);

        $max = SecurityRequest::MAX_REPLAY_ID_BYTES;
        self::assertSame(ReplayDecision::ACCEPT, $p->check(str_repeat('a', $max), 1000)->decision, 'exactly MAX bytes is accepted');
        self::assertSame(ReplayDecision::REJECTED, $p->check(str_repeat('b', $max + 1), 1000)->decision, 'beyond MAX bytes is rejected');
        self::assertSame(ReplayDecision::REJECTED, $p->check('', 1000)->decision);

        try {
            new BoundedInMemoryReplayProtector(capacity: 0);
            self::fail('Expected InvalidArgumentException for capacity 0.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        try {
            new BoundedInMemoryReplayProtector(windowMs: 0);
            self::fail('Expected InvalidArgumentException for windowMs 0.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        self::addToAssertionCount(1); // capacity=1 & windowMs=1 valid
        new BoundedInMemoryReplayProtector(1, 1)->check('x', 1);
    }

    public function testReplayProtectorWindowBoundaryIsInclusive(): void
    {
        $p = new BoundedInMemoryReplayProtector();
        self::assertSame(ReplayDecision::ACCEPT, $p->check('id-1', 1_000_000)->decision);
        // Pada now = t0 + windowMs (300.000): entry seenAt == cutoff -> masih duplikat.
        self::assertSame(ReplayDecision::DUPLICATE, $p->check('id-1', 1_300_000)->decision);
        // Satu ms lagi: entry di luar window -> purged -> accept lagi.
        self::assertSame(ReplayDecision::ACCEPT, $p->check('id-1', 1_300_001)->decision);
    }

    public function testReplayProtectorCapacityBoundaryIsExactly1024(): void
    {
        $p = new BoundedInMemoryReplayProtector();
        $t = 5_000_000;
        for ($i = 0; $i < 1024; ++$i) {
            $result = $p->check('cap-' . str_pad((string) $i, 6, '0', STR_PAD_LEFT), $t + $i);
            self::assertSame(ReplayDecision::ACCEPT, $result->decision, "entry {$i} must be accepted");
        }
        self::assertSame(ReplayDecision::UNAVAILABLE, $p->check('cap-overflow', $t + 1024)->decision, 'the 1025th distinct id saturates the buffer');
        self::assertSame(ReplayDecision::DUPLICATE, $p->check('cap-000000', $t + 1024)->decision, 'existing ids stay checkable');
    }

    // --------------------------------------------- InMemoryRateLimiter

    public function testRateLimiterExplicitCapacityBoundary(): void
    {
        try {
            new InMemoryRateLimiter(0);
            self::fail('Expected InvalidArgumentException for maxKeys 0.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        $limiter = new InMemoryRateLimiter(1);
        $limiter->check('k-1', 5, 60);
        $this->expectException(\RuntimeException::class);
        $limiter->check('k-2', 5, 60); // NEW key at capacity -> exhausted
    }

    public function testRateLimiterDefaultCapacityIsExactlyTenThousand(): void
    {
        $limiter = new InMemoryRateLimiter();
        for ($i = 0; $i < 10_000; ++$i) {
            $limiter->check('cap-' . str_pad((string) $i, 6, '0', STR_PAD_LEFT), 5, 60);
        }
        $this->expectException(\RuntimeException::class);
        $limiter->check('cap-010000', 5, 60); // the 10.001st key is one too many
    }

    public function testRateLimiterPurgeFreesCapacityForNewKeys(): void
    {
        $limiter = new InMemoryRateLimiter(2);
        $limiter->check('k-1', 5, 1); // reset = t0 + 1
        $limiter->check('k-2', 5, 1); // reset = t0 + 1
        $t0 = time();
        while (time() === $t0) {
            // maju satu detik: reset == now
        }
        $decision = $limiter->check('k-3', 5, 60);
        self::assertTrue($decision->allowed, 'purge loop must free capacity before the new-key guard');
    }

    public function testRateLimiterInputGuards(): void
    {
        $limiter = new InMemoryRateLimiter();

        try {
            $limiter->check('', 5, 60);
            self::fail('Expected InvalidArgumentException for empty key.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        try {
            $limiter->check('k', 0, 60);
            self::fail('Expected InvalidArgumentException for limit 0.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        try {
            $limiter->check('k', 5, 0);
            self::fail('Expected InvalidArgumentException for windowSeconds 0.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    public function testRateLimiterBudgetAndRetryAfter(): void
    {
        $limiter = new InMemoryRateLimiter();
        $d1 = $limiter->check('k', 2, 60);
        self::assertTrue($d1->allowed);
        self::assertSame(2, $d1->limit);
        self::assertSame(1, $d1->remaining);
        self::assertSame(60, $d1->retryAfter, 'fresh bucket resets one full window ahead');
        $d2 = $limiter->check('k', 2, 60);
        self::assertTrue($d2->allowed);
        self::assertSame(0, $d2->remaining);
        $d3 = $limiter->check('k', 2, 60);
        self::assertFalse($d3->allowed, 'third call within the window exceeds limit 2');
        self::assertSame(0, $d3->remaining);
    }

    public function testRateLimiterResetsExactlyWhenWindowExpires(): void
    {
        $limiter = new InMemoryRateLimiter();
        $d = $limiter->check('k', 5, 1); // reset = now + 1
        self::assertTrue($d->allowed);
        self::assertSame(1, $d->retryAfter, 'one-second window yields retryAfter 1 (not 2)');
        $t0 = time();
        while (time() === $t0) {
            // tunggu detik dinding berganti agar reset == now (<=, bukan <)
        }
        $d2 = $limiter->check('k', 5, 1);
        self::assertTrue($d2->allowed, 'bucket must be purged at reset == now (<= semantics)');
        self::assertSame(4, $d2->remaining, 'purged bucket starts fresh with full budget');
    }

    // --------------------------------------------- OriginPolicy

    public function testOriginNormalizeAcceptsAndCanonicalizes(): void
    {
        self::assertSame('https://a.example', OriginPolicy::normalizeOrigin('  HTTPS://A.example  '));
        self::assertSame('http://a.example:8080', OriginPolicy::normalizeOrigin('http://a.example:8080'));
        self::assertSame('http://a.example:1', OriginPolicy::normalizeOrigin('http://a.example:1'), 'port 1 is valid');
        self::assertSame('null', OriginPolicy::normalizeOrigin('null'));
        self::assertSame('http://[::1]', OriginPolicy::normalizeOrigin('http://[::1]'));
        self::assertSame('http://[2001:db8::1]:8443', OriginPolicy::normalizeOrigin('http://[2001:db8::1]:8443'));

        OriginPolicy::assertAllowed('https://a.example', ['https://a.example']);
        OriginPolicy::assertAllowed(null, []);
        OriginPolicy::assertAllowed('', ['https://a.example']);

        try {
            OriginPolicy::assertAllowed('https://b.example', ['https://a.example']);
            self::fail('Expected exception for a disallowed origin.');
        } catch (InvalidConfigurationException) {
            self::addToAssertionCount(1);
        }
    }

    public function testOriginNormalizeRejectsMalformedInputs(): void
    {
        $this->assertGuardThrows(fn (): string => OriginPolicy::normalizeOrigin("a\r\nb"));
        $this->assertGuardThrows(fn (): string => OriginPolicy::normalizeOrigin('https://a.example/?query=1'));
        $this->assertGuardThrows(fn (): string => OriginPolicy::normalizeOrigin('https://a.example/path'));
        $this->assertGuardThrows(fn (): string => OriginPolicy::normalizeOrigin('https://user:pass@a.example'));
        $this->assertGuardThrows(fn (): string => OriginPolicy::normalizeOrigin('ftp://a.example'));
        $this->assertGuardThrows(fn (): string => OriginPolicy::normalizeOrigin('https://a.example:0'));
        $this->assertGuardThrows(fn (): string => OriginPolicy::normalizeOrigin('https://a.example:notaport'));
        $this->assertGuardThrows(fn (): string => OriginPolicy::normalizeOrigin('http://[::1'));
    }

    public function testOriginDefaultPortIsStrippedPerScheme(): void
    {
        self::assertSame('http://a.example', OriginPolicy::normalizeOrigin('http://a.example:80'));
        self::assertSame('https://a.example', OriginPolicy::normalizeOrigin('https://a.example:443'));
        self::assertSame('http://a.example:443', OriginPolicy::normalizeOrigin('http://a.example:443'), '443 is NOT the default for http');
        self::assertSame('https://a.example:80', OriginPolicy::normalizeOrigin('https://a.example:80'), '80 is NOT the default for https');
    }

    // --------------------------------------------- StaticCredentialProvider

    public function testStaticCredentialExpiryBoundaries(): void
    {
        $provider = new StaticCredentialProvider(['tok-1'], expiresAtMs: 1000);
        $handle = new CredentialHandle('tok-1', 'api', 0);
        self::assertSame(AuthenticationStatus::AUTHENTICATED, $provider->resolve($handle, 999)->status, '1ms before expiry is valid');
        self::assertSame(AuthenticationStatus::AUTHENTICATED, $provider->resolve($handle, 1000)->status, 'nowMs == expiresAtMs is NOT yet expired');
        self::assertSame(AuthenticationStatus::EXPIRED, $provider->resolve($handle, 1001)->status, '1ms after expiry fails');
        self::assertSame(AuthenticationStatus::FAILED, $provider->resolve(new CredentialHandle('nope', 'api', 0), 1)->status);
        self::assertSame(AuthenticationStatus::AUTHENTICATED, $provider->resolve($handle, 0)->status);
    }

    public function testStaticCredentialZeroExpiryNeverExpires(): void
    {
        $provider = new StaticCredentialProvider(['tok-1']);
        self::assertSame(AuthenticationStatus::AUTHENTICATED, $provider->resolve(new CredentialHandle('tok-1', 'api', 0), 99_999_999)->status, 'expiresAtMs=0 means never expires');
        $result = $provider->resolve(new CredentialHandle('tok-1', 'api', 0), 99_999_999);
        self::assertSame('static-principal', $result->context->principalId); // @phpstan-ignore-line
        self::assertSame('api', $result->context->credentialScope); // @phpstan-ignore-line

        try {
            new StaticCredentialProvider([]);
            self::fail('Expected InvalidArgumentException for empty token list.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    public function testSecurityBoundaryHappyPathAllowsWithoutRetryFlag(): void
    {
        $boundary = new DefaultSecurityBoundary();
        $auth = new AuthenticationResult(AuthenticationStatus::AUTHENTICATED, $this->ctx('user-1', 'api'));
        $decision = $boundary->admit($auth, $this->request(), $this->allowPolicy(), $this->acceptReplay(), 1000);
        self::assertSame(SecurityVerdict::ALLOW, $decision->verdict);
        self::assertSame(SecurityFailure::NONE, $decision->failure);
        self::assertFalse($decision->retryAllowed, 'an ALLOW decision never asks for a retry');
    }

    public function testSecurityBoundaryMapsFailuresAndRetryFlags(): void
    {
        $boundary = new DefaultSecurityBoundary();
        $request = $this->request();

        $expired = $boundary->admit(new AuthenticationResult(AuthenticationStatus::EXPIRED), $request, $this->allowPolicy(), $this->acceptReplay(), 1000);
        self::assertSame(SecurityFailure::CREDENTIAL_EXPIRED, $expired->failure);
        self::assertFalse($expired->retryAllowed);

        $unavailable = $boundary->admit(new AuthenticationResult(AuthenticationStatus::UNAVAILABLE), $request, $this->allowPolicy(), $this->acceptReplay(), 1000);
        self::assertSame(SecurityFailure::AUTHENTICATION_UNAVAILABLE, $unavailable->failure);
        self::assertFalse($unavailable->retryAllowed);

        $denied = $boundary->admit(
            new AuthenticationResult(AuthenticationStatus::AUTHENTICATED, $this->ctx('user-1', 'api')),
            $request,
            $this->denyPolicy(),
            $this->acceptReplay(),
            1000,
        );
        self::assertSame(SecurityFailure::AUTHORIZATION_DENIED, $denied->failure);
        self::assertFalse($denied->retryAllowed);

        $dupReplay = new class implements ReplayProtectorInterface {
            #[\Override]
            public function check(?string $replayId, int $nowMs): ReplayResult
            {
                return new ReplayResult(ReplayDecision::DUPLICATE);
            }
        };
        $replayRejected = $boundary->admit(
            new AuthenticationResult(AuthenticationStatus::AUTHENTICATED, $this->ctx('user-1', 'api')),
            $request,
            $this->allowPolicy(),
            $dupReplay,
            1000,
        );
        self::assertSame(SecurityFailure::REPLAY_REJECTED, $replayRejected->failure);
        self::assertFalse($replayRejected->retryAllowed);

        $unauth = $boundary->admit(new AuthenticationResult(AuthenticationStatus::FAILED), $request, $this->allowPolicy(), $this->acceptReplay(), 1000);
        self::assertSame(SecurityFailure::AUTHENTICATION_FAILED, $unauth->failure);
    }

    public function testClientAddressResolverDirectNonProxiedClient(): void
    {
        // Client bukan trusted proxy: XFF harus DIABAIKAN (early return).
        $resolved = ClientAddressResolver::resolve($this->requestWith('203.0.113.9', '198.51.100.7'), ['10.0.0.1']);
        self::assertSame('203.0.113.9', $resolved, 'non-trusted remote must short-circuit before XFF');
        // REMOTE_ADDR dipangkas sebelum sanitasi.
        self::assertSame('203.0.113.9', ClientAddressResolver::resolve($this->requestWith(' 203.0.113.9 ')), 'REMOTE_ADDR must be trimmed');
        self::assertSame('0.0.0.0', ClientAddressResolver::resolve($this->requestWith(''), ['10.0.0.1']));
        self::assertSame('0.0.0.0', ClientAddressResolver::resolve($this->requestWith('not-an-ip')));
    }

    public function testClientAddressResolverWalksXffFromRightmost(): void
    {
        // 10.0.0.1 adalah trusted proxy; rantai XFF di-trim per koma dan
        // dimundurkan sampai hop pertama yang BUKAN proxy.
        $request = $this->requestWith('10.0.0.1', '203.0.113.20, 10.0.0.2 , 10.0.0.1');
        self::assertSame('203.0.113.20', ClientAddressResolver::resolve($request, ['10.0.0.1', '10.0.0.2']));
    }

    // --------------------------------------------- BoundedTrait grammar (via SecurityContext)

    public function testSecurityContextBoundedGrammar(): void
    {
        $max = SecurityContext::MAX_PRINCIPAL_BYTES;
        self::addToAssertionCount(1); // exactly MAX bytes accepted
        $this->ctx(str_repeat('p', $max), 'api');

        try {
            $this->ctx('', 'api');
            self::fail('Expected InvalidArgumentException for empty principalId.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('principalId exceeds its bound.', $e->getMessage());
        }

        try {
            $this->ctx(str_repeat('p', $max + 1), 'api');
            self::fail('Expected InvalidArgumentException for oversized principalId.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('principalId exceeds its bound.', $e->getMessage());
        }
    }

    public function testSecurityRequestBoundedGrammar(): void
    {
        $max = SecurityRequest::MAX_RESOURCE_BYTES;
        self::addToAssertionCount(1); // exactly MAX accepted
        new SecurityRequest('App\Op', str_repeat('r', $max), 'GET');

        try {
            new SecurityRequest('App\Op', str_repeat('r', $max + 1), 'GET');
            self::fail('Expected InvalidArgumentException for oversized resource.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('resource exceeds its bound.', $e->getMessage());
        }

        try {
            new SecurityRequest('App\Op', 'r', 'GET', str_repeat('x', 129));
            self::fail('Expected InvalidArgumentException for oversized replayId.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('replayId exceeds its bound.', $e->getMessage());
        }
    }
    // --------------------------------------------- InMemoryLockStore

    /**
     * Clock palsu dengan jadwal eksplisit per pembacaan (nilai terakhir diulang).
     *
     * @param list<int> $times
     */
    private function lockStoreAt(array $times): InMemoryLockStore
    {
        $i = 0;
        $clock = static function () use (&$i, $times): int {
            $t = $times[min($i, count($times) - 1)];
            ++$i;

            return $t;
        };

        return new InMemoryLockStore($clock);
    }

    private function lockStore(int $now = 1000, int $advance = 0): InMemoryLockStore
    {
        return $this->lockStoreAt([$now, $now + $advance]);
    }

    private function assertGuardThrows(callable $fn): void
    {
        try {
            $fn();
            self::fail('Expected InvalidArgumentException guard.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    // --------------------------------------------- AllowScopeAuthorizationPolicy

    private function ctx(string $principalId, string $scope): SecurityContext
    {
        return new SecurityContext(
            principalId: $principalId,
            authenticationMethod: 'bearer',
            authorizationContext: 'route',
            credentialScope: $scope,
            peerIdentity: null,
        );
    }

    private function request(): SecurityRequest
    {
        return new SecurityRequest('App\Op', 'res', 'GET');
    }

    // --------------------------------------------- DefaultSecurityBoundary

    private function allowPolicy(): AuthorizationPolicyInterface
    {
        return new class implements AuthorizationPolicyInterface {
            #[\Override]
            public function authorize(SecurityContext $context, SecurityRequest $request): AuthorizationResult
            {
                return new AuthorizationResult(SecurityVerdict::ALLOW, 'ok');
            }
        };
    }

    private function denyPolicy(): AuthorizationPolicyInterface
    {
        return new class implements AuthorizationPolicyInterface {
            #[\Override]
            public function authorize(SecurityContext $context, SecurityRequest $request): AuthorizationResult
            {
                return new AuthorizationResult(SecurityVerdict::DENY, 'no');
            }
        };
    }

    private function acceptReplay(): ReplayProtectorInterface
    {
        return new class implements ReplayProtectorInterface {
            #[\Override]
            public function check(?string $replayId, int $nowMs): ReplayResult
            {
                return new ReplayResult(ReplayDecision::ACCEPT);
            }
        };
    }

    // --------------------------------------------- ClientAddressResolver

    private function requestWith(string $remoteAddr, string $xff = ''): ServerRequestInterface
    {
        return new readonly class($remoteAddr, $xff) implements ServerRequestInterface {
            public function __construct(
                private string $remoteAddr,
                private string $xff,
            ) {}

            #[\Override] // @phpstan-ignore-line
            public function getServerParams(): array
            {
                return $this->remoteAddr === '' ? [] : ['REMOTE_ADDR' => $this->remoteAddr];
            }

            #[\Override]
            public function getHeaderLine(string $name): string
            {
                return $name === 'X-Forwarded-For' ? $this->xff : '';
            }

            #[\Override]
            public function getProtocolVersion(): string
            {
                return '1.1';
            }

            #[\Override]
            public function withProtocolVersion(string $version): static
            {
                return $this;
            }

            #[\Override] // @phpstan-ignore-line
            public function getHeaders(): array
            {
                return $this->xff === '' ? [] : ['X-Forwarded-For' => [$this->xff]];
            }

            #[\Override]
            public function hasHeader(string $name): bool
            {
                return $name === 'X-Forwarded-For' && $this->xff !== '';
            }

            #[\Override] // @phpstan-ignore-line
            public function getHeader(string $name): array
            {
                return $this->hasHeader($name) ? [$this->xff] : [];
            }

            #[\Override] // @phpstan-ignore-line
            /** @param mixed $value */
            public function withHeader(string $name, $value): static
            {
                return $this;
            }

            #[\Override] // @phpstan-ignore-line
            /** @param mixed $value */
            public function withAddedHeader(string $name, $value): static
            {
                return $this;
            }

            #[\Override]
            public function withoutHeader(string $name): static
            {
                return $this;
            }

            #[\Override]
            public function getBody(): StreamInterface
            {
                throw new \LogicException('not used');
            }

            #[\Override]
            public function withBody(StreamInterface $body): static
            {
                return $this;
            }

            #[\Override]
            public function getRequestTarget(): string
            {
                return '/';
            }

            #[\Override]
            public function withRequestTarget(string $requestTarget): static
            {
                return $this;
            }

            #[\Override]
            public function getMethod(): string
            {
                return 'GET';
            }

            #[\Override]
            public function withMethod(string $method): static
            {
                return $this;
            }

            #[\Override]
            public function getUri(): UriInterface
            {
                throw new \LogicException('not used');
            }

            #[\Override]
            public function withUri(UriInterface $uri, bool $preserveHost = false): static
            {
                return $this;
            }

            #[\Override] // @phpstan-ignore-line
            public function getCookieParams(): array
            {
                return [];
            }

            #[\Override] // @phpstan-ignore-line
            public function withCookieParams(array $cookies): static
            {
                return $this;
            }

            #[\Override] // @phpstan-ignore-line
            public function getQueryParams(): array
            {
                return [];
            }

            #[\Override] // @phpstan-ignore-line
            public function withQueryParams(array $query): static
            {
                return $this;
            }

            #[\Override] // @phpstan-ignore-line
            public function getUploadedFiles(): array
            {
                return [];
            }

            #[\Override] // @phpstan-ignore-line
            public function withUploadedFiles(array $uploadedFiles): static
            {
                return $this;
            }

            #[\Override] // @phpstan-ignore-line
            public function getParsedBody(): array|object|null
            {
                return null;
            }

            #[\Override] // @phpstan-ignore-line
            /** @param mixed $data */
            public function withParsedBody($data): static
            {
                return $this;
            }

            #[\Override] // @phpstan-ignore-line
            public function getAttributes(): array
            {
                return [];
            }

            #[\Override] // @phpstan-ignore-line
            /** @param mixed $default */
            public function getAttribute(string $name, $default = null): mixed
            {
                return $default;
            }

            #[\Override] // @phpstan-ignore-line
            /** @param mixed $value */
            public function withAttribute(string $name, $value): static
            {
                return $this;
            }

            #[\Override]
            public function withoutAttribute(string $name): static
            {
                return $this;
            }
        };
    }
}
