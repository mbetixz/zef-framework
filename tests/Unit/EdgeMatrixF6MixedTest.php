<?php

declare(strict_types=1);

/*
 * Edge-Case Matrix fase 6 — zona campuran (ronde 3): Config (rescfg),
 * Security residual, Container/Autowiring residual (core-a).
 *
 * Kurikulum 133 escape baseline: ModuleDefinition (trim nama, guard
 * konjungtif registry key, dedup dependensi non-kontigu, koalesensi
 * dependencies/requires, Throw_ fromArray), Governance (anchor nama
 * validator, kode 0), SecurityPolicy (batas default exact, trim env,
 * error_log teramati, SameSite/origin), Base32 (vektor RFC 4648 penuh,
 * padding-bit kanonik), Totp (vektor RFC 4226 + counter >= 2^32),
 * Distributed VO (bounded trait, guard konjungtif), NamespaceRadixTree
 * (mid-edge traversal, stats exact, ksort, fromArray cast), AutowireMetadata
 * (argument plan dep/literal), ServiceDefinition (fromArray koalesensi).
 */

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Zef\Framework\Autowiring\AutowireMetadata;
use Zef\Framework\Autowiring\AutowireResult;
use Zef\Framework\Autowiring\Inject;
use Zef\Framework\Autowiring\Target;
use Zef\Framework\Autowiring\Value;
use Zef\Framework\Config\ConfigurationGovernance;
use Zef\Framework\Config\ConfigurationSnapshot;
use Zef\Framework\Config\ModuleDefinition;
use Zef\Framework\Container\NamespaceRadixTree;
use Zef\Framework\Container\ServiceDefinition;
use Zef\Framework\Container\ServiceLifetime;
use Zef\Framework\Router\RouteDefinition;
use Zef\Framework\Security\Base32;
use Zef\Framework\Security\Distributed\AuthenticationResult;
use Zef\Framework\Security\Distributed\AuthenticationStatus;
use Zef\Framework\Security\Distributed\AuthorizationResult;
use Zef\Framework\Security\Distributed\SecurityAdmissionDecision;
use Zef\Framework\Security\Distributed\SecurityContext;
use Zef\Framework\Security\Distributed\SecurityFailure;
use Zef\Framework\Security\Distributed\SecurityRequest;
use Zef\Framework\Security\Distributed\SecurityVerdict;
use Zef\Framework\Security\SecurityPolicy;
use Zef\Framework\Security\Totp;

enum F6FixtureStatus
{
    case Active;
}

/**
 * @internal
 */
final class EdgeMatrixF6MixedTest extends TestCase
{
    public function testModuleDefinitionTrimsNameAndNormalizesDeps(): void
    {
        $m = new ModuleDefinition('  billing  ', [], [], [], [], ['Api', 'api', 'Billing']);
        self::assertSame('  billing  ', $m->name, 'Promoted property menyalin nilai asli; trim hanya untuk validasi.');
        self::assertSame(['api', 'billing'], $m->dependencies);
    }

    public function testModuleDefinitionServiceRegistryGuards(): void
    {
        $svc = $this->svc('svc.a');
        $m = new ModuleDefinition('core', ['svc.a' => $svc]);
        self::assertSame(['svc.a' => $svc], $m->services);

        // Registry key int dengan instance valid: guard real menolak, mutant
        // konjungsi yang di-or/and salah akan lolos.
        try {
            new ModuleDefinition('core', [5 => $this->svc('svc.a')]);
            self::fail('Registry key int harus ditolak.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        try {
            new ModuleDefinition('core', ['svc.a' => 'bukan-definition']);
            self::fail('Nilai non-ServiceDefinition harus ditolak.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        try {
            new ModuleDefinition('core', ['svc.a' => $this->svc('other')]);
            self::fail('ID definition yang tak cocok harus ditolak.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString("Service definition ID 'other'", $e->getMessage());
            self::assertStringContainsString("'svc.a'", $e->getMessage());
        }
    }

    public function testModuleDefinitionAliasesDependenciesRoutes(): void
    {
        $r1 = RouteDefinition::fromArray(['path' => '/a', 'handler' => 'h']);
        $r2 = RouteDefinition::fromArray(['path' => '/b', 'handler' => 'h']);
        $m = new ModuleDefinition('core', [], ['alias.a' => 'svc.a'], [3 => $r1, 7 => $r2], [], ['Api']);
        self::assertSame(['alias.a' => 'svc.a'], $m->aliases);
        self::assertSame([$r1, $r2], $m->routes); // array_values: list ketat
        self::assertSame(['api'], $m->dependencies);

        try {
            new ModuleDefinition('core', [], [], ['bukan-route']);
            self::fail('Route non-instance harus ditolak.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        try {
            new ModuleDefinition('core', [], [], [], [], ['bad name!']);
            self::fail('Dependensi invalid harus ditolak.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    public function testModuleDefinitionFromArrayCoalesceAndGuards(): void
    {
        // dependencies menang atas requires (urutan ?? kiri-dulu).
        $m = ModuleDefinition::fromArray('core', ['dependencies' => ['Alpha'], 'requires' => ['Beta']]);
        self::assertSame(['alpha'], $m->dependencies);
        $onlyReq = ModuleDefinition::fromArray('core', ['requires' => ['Beta']]);
        self::assertSame(['beta'], $onlyReq->dependencies);
        self::assertSame([], $onlyReq->services);
        $ext = ModuleDefinition::fromArray('core', ['custom-key' => 'v']);
        self::assertSame(['services' => [], 'aliases' => [], 'routes' => [], 'dependencies' => [], 'custom-key' => 'v'], $ext->toArray());
        $bad = [
            [['services' => 'bukan-array']],
            [['aliases' => [5 => 'target']]],
            [['aliases' => ['a' => 42]]],
            [['dependencies' => [42]]],
            [['services' => ['svc' => $this->svc('other')]]],
            [['services' => ['ok' => $this->svc('ok'), 'bad' => 42]]],
        ];
        foreach ($bad as $i => [$config]) {
            try {
                ModuleDefinition::fromArray('core', $config);
                self::fail("Config invalid #{$i} harus ditolak.");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        // Definition array tanpa factory -> InvalidFactoryException (Runtime).
        try {
            ModuleDefinition::fromArray('core', ['services' => ['x' => ['id' => 'x']]]);
            self::fail('Definition tanpa factory harus InvalidFactoryException.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('callable factory required', $e->getMessage());
        }

        // ServiceDefinition instance diterima + lanjut ke entri berikut (continue):
        // entri array tanpa factory di entri KEDUA membuktikan loop tidak break.
        try {
            ModuleDefinition::fromArray('core', ['services' => ['ok' => $this->svc('ok'), 'arr' => []]]);
            self::fail('Entri kedua tanpa factory harus tetap dievaluasi (continue).');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString("Factory for 'arr'", $e->getMessage());
        }
    }

    // ------------------------------------ ConfigurationGovernance + Snapshot

    public function testConfigurationGovernanceValidatorNameAnchored(): void
    {
        $g = new ConfigurationGovernance();
        $g->addValidator('valid_name-1.x', static fn (): null => null);
        foreach (['1abc', 'a!', ' has-space', ''] as $bad) {
            try {
                $g2 = new ConfigurationGovernance();
                $g2->addValidator($bad, static fn (): null => null);
                self::fail("Nama validator '{$bad}' harus ditolak.");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testConfigurationGovernancePublishRunsValidatorsAndCodesZero(): void
    {
        $g = new ConfigurationGovernance();
        self::assertNull($g->current());
        $g->addValidator('range', static function (array $v): void {
            if (($v['n'] ?? 0) < 1) {
                throw new \DomainException('n terlalu kecil');
            }
        });

        try {
            $g->publish(['n' => 0], 2);
            self::fail('Validator gagal harus melempar InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Configuration validation failed: range', $e->getMessage());
            self::assertSame(0, $e->getCode());
            self::assertInstanceOf(\DomainException::class, $e->getPrevious());
        }
        $snap = $g->publish(['n' => 5], 3);
        self::assertSame(['n' => 5], $snap->values);
        self::assertSame(3, $snap->version);
        self::assertSame($snap, $g->current());

        try {
            $g->addValidator('late', static fn (): null => null);
            self::fail('Validator pasca-publikasi harus ditolak.');
        } catch (\LogicException) {
            self::addToAssertionCount(1);
        }
    }

    public function testConfigurationSnapshotVersionDefaultIs1(): void
    {
        self::assertSame(1, new ConfigurationSnapshot(['a' => 1])->version);
        self::assertSame(2, new ConfigurationSnapshot(['a' => 1], 2)->version);

        try {
            new ConfigurationSnapshot(['a' => 1], 0);
            self::fail('Versi 0 harus ditolak.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    // ------------------------------------------------- SecurityPolicy (17)

    public function testSecurityPolicyDefaultsExact(): void
    {
        $p = new SecurityPolicy();
        self::assertFalse($p->rateLimitEnabled);
        self::assertSame(100, $p->rateLimitMaxRequests);
        self::assertSame(60, $p->rateLimitWindowSeconds);
        self::assertSame(10000, $p->rateLimitMaxKeys);
        self::assertTrue($p->csrfEnabled);
        self::assertSame('', $p->csrfSecret);
        self::assertSame('ZEF-XSRF-TOKEN', $p->csrfCookieName);
        self::assertSame('X-CSRF-Token', $p->csrfHeaderName);
        self::assertTrue($p->csrfSecureCookie);
        self::assertTrue($p->csrfHttpOnlyCookie);
        self::assertSame('Strict', $p->csrfSameSite);
        self::assertSame([], $p->allowedOrigins);
        self::assertFalse($p->originEnabled);
        self::assertSame(32, $p->csrfTokenBytes);
    }

    public function testSecurityPolicyCtorGuards(): void
    {
        new SecurityPolicy(csrfSecret: str_repeat('s', 32), csrfTokenBytes: 16);
        new SecurityPolicy(rateLimitMaxRequests: 1, rateLimitWindowSeconds: 1, rateLimitMaxKeys: 1);
        $bad = [
            ['csrfTokenBytes 15', ['csrfTokenBytes' => 15]],
            ['maxRequests 0', ['rateLimitMaxRequests' => 0]],
            ['window 0', ['rateLimitWindowSeconds' => 0]],
            ['maxKeys 0', ['rateLimitMaxKeys' => 0]],
            ['secret 31', ['csrfSecret' => str_repeat('s', 31)]],
            ['cookie spasi', ['csrfCookieName' => 'bad name']],
            ['header spasi', ['csrfHeaderName' => 'bad header!']],
            ['samesite bogus', ['csrfSameSite' => 'Bogus']],
            ['samesite none tanpa secure', ['csrfSameSite' => 'None', 'csrfSecureCookie' => false]],
            ['origin tanpa origins', ['originEnabled' => true]],
        ];
        foreach ($bad as [$label, $args]) {
            try {
                // @phpstan-ignore-next-line (argumen invalid disengaja)
                new SecurityPolicy(...$args);
                self::fail("{$label} harus ditolak.");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        // Secret pendek boleh saat CSRF dimatikan.
        new SecurityPolicy(csrfEnabled: false, csrfSecret: 'short');
        // SameSite None dengan secure OK; origin dengan origins OK.
        new SecurityPolicy(csrfSecureCookie: true, csrfSameSite: 'None');
        $o = new SecurityPolicy(allowedOrigins: ['https://a.com'], originEnabled: true);
        self::assertSame(['https://a.com'], $o->allowedOrigins);
        // Dedup origin: pasangan ganda -> array_values menata ulang list ketat.
        $d = new SecurityPolicy(allowedOrigins: ['https://a.com', 'https://a.com', 'https://b.com']);
        self::assertSame(['https://a.com', 'https://b.com'], $d->allowedOrigins);
    }

    public function testSecurityPolicyFromEnvironmentDefaultsAndTrims(): void
    {
        $this->withEnv(['ZEF_SECURITY_CSRF' => null, 'ZEF_SECURITY_CSRF_SECRET' => null, 'ZEF_SECURITY_RATE_LIMIT' => null, 'ZEF_SECURITY_RATE_LIMIT_MAX' => null, 'ZEF_SECURITY_RATE_LIMIT_WINDOW' => null, 'ZEF_SECURITY_RATE_LIMIT_MAX_KEYS' => null, 'ZEF_SECURITY_CSRF_COOKIE' => null, 'ZEF_SECURITY_CSRF_HEADER' => null, 'ZEF_SECURITY_CSRF_SECURE' => null, 'ZEF_SECURITY_CSRF_HTTP_ONLY' => null, 'ZEF_SECURITY_CSRF_SAMESITE' => null, 'ZEF_SECURITY_CSRF_TOKEN_BYTES' => null, 'ZEF_SECURITY_ALLOWED_ORIGINS' => null, 'ZEF_SECURITY_ORIGIN_POLICY' => null], function (): void {
            $p = SecurityPolicy::fromEnvironment();
            self::assertFalse($p->csrfEnabled, 'Tanpa secret, CSRF otomatis nonaktif.');
            self::assertSame(100, $p->rateLimitMaxRequests);
            self::assertSame(60, $p->rateLimitWindowSeconds);
            self::assertSame(10000, $p->rateLimitMaxKeys);
            self::assertSame('ZEF-XSRF-TOKEN', $p->csrfCookieName);
            self::assertSame('Strict', $p->csrfSameSite);
            self::assertTrue($p->csrfSecureCookie);
            self::assertTrue($p->csrfHttpOnlyCookie);
            self::assertSame(32, $p->csrfTokenBytes);
        });
        // Nilai env dengan whitespace: trim wajib (UnwrapTrim).
        $this->withEnv(['ZEF_SECURITY_CSRF_COOKIE' => ' ZEF-XSRF-TOKEN ', 'ZEF_SECURITY_CSRF_HEADER' => ' X-CSRF-Token ', 'ZEF_SECURITY_CSRF_SAMESITE' => ' Lax ', 'ZEF_SECURITY_RATE_LIMIT_MAX' => ' 250 ', 'ZEF_SECURITY_CSRF' => ' ', 'ZEF_SECURITY_CSRF_SECRET' => str_repeat('s', 32)], function (): void {
            $p = SecurityPolicy::fromEnvironment();
            self::assertSame('ZEF-XSRF-TOKEN', $p->csrfCookieName);
            self::assertSame('X-CSRF-Token', $p->csrfHeaderName);
            self::assertSame('Lax', $p->csrfSameSite);
            self::assertSame(250, $p->rateLimitMaxRequests);
            self::assertTrue($p->csrfEnabled, 'CSRF env berisi spasi = dianggap tidak diset -> default aktif (secret tersedia).');
        });
        // Batas atas env di-clamp oleh max(16, ...) dan token bytes.
        $this->withEnv(['ZEF_SECURITY_CSRF_TOKEN_BYTES' => '8'], function (): void {
            self::assertSame(16, SecurityPolicy::fromEnvironment()->csrfTokenBytes);
        });
        $this->withEnv(['ZEF_SECURITY_CSRF_TOKEN_BYTES' => '64'], function (): void {
            self::assertSame(64, SecurityPolicy::fromEnvironment()->csrfTokenBytes);
        });
        // Non-digit jatuh ke default, 0 di-clamp ke 1.
        $this->withEnv(['ZEF_SECURITY_RATE_LIMIT_WINDOW' => 'abc', 'ZEF_SECURITY_RATE_LIMIT_MAX_KEYS' => '0'], function (): void {
            $p = SecurityPolicy::fromEnvironment();
            self::assertSame(60, $p->rateLimitWindowSeconds);
            self::assertSame(1, $p->rateLimitMaxKeys);
        });
        $this->withEnv(['ZEF_SECURITY_RATE_LIMIT_WINDOW' => '-5'], function (): void {
            self::assertSame(60, SecurityPolicy::fromEnvironment()->rateLimitWindowSeconds);
        });
    }

    public function testSecurityPolicyCsrfEnvMatrix(): void
    {
        $secret = str_repeat('s', 32);
        $this->withEnv(['ZEF_SECURITY_CSRF' => '1', 'ZEF_SECURITY_CSRF_SECRET' => $secret], function (): void {
            self::assertTrue(SecurityPolicy::fromEnvironment()->csrfEnabled);
        });
        $this->withEnv(['ZEF_SECURITY_CSRF' => '1', 'ZEF_SECURITY_CSRF_SECRET' => null], function (): void {
            try {
                SecurityPolicy::fromEnvironment();
                self::fail('CSRF=1 tanpa secret harus RuntimeException.');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('ZEF_SECURITY_CSRF_SECRET', $e->getMessage());
            }
        });
        $this->withEnv(['ZEF_SECURITY_CSRF' => '0', 'ZEF_SECURITY_CSRF_SECRET' => null], function (): void {
            self::assertFalse(SecurityPolicy::fromEnvironment()->csrfEnabled);
        });
    }

    public function testSecurityPolicyWarningRoutesToLoggerAndErrorLog(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(self::stringContains('ZEF_SECURITY_CSRF_SECRET is not set'));
        $this->withEnv(['ZEF_SECURITY_CSRF' => null, 'ZEF_SECURITY_CSRF_SECRET' => null], static fn (): SecurityPolicy => SecurityPolicy::fromEnvironment($logger));

        // Tanpa logger: error_log diarahkan ke file sementara (pola $previous).
        $tmp = tempnam(sys_get_temp_dir(), 'zefsec');
        $prev = ini_get('error_log');
        ini_set('error_log', $tmp);

        try {
            $this->withEnv(['ZEF_SECURITY_CSRF' => null, 'ZEF_SECURITY_CSRF_SECRET' => null], static fn (): mixed => SecurityPolicy::fromEnvironment());
        } finally {
            ini_set('error_log', $prev);
        }
        $written = (string) file_get_contents((string) $tmp);
        unlink($tmp); // nosemgrep: php.lang.security.unlink-use
        self::assertStringContainsString('CSRF protection disabled', $written);
    }

    // ------------------------------------------------------- Base32 (10)

    public function testBase32Rfc4648Vectors(): void
    {
        $vectors = [
            '' => '',
            'f' => 'MY',
            'fo' => 'MZXQ',
            'foo' => 'MZXW6',
            'foob' => 'MZXW6YQ',
            'fooba' => 'MZXW6YTB',
            'foobar' => 'MZXW6YTBOI',
        ];
        foreach ($vectors as $raw => $b32) {
            self::assertSame($b32, Base32::encode($raw), "encode({$raw})");
        }
        // Dekode persis 5 byte: eksak 8 simbol (loop >= 5 vs > 5).
        self::assertSame('foobar', Base32::decode('MZXW6YTBOI'));
        self::assertSame('a', Base32::decode('ME'));
        self::assertSame('foobar', Base32::decode('mzxw6ytboi'));
        self::assertSame('foobar', Base32::decode('MZXW6YTBOI===='));
        self::assertSame('', Base32::encode(''));
        self::assertSame('', Base32::decode(''));
    }

    public function testBase32RejectsIllegalAndNonCanonicalPadding(): void
    {
        foreach (['1A', 'A1', '8A', 'A8', '0A', 'A0', '9A', 'A!', 'A B'] as $bad) {
            try {
                Base32::decode($bad);
                self::fail("Input base32 '{$bad}' harus ditolak.");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        // Padding bit non-nol: 'ME' kanonik; 'MF' memiliki sisa bit != 0.
        try {
            Base32::decode('MF');
            self::fail('Padding bit non-kanonik harus ditolak.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Invalid base32 padding bits.', $e->getMessage());
        }
    }

    // --------------------------------------------------------- Totp (10)

    public function testTotpRfc4226VectorsAndHighCounters(): void
    {
        $secret = '12345678901234567890';
        $expected = [755224, 287082, 359152, 969429, 338314, 254676, 287922, 162583, 399871, 520489];
        foreach ($expected as $counter => $code) {
            self::assertSame((string) $code, Totp::hotp($secret, $counter), "HOTP counter {$counter}");
        }
        // Counter >= 2^32: membunuh shift/mask 32-bit.
        self::assertSame('999456', Totp::hotp($secret, 4294967296));
        self::assertSame('108930', Totp::hotp($secret, 4294967297));
        self::assertSame('166590', Totp::hotp($secret, 8589934592));
        // Algoritma lain (RFC 6238): 8 digit, SHA-1, T=59 -> counter 1.
        self::assertSame('94287082', Totp::hotp($secret, 1, 8, 'sha1'));

        try {
            Totp::hotp($secret, 1, 6, 'md5');
            self::fail('Algoritma tidak didukung harus ditolak.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        try {
            Totp::hotp('short', 1);
            self::fail('Secret 5 byte harus ditolak.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    public function testTotpCtorBoundsAndSecretGuard(): void
    {
        new Totp(1, 6, 'sha1');
        new Totp(86400, 8, 'sha256');
        new Totp(30, 7, 'sha512');
        $bad = [
            [0, 6, 'sha1'], [86401, 6, 'sha1'], [30, 5, 'sha1'], [30, 9, 'sha1'], [30, 6, 'md5'],
        ];
        foreach ($bad as $args) {
            try {
                new Totp(...$args);
                self::fail('Parameter Totp di luar batas harus ditolak.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $t = new Totp();
        self::assertSame(30, $t->period);
        self::assertSame(6, $t->digits);
        self::assertSame('sha1', $t->algorithm);
        $secret = str_repeat('k', 8);
        self::assertSame(Totp::hotp($secret, 1, 6), $t->at($secret, 59));
        self::assertSame(Totp::hotp($secret, 2, 6), $t->at($secret, 89));

        try {
            $t->at('1234567', 59);
            self::fail('Secret 7 byte harus ditolak di at().');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    public function testTotpVerifyWindowAndCodeGrammar(): void
    {
        $t = new Totp();
        $secret = '12345678901234567890';
        $now = 59 * 30; // 59 periode penuh
        $codeHere = $t->at($secret, $now);
        $codePrev = $t->at($secret, $now - 30);
        $codeNext = $t->at($secret, $now + 30);
        self::assertTrue($t->verify($secret, $codeHere, $now, 0));
        self::assertFalse($t->verify($secret, $codePrev, $now, 0));
        self::assertTrue($t->verify($secret, $codePrev, $now, 1));
        self::assertTrue($t->verify($secret, $codeNext, $now, 1));
        self::assertFalse($t->verify($secret, $codeNext, $now, -1));
        self::assertFalse($t->verify($secret, '12345', $now));
        self::assertFalse($t->verify($secret, '1234567', $now));
        self::assertFalse($t->verify($secret, 'abcdef', $now));

        try {
            $t->verify('1234567', '123456', $now);
            self::fail('Secret pendek harus ditolak di verify().');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    // ------------------------------------------ Security Distributed (10)

    public function testSecurityContextAllFieldsBounded(): void
    {
        new SecurityContext('u-1', 'pwd', 'ctx', 'scope', null);
        new SecurityContext(str_repeat('p', 128), str_repeat('m', 64), str_repeat('c', 256), str_repeat('s', 128), str_repeat('n', 128));
        foreach ([
            ['', 'm', 'c', 's', null],
            [str_repeat('p', 129), 'm', 'c', 's', null],
            ['p', '', 'c', 's', null],
            ['p', str_repeat('m', 65), 'c', 's', null],
            ['p', 'm', '', 's', null],
            ['p', 'm', str_repeat('c', 257), 's', null],
            ['p', 'm', 'c', '', null],
            ['p', 'm', 'c', str_repeat('s', 129), null],
            ['p', 'm', 'c', 's', str_repeat('n', 129)],
        ] as $args) {
            try {
                new SecurityContext(...$args);
                self::fail('Field di luar bound harus ditolak.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testSecurityRequestGuardsAndSensitiveKeys(): void
    {
        new SecurityRequest('read', 'res', 'get');
        new SecurityRequest(str_repeat('o', 128), str_repeat('r', 128), str_repeat('a', 64), str_repeat('p', 128));
        foreach ([
            ['', 'r', 'a'],
            ['o', '', 'a'],
            ['o', 'r', ''],
            [str_repeat('o', 129), 'r', 'a'],
            ['o', str_repeat('r', 129), 'a'],
            ['o', 'r', str_repeat('a', 65)],
            ['o', 'r', 'a', str_repeat('p', 129)],
        ] as $args) {
            try {
                new SecurityRequest(...$args);
                self::fail('Field SecurityRequest di luar bound harus ditolak.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        // Atribut: kunci sensitif ditolak case-insensitive, termasuk proxy-auth.
        foreach (['authorization', 'proxy-authorization', 'x-token', 'PASSWORD', 'private_key', 'private-key', 'my-secret-value'] as $badKey) {
            try {
                new SecurityRequest('o', 'r', 'a', null, [$badKey => 'v']);
                self::fail("Kunci sensitif '{$badKey}' harus ditolak.");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        new SecurityRequest('o', 'r', 'a', null, ['safe' => 'v', 'num' => 42, 'nul' => null]);

        try {
            new SecurityRequest('o', 'r', 'a', null, ['arr' => ['x']]);
            self::fail('Nilai non-scalar harus ditolak.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        $attrs16 = [];
        for ($i = 0; $i < 16; ++$i) {
            $attrs16[chr(97 + $i)] = str_repeat('v', 254);
        }
        new SecurityRequest('o', 'r', 'a', null, $attrs16);
        $attrs17 = $attrs16;
        $attrs17['k17'] = 'v';

        try {
            new SecurityRequest('o', 'r', 'a', null, $attrs17);
            self::fail('Atribut ke-17 harus ditolak.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        try {
            new SecurityRequest('o', 'r', 'a', null, ['big' => str_repeat('v', 257)]);
            self::fail('Nilai atribut 257 byte harus ditolak.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        // Agregat 4097: 15 x (2+254) + (3+254) = 4097.
        $agg = [];
        for ($i = 0; $i < 15; ++$i) {
            $agg['k' . $i] = str_repeat('v', 254);
        }
        new SecurityRequest('o', 'r', 'a', null, $agg); // 15 x 256 = 3840 OK
        $agg['xyz'] = str_repeat('v', 254);

        try {
            new SecurityRequest('o', 'r', 'a', null, $agg);
            self::fail('Agregat 4097 byte harus ditolak.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    public function testAuthenticationResultContextCoupling(): void
    {
        $ctx = new SecurityContext('u', 'm', 'c', 's', null);
        new AuthenticationResult(AuthenticationStatus::AUTHENTICATED, $ctx);
        new AuthenticationResult(AuthenticationStatus::FAILED);

        try {
            new AuthenticationResult(AuthenticationStatus::AUTHENTICATED);
            self::fail('Authenticated tanpa context harus ditolak.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        try {
            new AuthenticationResult(AuthenticationStatus::FAILED, $ctx);
            self::fail('Non-authenticated dengan context harus ditolak.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    public function testAuthorizationResultPolicyCodeBound(): void
    {
        new AuthorizationResult(SecurityVerdict::DENY, str_repeat('p', 128));

        try {
            new AuthorizationResult(SecurityVerdict::DENY, '');
            self::fail('policyCode kosong harus ditolak.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        try {
            new AuthorizationResult(SecurityVerdict::DENY, str_repeat('p', 129));
            self::fail('policyCode 129 byte harus ditolak.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    public function testSecurityAdmissionDecisionConsistency(): void
    {
        new SecurityAdmissionDecision(SecurityVerdict::ALLOW, SecurityFailure::NONE, true);
        new SecurityAdmissionDecision(SecurityVerdict::ALLOW, SecurityFailure::NONE, false);
        new SecurityAdmissionDecision(SecurityVerdict::DENY, SecurityFailure::NONE, false);
        new SecurityAdmissionDecision(SecurityVerdict::DENY, SecurityFailure::REPLAY_REJECTED, false);
        self::assertTrue(new SecurityAdmissionDecision(SecurityVerdict::ALLOW, SecurityFailure::NONE, false)->allows());
        self::assertFalse(new SecurityAdmissionDecision(SecurityVerdict::DENY, SecurityFailure::NONE, false)->allows());

        try {
            new SecurityAdmissionDecision(SecurityVerdict::ALLOW, SecurityFailure::REPLAY_REJECTED, false);
            self::fail('ALLOW dengan failure harus ditolak.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        try {
            new SecurityAdmissionDecision(SecurityVerdict::DENY, SecurityFailure::NONE, true);
            self::fail('DENY dengan retry harus ditolak.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    public function testRadixTreeInsertGuardsAndIdempotence(): void
    {
        $t = new NamespaceRadixTree();
        $t->insert('A\B');
        $t->insert('A\B');
        self::assertSame(1, $t->stats()['serviceIds']);

        try {
            $t->insert('');
            self::fail('ID kosong harus ditolak.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        $t->seal();
        $t->seal(); // idempoten
        self::assertTrue($t->isSealed());

        try {
            $t->insert('C\D');
            self::fail('Insert pasca-seal harus LogicException.');
        } catch (\LogicException) {
            self::addToAssertionCount(1);
        }

        try {
            $t->annotate('X', 'internal');
            self::fail('Annotate pasca-seal harus LogicException.');
        } catch (\LogicException) {
            self::addToAssertionCount(1);
        }
    }

    public function testRadixTreeAnnotateScopeAndMessage(): void
    {
        $t = new NamespaceRadixTree();
        foreach (NamespaceRadixTree::SCOPES as $scope) {
            $t->annotate("N\\{$scope}", $scope);
        }

        try {
            $t->annotate('X', 'bogus');
            self::fail('Scope bogus harus ditolak.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('public, internal, module', $e->getMessage());
        }
    }

    public function testRadixTreeContainsExactMidEdgeAndMismatch(): void
    {
        $t = $this->sealedTree();
        self::assertTrue($t->containsExact('App\Http\Api\One'));
        self::assertTrue($t->containsExact('App\Cli\Run'));
        self::assertFalse($t->containsExact(''));
        self::assertFalse($t->containsExact('App\Http')); // query berakhir di tengah edge
        self::assertFalse($t->containsExact('App\Http\Api'));
        self::assertFalse($t->containsExact('App\Cli\Xxx')); // mismatch di edge
        self::assertFalse($t->containsExact('Z\X'));
    }

    public function testRadixTreeIdsUnderPrefixSortedAndMidEdge(): void
    {
        $t = $this->sealedTree();
        self::assertSame(['App\Cli\Run', 'App\Http\Api\One', 'App\Http\Api\Two'], $t->idsUnderPrefix('App'));
        self::assertSame(['App\Cli\Run', 'App\Http\Api\One', 'App\Http\Api\Two'], $t->idsUnderPrefix('App\\'));
        self::assertSame(['App\Http\Api\One', 'App\Http\Api\Two'], $t->idsUnderPrefix('App\Http'));
        self::assertSame(['App\Http\Api\One', 'App\Http\Api\Two'], $t->idsUnderPrefix('App\Http\Api'));
        self::assertSame([], $t->idsUnderPrefix('App\Http\X'));
        self::assertSame([], $t->idsUnderPrefix('Z'));
        // Mid-edge mismatch pada posisi pertama edge.
        self::assertSame([], $t->idsUnderPrefix('App\Cli\Xx'));
    }

    public function testRadixTreeScopeOfLongestPrefixWins(): void
    {
        $t = new NamespaceRadixTree();
        $t->insert('App\Http\Api\One');
        $t->insert('App\Http\Api\Two');
        $t->insert('App\Cli\Run');
        $t->annotate('App', 'module');
        $t->annotate('App\Http', 'internal');
        $t->seal();
        $cli = $t->scopeOf('App\Cli\Run');
        $httpOne = $t->scopeOf('App\Http\Api\One');
        $httpTwo = $t->scopeOf('App\Http\Api\Two');
        self::assertNotNull($cli);
        self::assertNotNull($httpOne);
        self::assertNotNull($httpTwo);
        self::assertNull($t->scopeOf('Other\X'));
        self::assertSame('module', $cli['scope']);
        self::assertSame('internal', $httpOne['scope']);
        self::assertSame('internal', $httpTwo['scope']);
        self::assertSame(['App\\' => 'module', 'App\Http\\' => 'internal'], $t->annotations());
    }

    public function testRadixTreeStatsExact(): void
    {
        $t = $this->sealedTree();
        $s = $t->stats();
        self::assertSame(3, $s['serviceIds']);
        self::assertSame(6, $s['nodes']);
        self::assertSame(5, $s['edges']);
        self::assertSame(4, $s['maxDepth']);
        self::assertSame(11, $s['rawSegments']);
        self::assertSame(2.2, $s['compressionRatio']);
        self::assertSame(0, $s['annotations']);
        self::assertTrue($s['sealed']);
        // Rasio desimal berulang (12/7 = 1.714285...): membunuh precision/rounding mutant.
        $r = new NamespaceRadixTree();
        $r->insert('X\Y\One');
        $r->insert('X\Y\Two');
        $r->insert('X\Z\Three');
        $r->insert('X\Z\Four');
        $r->seal();
        $sr = $r->stats();
        self::assertSame(round($sr['rawSegments'] / $sr['edges'], 4), $sr['compressionRatio']);
        self::assertNotSame(round($sr['rawSegments'] / $sr['edges'], 3), $sr['compressionRatio']);
        self::assertNotSame(floor($sr['rawSegments'] / $sr['edges']), $sr['compressionRatio']);
    }

    public function testRadixTreeExportImportRoundTrip(): void
    {
        $t = new NamespaceRadixTree();
        $t->insert('App\Http\Api\One');
        $t->insert('App\Http\Api\Two');
        $t->insert('App\Cli\Run');
        $t->annotate('App\Http', 'internal');
        $t->seal();
        $export = $t->exportArray();
        self::assertSame('v2.11.0', $export['version']);
        $back = NamespaceRadixTree::fromArray($export);
        self::assertTrue($back->isSealed());
        self::assertSame($t->stats()['serviceIds'], $back->stats()['serviceIds']);
        self::assertSame(['App\Http\Api\One', 'App\Http\Api\Two'], $back->idsUnderPrefix('App\Http'));
        $scoped = $back->scopeOf('App\Http\Api\One');
        self::assertNotNull($scoped);
        self::assertSame('internal', $scoped['scope']);
        // Cast int menerima string numerik; default 0 tanpa kunci; sealed default true.
        $loose = NamespaceRadixTree::fromArray(['root' => ['ids' => [], 'children' => []], 'serviceCount' => '7']);
        self::assertSame(7, $loose->stats()['serviceIds']);
        self::assertSame(0, $loose->stats()['maxDepth']);
        self::assertSame(0, $loose->stats()['rawSegments']);
        self::assertTrue($loose->isSealed());
        $open = NamespaceRadixTree::fromArray(['root' => ['ids' => [], 'children' => []], 'sealed' => false]);
        self::assertFalse($open->isSealed());

        try {
            NamespaceRadixTree::fromArray([]);
            self::fail('Payload tanpa root harus ditolak.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    // ---------------------------------------------- Autowiring residual (23)

    public function testAutowireMetadataDependenciesAndArgumentPlan(): void
    {
        $m = new AutowireMetadata('svc', 'Cls', ['dep0', 'dep1'], [['dep', 0], ['literal', "'x'"], ['dep', 1]]);
        self::assertSame(['dep0', 'dep1'], $m->dependencies);
        self::assertSame(ServiceLifetime::SINGLETON, $m->lifetime);
        $bad = [
            [['ok', 42], []],                    // dep non-string
            [['ok', ''], []],                    // dep kosong
            [['ok'], [['x', 0]]],                // plan tipe tak dikenal
            [['ok'], [['dep']]],
            [['ok'], [['dep', -1]]],             // index negatif
            [['ok'], [['dep', 1]]],              // index melebihi deps
            [['ok'], [['dep', 'str']]],
            [['ok'], [['literal', 42]]],         // literal non-string
        ];
        foreach ($bad as $i => [$deps, $plan]) {
            try {
                // @phpstan-ignore-next-line (argumen invalid disengaja)
                new AutowireMetadata('svc', 'Cls', $deps, $plan);
                self::fail("Metadata invalid #{$i} harus ditolak.");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('svc', $e->getMessage());
            }
        }
        new AutowireMetadata('svc', 'Cls', ['d'], [['dep', 0]], 'mod', 'request');
        self::assertNull(new AutowireMetadata('svc', 'Cls', [], [])->module);
        self::assertSame('mod', new AutowireMetadata('svc', 'Cls', [], [], 'mod')->module);
    }

    public function testAutowireResultTransitiveDependencies(): void
    {
        $meta = [
            'a' => new AutowireMetadata('a', 'A', ['b'], []),
            'b' => new AutowireMetadata('b', 'B', ['c', 'a'], []),
            'c' => new AutowireMetadata('c', 'C', [], []),
        ];
        $r = new AutowireResult($meta, [], []);
        self::assertSame(['b', 'c'], $r->transitiveDependenciesOf('a'));
        self::assertSame([], $r->transitiveDependenciesOf('c'));
        self::assertSame([], $r->transitiveDependenciesOf('ghost'));
    }

    public function testAutowiringAttributesGrammar(): void
    {
        new Target(\DateTimeImmutable::class);
        new Target(\Countable::class);
        new Target(F6FixtureStatus::class);

        try {
            // @phpstan-ignore-next-line (ghost class disengaja)
            new Target('No\Such\Class');
            self::fail('Target ghost harus ditolak.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        new Value('db.host-1:2_x');
        new Value(str_repeat('a', 190));
        foreach (['', 'bad key!', '!lead', 'tail!', str_repeat('a', 191)] as $bad) {
            try {
                new Value($bad);
                self::fail("Value key '{$bad}' harus ditolak.");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        new Inject('svc.id');
        new Inject('  svc.id  '); // dipertahankan apa adanya selama tak kosong
        foreach (['', '   '] as $bad) {
            try {
                // @phpstan-ignore-next-line (string kosong disengaja)
                new Inject($bad);
                self::fail('Inject kosong harus ditolak.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    /** @param array<string, null|string> $pairs @param callable(): mixed $fn */
    private function withEnv(array $pairs, callable $fn): void
    {
        $previous = [];
        foreach ($pairs as $name => $value) {
            $previous[$name] = getenv($name);
            putenv($value === null ? $name : $name . '=' . $value);
        }

        try {
            $fn();
        } finally {
            foreach ($previous as $name => $old) {
                putenv($old === false ? $name : $name . '=' . $old);
            }
        }
    }

    // ------------------------------------------------- ModuleDefinition (13)

    private function svc(string $id): ServiceDefinition
    {
        return new ServiceDefinition($id, static fn (): null => null);
    }

    // ---------------------------------------------- NamespaceRadixTree (29)

    private function sealedTree(): NamespaceRadixTree
    {
        $t = new NamespaceRadixTree();
        $t->insert('App\Http\Api\One');
        $t->insert('App\Http\Api\Two');
        $t->insert('App\Cli\Run');
        $t->seal();

        return $t;
    }
}
