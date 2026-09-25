<?php

declare(strict_types=1);

/*
 * Issue #55 step 4 — guard test untuk deprecasi facade statis Env.
 *
 * Empat invariant dikawal di sini:
 *   1. Seluruh metode statis publik Env membawa tag @deprecated pada
 *      docblock-nya (sinyal statis-analisis/IDE bagi konsumen baru).
 *   2. Setiap pemanggilan facade statis memancarkan E_USER_DEPRECATED pada
 *      runtime. Notice dipancarkan dengan @-suppression ala Symfony: PHP
 *      tetap memanggil error handler kustom (test ini menangkapnya) sambil
 *      menjaga PHPUnit/self-test hening — error_reporting() bernilai 0 di
 *      dalam scope handler untuk panggilan ter-suppress.
 *   3. Permukaan instance EnvInterface bersih: readInt/readBool/
 *      readString/readCsv TIDAK memancarkan deprecation apa pun.
 *   4. Versi framework konsisten dengan rilis deprecasi (CHANGELOG-v2.28.0).
 *
 * Timeline: permukaan statis dihapus di v3.0 (major) — dokumentasi migrasi
 * lengkap ada di docs/CHANGELOG-v2.28.0.md.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Foundation\Env;
use Zef\Framework\Foundation\ZefVersion;

/**
 * @internal
 */
final class EnvStaticDeprecationTest extends TestCase
{
    private const array DEPRECATED_STATIC_METHODS = ['int', 'bool', 'string', 'csv'];

    public function testEveryStaticFacadeMethodCarriesTheDeprecatedTag(): void
    {
        $reflection = new \ReflectionClass(Env::class);

        foreach (self::DEPRECATED_STATIC_METHODS as $methodName) {
            $docComment = $reflection->getMethod($methodName)->getDocComment();

            self::assertIsString($docComment, $methodName . '() must keep a docblock.');
            self::assertStringContainsString(
                '@deprecated',
                $docComment,
                $methodName . '() must carry the @deprecated tag since v2.28.0.',
            );
        }
    }

    public function testStaticFacadeEmitsRuntimeDeprecationNotices(): void
    {
        $captured = $this->captureDeprecations(static function (): void {
            Env::int('ZEF_DEPREC8_INT', 5, 0, 10);
            Env::bool('ZEF_DEPREC8_BOOL');
            Env::string('ZEF_DEPREC8_STR');
            Env::csv('ZEF_DEPREC8_CSV');
        });

        self::assertCount(4, $captured, 'each static facade call must emit one notice.');

        $expectedPairs = [
            ['Env::int()', 'readInt()'],
            ['Env::bool()', 'readBool()'],
            ['Env::string()', 'readString()'],
            ['Env::csv()', 'readCsv()'],
        ];
        foreach ($captured as $index => [$errno, $message]) {
            self::assertSame(E_USER_DEPRECATED, $errno, 'notice ' . $index . ' must be E_USER_DEPRECATED.');
            self::assertStringContainsString('deprecated since v2.28.0', $message);
            self::assertStringContainsString('removed in v3.0', $message);
            self::assertStringContainsString('EnvInterface', $message);
            self::assertStringContainsString($expectedPairs[$index][0], $message);
            self::assertStringContainsString($expectedPairs[$index][1], $message);
        }
    }

    public function testInstancePortSurfaceEmitsNoDeprecation(): void
    {
        $captured = $this->captureDeprecations(static function (): void {
            $env = new Env();
            $env->readInt('ZEF_DEPREC8_INT', 5, 0, 10);
            $env->readBool('ZEF_DEPREC8_BOOL');
            $env->readString('ZEF_DEPREC8_STR');
            $env->readCsv('ZEF_DEPREC8_CSV');
        });

        self::assertSame([], $captured, 'the EnvInterface port is the clean, supported surface.');
    }

    public function testFrameworkVersionMatchesTheDeprecationRelease(): void
    {
        // The facade deprecation SHIPPED in v2.28.0; the runtime version may
        // move on in later releases, but it must never appear to predate the
        // deprecation itself (message contract stays anchored to v2.28.0).
        self::assertTrue(
            version_compare(ZefVersion::VERSION, '2.28.0', '>='),
            sprintf('ZefVersion %s must not predate the v2.28.0 deprecation release.', ZefVersion::VERSION),
        );
    }

    /**
     * @return list<array{int, string}>
     */
    private function captureDeprecations(callable $invocations): array
    {
        $captured = [];
        set_error_handler(
            static function (int $errno, string $errstr) use (&$captured): bool {
                $captured[] = [$errno, $errstr];

                return true;
            },
            E_USER_DEPRECATED,
        );

        try {
            $invocations();
        } finally {
            restore_error_handler();
        }

        return $captured;
    }
}
