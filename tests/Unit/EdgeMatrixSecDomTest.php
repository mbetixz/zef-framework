<?php

declare(strict_types=1);

/*
 * ZEF Framework — Edge-Case Matrix tier 1 (v2.14.2), security half: RFC
 * vectors, ±1 boundaries on every security guard, the CSRF environment
 * matrix, and the sensitive-attribute firewall. Evidence: escapes-dom-sec.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Security\Base32;
use Zef\Framework\Security\Distributed\CredentialHandle;
use Zef\Framework\Security\Distributed\SecurityRequest;
use Zef\Framework\Security\SecurityPolicy;
use Zef\Framework\Security\Totp;

/**
 * @internal
 */
final class EdgeMatrixSecDomTest extends TestCase
{
    // --------------------------------------------------------------- #
    // SecurityPolicy — constructor guards at exact boundaries.         #
    // --------------------------------------------------------------- #

    public function testTokenBytesAndRateLimitFloors(): void
    {
        try {
            new SecurityPolicy(csrfTokenBytes: 15);
            self::fail('csrfTokenBytes below 16 must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('csrfTokenBytes must be >= 16.', $e->getMessage());
        }
        self::assertSame(16, new SecurityPolicy(csrfTokenBytes: 16)->csrfTokenBytes);

        foreach ([
            ['rateLimitMaxRequests', 'rateLimitMaxRequests must be >= 1.', static fn (): SecurityPolicy => new SecurityPolicy(rateLimitMaxRequests: 0, csrfEnabled: false)],
            ['rateLimitWindowSeconds', 'rateLimitWindowSeconds must be >= 1.', static fn (): SecurityPolicy => new SecurityPolicy(rateLimitWindowSeconds: 0, csrfEnabled: false)],
            ['rateLimitMaxKeys', 'rateLimitMaxKeys must be >= 1.', static fn (): SecurityPolicy => new SecurityPolicy(rateLimitMaxKeys: 0, csrfEnabled: false)],
        ] as [$field, $message, $build]) {
            try {
                $build();
                self::fail("{$field} below its floor must be rejected.");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testCsrfSecretLengthAndTokenNames(): void
    {
        try {
            new SecurityPolicy(csrfSecret: str_repeat('s', 31));
            self::fail('short CSRF secret must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('CSRF secret must be at least 32 bytes', $e->getMessage());
        }
        new SecurityPolicy(csrfSecret: str_repeat('s', 32));
        foreach ([
            ['csrfCookieName', 'bad cookie', 'Invalid CSRF cookie name.', static fn (): SecurityPolicy => new SecurityPolicy(csrfEnabled: false, csrfCookieName: 'bad cookie')],
            ['csrfHeaderName', 'bad(header', 'Invalid CSRF header name.', static fn (): SecurityPolicy => new SecurityPolicy(csrfEnabled: false, csrfHeaderName: 'bad(header')],
        ] as [$field, $value, $message, $build]) {
            try {
                $build();
                self::fail("{$field} charset must be enforced.");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
        new SecurityPolicy(csrfEnabled: false, csrfCookieName: "a!#\$%&'*+.^_`|~-Z09");
    }

    public function testSameSiteNoneRequiresSecureCookie(): void
    {
        foreach (['Strict', 'Lax'] as $sameSite) {
            new SecurityPolicy(csrfEnabled: false, csrfSecureCookie: false, csrfSameSite: $sameSite);
        }

        try {
            new SecurityPolicy(csrfEnabled: false, csrfSecureCookie: false, csrfSameSite: 'None');
            self::fail('SameSite=None without Secure must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('SameSite=None requires Secure cookies.', $e->getMessage());
        }

        try {
            new SecurityPolicy(csrfEnabled: false, csrfSameSite: 'Permissive');
            self::fail('unknown SameSite must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Invalid CSRF SameSite policy.', $e->getMessage());
        }
    }

    public function testOriginPolicyRequiresAtLeastOneOrigin(): void
    {
        try {
            new SecurityPolicy(originEnabled: true);
            self::fail('origin policy without origins must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Origin policy enabled without allowed origins.', $e->getMessage());
        }
        $policy = new SecurityPolicy(allowedOrigins: ['https://a.example', 'https://a.example'], originEnabled: true);
        self::assertCount(1, $policy->allowedOrigins, 'origins must be normalized and deduplicated');
    }

    public function testFromEnvironmentDisablesCsrfWithoutSecretAndWarns(): void
    {
        $this->withEnv([], static function (): void {
            $logger = new CollectingLogger();
            $policy = SecurityPolicy::fromEnvironment($logger);
            self::assertFalse($policy->csrfEnabled, 'CSRF must stay off without a secret');
            self::assertStringContainsString('CSRF protection disabled', (string) $logger->lastWarning());
            self::assertSame(32, $policy->csrfTokenBytes);
            self::assertFalse($policy->rateLimitEnabled);
            self::assertSame(100, $policy->rateLimitMaxRequests);
            self::assertSame(60, $policy->rateLimitWindowSeconds);
            self::assertSame(10000, $policy->rateLimitMaxKeys);
        });
    }

    public function testFromEnvironmentCsrfOnRequiresSecret(): void
    {
        $this->withEnv(['ZEF_SECURITY_CSRF' => '1'], static function (): void {
            try {
                SecurityPolicy::fromEnvironment(new CollectingLogger());
                self::fail('CSRF=1 without a secret must be refused.');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('ZEF_SECURITY_CSRF=1 requires ZEF_SECURITY_CSRF_SECRET (>= 32 bytes).', $e->getMessage());
            }
        });
    }

    public function testFromEnvironmentCsrfOnWithSecretIsSilentAndEnabled(): void
    {
        $this->withEnv([
            'ZEF_SECURITY_CSRF' => '1',
            'ZEF_SECURITY_CSRF_SECRET' => str_repeat('s', 32),
        ], static function (): void {
            $logger = new CollectingLogger();
            $policy = SecurityPolicy::fromEnvironment($logger);
            self::assertTrue($policy->csrfEnabled);
            self::assertSame([], $logger->warnings, 'a properly configured CSRF must not warn');
        });
    }

    public function testFromEnvironmentCsrfExplicitOffIsSilent(): void
    {
        $this->withEnv(['ZEF_SECURITY_CSRF' => '0'], static function (): void {
            $logger = new CollectingLogger();
            $policy = SecurityPolicy::fromEnvironment($logger);
            self::assertFalse($policy->csrfEnabled);
            self::assertSame([], $logger->warnings, 'explicitly disabled CSRF must not warn');
        });
    }

    public function testFromEnvironmentCsrfEmptyValueBehavesLikeUnset(): void
    {
        $this->withEnv(['ZEF_SECURITY_CSRF' => ''], static function (): void {
            $logger = new CollectingLogger();
            $policy = SecurityPolicy::fromEnvironment($logger);
            self::assertFalse($policy->csrfEnabled, 'empty env falls back to the default path');
            self::assertStringContainsString('CSRF protection disabled', (string) $logger->lastWarning());
        });
    }

    public function testFromEnvironmentNumericEnvEdgeValues(): void
    {
        $this->withEnv(['ZEF_SECURITY_RATE_LIMIT_MAX' => '50'], static function (): void {
            self::assertSame(50, SecurityPolicy::fromEnvironment(new CollectingLogger())->rateLimitMaxRequests, 'plain digits pass through');
        });
        $this->withEnv(['ZEF_SECURITY_RATE_LIMIT_MAX' => '0'], static function (): void {
            self::assertSame(1, SecurityPolicy::fromEnvironment(new CollectingLogger())->rateLimitMaxRequests, "'0' clamps to the floor of 1");
        });
        $this->withEnv(['ZEF_SECURITY_RATE_LIMIT_MAX' => 'abc'], static function (): void {
            self::assertSame(100, SecurityPolicy::fromEnvironment(new CollectingLogger())->rateLimitMaxRequests, 'non-numeric falls back to default');
        });
        $this->withEnv(['ZEF_SECURITY_RATE_LIMIT_MAX' => ' 50 '], static function (): void {
            self::assertSame(50, SecurityPolicy::fromEnvironment(new CollectingLogger())->rateLimitMaxRequests, 'value is trimmed before the ctype digit check');
        });
        $this->withEnv(['ZEF_SECURITY_RATE_LIMIT_MAX' => '-5'], static function (): void {
            self::assertSame(100, SecurityPolicy::fromEnvironment(new CollectingLogger())->rateLimitMaxRequests, 'negative values fall back to default');
        });
        $this->withEnv(['ZEF_SECURITY_CSRF_TOKEN_BYTES' => '8'], static function (): void {
            self::assertSame(16, SecurityPolicy::fromEnvironment(new CollectingLogger())->csrfTokenBytes, 'token bytes clamp up to 16');
        });
        $this->withEnv(['ZEF_SECURITY_CSRF_TOKEN_BYTES' => '64'], static function (): void {
            self::assertSame(64, SecurityPolicy::fromEnvironment(new CollectingLogger())->csrfTokenBytes);
        });
        $this->withEnv(['ZEF_SECURITY_RATE_LIMIT_WINDOW' => '120'], static function (): void {
            self::assertSame(120, SecurityPolicy::fromEnvironment(new CollectingLogger())->rateLimitWindowSeconds);
        });
        $this->withEnv(['ZEF_SECURITY_RATE_LIMIT_MAX_KEYS' => '0'], static function (): void {
            self::assertSame(1, SecurityPolicy::fromEnvironment(new CollectingLogger())->rateLimitMaxKeys);
        });
    }

    public function testFromEnvironmentOriginPolicyAndNameValidation(): void
    {
        $this->withEnv(['ZEF_SECURITY_ORIGIN_POLICY' => '1'], static function (): void {
            try {
                SecurityPolicy::fromEnvironment(new CollectingLogger());
                self::fail('origin policy without origins must fail at construction.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('Origin policy enabled without allowed origins.', $e->getMessage());
            }
        });
        $this->withEnv(['ZEF_SECURITY_CSRF_HEADER' => 'bad header'], static function (): void {
            try {
                SecurityPolicy::fromEnvironment(new CollectingLogger());
                self::fail('invalid header name must fail at construction.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('Invalid CSRF header name.', $e->getMessage());
            }
        });
    }

    // --------------------------------------------------------------- #
    // Totp — RFC vectors, constructor floors, window boundaries.       #
    // --------------------------------------------------------------- #

    public function testConstructorFloorsAndAlgorithmWhitelist(): void
    {
        foreach ([
            ['period' => 0], ['period' => 86401], ['digits' => 5], ['digits' => 9],
        ] as $bad) {
            try {
                new Totp(...$bad);
                self::fail('out-of-range constructor argument must be rejected.');
            } catch (\InvalidArgumentException) {
                // expected
            }
        }
        self::assertSame(1, new Totp(period: 1)->period);
        self::assertSame(86400, new Totp(period: 86400)->period);
        self::assertSame(6, new Totp(digits: 6)->digits);
        self::assertSame(8, new Totp(digits: 8)->digits);
        self::assertSame('sha256', new Totp(algorithm: 'sha256')->algorithm);

        try {
            new Totp(algorithm: 'md5');
            self::fail('md5 must not be accepted.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString("Unsupported TOTP algorithm 'md5'.", $e->getMessage());
        }

        try {
            new Totp(algorithm: 'SHA1');
            self::fail('algorithm matching must be case-sensitive.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString("Unsupported TOTP algorithm 'SHA1'.", $e->getMessage());
        }
    }

    public function testHotpRfc4226AppendixDVectors(): void
    {
        $secret = '12345678901234567890';
        $expected = ['755224', '287082', '359152', '969429', '338314', '254676', '287922', '162583', '399871', '520489'];
        foreach ($expected as $counter => $code) {
            self::assertSame($code, Totp::hotp($secret, $counter), "RFC 4226 vector counter={$counter}");
        }
    }

    public function testRfc6238AppendixBAcrossAlgorithms(): void
    {
        // RFC 6238 Appendix B seeds one secret per algorithm, sized to the
        // hash input block: 20 bytes for SHA1, 32 for SHA256, 64 for SHA512.
        $seeds = [
            'sha1' => '12345678901234567890',
            'sha256' => '12345678901234567890123456789012',
            'sha512' => '1234567890123456789012345678901234567890123456789012345678901234',
        ];
        $vectors = [
            [59, '94287082', '46119246', '90693936'],
            [1111111109, '07081804', '68084774', '25091201'],
            [1234567890, '89005924', '91819424', '93441116'],
        ];
        foreach ($vectors as [$time, $sha1, $sha256, $sha512]) {
            self::assertSame($sha1, Totp::hotp($seeds['sha1'], intdiv($time, 30), 8, 'sha1'), "sha1 T={$time}");
            self::assertSame($sha256, Totp::hotp($seeds['sha256'], intdiv($time, 30), 8, 'sha256'), "sha256 T={$time}");
            self::assertSame($sha512, Totp::hotp($seeds['sha512'], intdiv($time, 30), 8, 'sha512'), "sha512 T={$time}");
        }
    }

    public function testAtSplitsPeriodsAtExactBoundaries(): void
    {
        $totp = new Totp();
        $secret = '12345678901234567890';
        self::assertSame('755224', $totp->at($secret, 29), 'T=29 still belongs to counter 0');
        self::assertSame('287082', $totp->at($secret, 30), 'T=30 is exactly counter 1');
        self::assertSame('287082', $totp->at($secret, 59));
        self::assertSame('359152', $totp->at($secret, 60), 'T=60 is exactly counter 2');
    }

    public function testVerifyWindowIsSymmetricAndExclusiveBeyondIt(): void
    {
        $totp = new Totp();
        $secret = '12345678901234567890';
        $codeAt = static fn (int $t): string => $totp->at($secret, $t);
        self::assertTrue($totp->verify($secret, $codeAt(1000), 1000), 'exact code matches');
        self::assertTrue($totp->verify($secret, $codeAt(970), 1000), 'one period before is inside the window');
        self::assertTrue($totp->verify($secret, $codeAt(1030), 1000), 'one period after is inside the window');
        self::assertFalse($totp->verify($secret, $codeAt(1060), 1000), 'two periods after is outside');
        self::assertFalse($totp->verify($secret, $codeAt(940), 1000), 'two periods before is outside');
        self::assertFalse($totp->verify($secret, $codeAt(970), 1000, 0), 'window 0 accepts only the exact code');
        self::assertTrue($totp->verify($secret, $codeAt(1000), 1000, 0));
    }

    public function testVerifyRejectsMalformedCodesBeforeComparison(): void
    {
        $totp = new Totp();
        $secret = '12345678901234567890';
        $code = $totp->at($secret, 1000);
        foreach (['0' . $code, $code . '0', substr($code, 0, 5), 'a' . substr($code, 1), $code . "\n", ''] as $malformed) {
            self::assertFalse($totp->verify($secret, $malformed, 1000), "malformed code '{$malformed}' must fail the format gate");
        }
    }

    public function testShortSecretsAreRejectedOnEveryEntry(): void
    {
        $totp = new Totp();
        $short = str_repeat('x', 7);
        foreach ([
            'at' => static fn (): string => $totp->at($short, 0),
            'verify' => static fn (): bool => $totp->verify($short, '123456', 0),
            'hotp' => static fn (): string => Totp::hotp($short, 0),
        ] as $method => $call) {
            try {
                $call();
                self::fail("{$method} must reject secrets shorter than 8 bytes.");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('TOTP secret must be at least 8 bytes.', $e->getMessage());
            }
        }
    }

    // --------------------------------------------------------------- #
    // Base32 — RFC 4648 vectors, canonical padding, charset.           #
    // --------------------------------------------------------------- #

    public function testEncodeMatchesRfc4648VectorsUnpadded(): void
    {
        foreach ([
            '' => '',
            'f' => 'MY',
            'fo' => 'MZXQ',
            'foo' => 'MZXW6',
            'foob' => 'MZXW6YQ',
            'fooba' => 'MZXW6YTB',
            'foobar' => 'MZXW6YTBOI',
        ] as $raw => $encoded) {
            self::assertSame($encoded, Base32::encode($raw), "encode '{$raw}'");
        }
    }

    public function testDecodeAcceptsPaddingWhitespaceAndLowercase(): void
    {
        foreach ([
            'MY' => 'f',
            'MY======' => 'f',
            'my' => 'f',
            'M Y' => 'f',
            'MZXW6' => 'foo',
            'MZXW6YQ' => 'foob',
            'MZXW6YTB' => 'fooba',
            'MZXW6YTBOI' => 'foobar',
        ] as $encoded => $raw) {
            self::assertSame($raw, Base32::decode($encoded), "decode '{$encoded}'");
        }
    }

    public function testDecodeRejectsForeignCharactersAndNonCanonicalPadding(): void
    {
        foreach (['M1', '8', 'M0', 'MY!'] as $bad) {
            try {
                Base32::decode($bad);
                self::fail("charset violation '{$bad}' must be rejected.");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('Invalid base32 input.', $e->getMessage());
            }
        }

        // 'MZXW6Y' carries six leftover bits of 11000 — not the canonical zeros.
        try {
            Base32::decode('MZXW6Y');
            self::fail('non-canonical trailing bits must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Invalid base32 padding bits.', $e->getMessage());
        }
    }

    public function testRoundTripPreservesBinaryPayloads(): void
    {
        foreach (["\x00", "\xff\x10\xaf", "\x00\x00\x01", random_bytes(20), random_bytes(35)] as $raw) {
            self::assertSame($raw, Base32::decode(Base32::encode($raw)));
        }
        self::assertSame('', Base32::decode(''));
    }

    // --------------------------------------------------------------- #
    // CredentialHandle / SecurityRequest — the bounded-value firewall. #
    // --------------------------------------------------------------- #

    public function testCredentialHandleBounds(): void
    {
        new CredentialHandle(str_repeat('h', 128), str_repeat('s', 128), 0);
        foreach ([
            [['', 'scope', 0], 'handleId exceeds its bound.'],
            [[str_repeat('h', 129), 'scope', 0], 'handleId exceeds its bound.'],
            [['h', str_repeat('s', 129), 0], 'scope exceeds its bound.'],
            [['h', 'scope', -1], 'expiresAtMs must be >= 0.'],
        ] as [$args, $message]) {
            try {
                new CredentialHandle(...$args);
                self::fail('bound violation must be rejected.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testSecurityRequestFieldBounds(): void
    {
        new SecurityRequest(str_repeat('o', 128), str_repeat('r', 128), str_repeat('a', 64));
        foreach ([
            [[str_repeat('o', 129), 'r', 'a'], 'operationClass exceeds its bound.'],
            [['o', str_repeat('r', 129), 'a'], 'resource exceeds its bound.'],
            [['o', 'r', str_repeat('a', 65)], 'action exceeds its bound.'],
            [['o', 'r', 'a', str_repeat('p', 129)], 'replayId exceeds its bound.'],
        ] as [$args, $message]) {
            try {
                new SecurityRequest(...$args);
                self::fail('field bound violation must be rejected.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testSecurityRequestAttributeMatrix(): void
    {
        self::assertSame(16, count($this->request(array_fill_keys(array_map(static fn (int $i): string => 'k' . $i, range(1, 16)), 'v'))->attributes));

        foreach ([
            [array_fill_keys(range(1, 17), 'v'), 'Too many security attributes.'],
            [['' => 'v'], 'Invalid security attribute key.'],
            [[str_repeat('k', 65) => 'v'], 'Invalid security attribute key.'],
            [['x-token' => 'v'], 'Sensitive credential material is not permitted in generic security attributes.'],
            [['X-Proxy-Authorization' => 'v'], 'Sensitive credential material is not permitted in generic security attributes.'],
            [['my_secret' => 'v'], 'Sensitive credential material is not permitted in generic security attributes.'],
            [['PRIVATE-KEY' => 'v'], 'Sensitive credential material is not permitted in generic security attributes.'],
            [['my-token-x' => 'v'], 'Sensitive credential material is not permitted in generic security attributes.'],
            [['obj' => new \stdClass()], 'Security attributes must be scalar or null.'],
            [['arr' => [1]], 'Security attributes must be scalar or null.'],
            [['big' => str_repeat('v', 257)], 'Security attribute value exceeds its bound.'],
        ] as [$attributes, $message]) {
            try {
                // @phpstan-ignore argument.type (the offending shapes ARE the scenario)
                $this->request($attributes);
                self::fail('attribute firewall violation must be rejected.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }

        // Per-attribute limits hold; the aggregate byte budget is 4096.
        $sixteen = static fn (int $valueLen): array => array_fill_keys(
            array_map(static fn (int $i): string => 'k' . $i, range(1, 16)),
            str_repeat('v', $valueLen),
        );
        $this->request($sixteen(242)); // 16 x (2 + 242) = 3904 bytes: fine

        try {
            $this->request($sixteen(300)); // every value individually above 256
            self::fail('per-attribute value bound must still apply.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Security attribute value exceeds its bound.', $e->getMessage());
        }
    }

    public function testSecurityRequestAggregateByteBudgetIsEnforced(): void
    {
        // 16 attributes x (1-byte key from 'a'..'p' + 255-byte value) = 4096 exactly.
        $payload = static fn (int $valueLen): array => array_fill_keys(
            range('a', 'p'),
            str_repeat('v', $valueLen),
        );
        new SecurityRequest('op', 'res', 'act', null, $payload(255));

        try {
            new SecurityRequest('op', 'res', 'act', null, $payload(256));
            self::fail('aggregate attribute budget above 4096 bytes must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Security attribute bytes exceed their bound.', $e->getMessage());
        }
    }

    public function testSecurityRequestAcceptsScalarsNullAndEmptyAttributes(): void
    {
        $this->request(['flag' => true, 'ratio' => 1.5, 'count' => 7, 'note' => null, 'empty' => '']);
        self::expectNotToPerformAssertions();
    }

    // --------------------------------------------------------------- #
    // SecurityPolicy::fromEnvironment — the CSRF env matrix.           #
    // --------------------------------------------------------------- #

    /**
     * @param array<string, false|string> $vars
     */
    private function withEnv(array $vars, \Closure $probe): void
    {
        foreach ($vars as $name => $value) {
            if ($value === false) {
                \putenv($name);
            } else {
                \putenv($name . '=' . $value);
            }
        }

        try {
            $probe();
        } finally {
            foreach (array_keys($vars) as $name) {
                \putenv($name);
            }
        }
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function request(array $attributes): SecurityRequest
    {
        return new SecurityRequest('op', 'res', 'act', null, $attributes);
    }
}
