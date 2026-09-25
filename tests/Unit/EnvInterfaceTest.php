<?php

declare(strict_types=1);

/*
 * Issue #55 — instance-based EnvInterface for composition-root injection.
 *
 * Empat invariant dikawal di sini:
 *   1. Paritas BC: facade statis historis (Env::int dsb., 38 call site
 *      saat issue dibuka) dan surface instance EnvInterface (readInt
 *      dsb.) wajib mengembalikan hasil identik dari sumber env yang
 *      sama — termasuk kontrak default dan clamping.
 *   2. Binding kernel: composition root mendaftarkan EnvInterface jako
 *      service container biasa (singleton) — kode produksi baru
 *      bergantung pada port, bukan kelas konkretnya.
 *   3. Surface statis TIDAK berubah: signature dan body metode statis
 *      persis seperti sebelum issue ini dibuka.
 *   4. Tidak ada static state baru: Env tetap bebas property static
 *      (invariant worker long-running, RuntimeSoakTest).
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zef\Framework\Application;
use Zef\Framework\Config\ConfigProviderInterface;
use Zef\Framework\Foundation\Env;
use Zef\Framework\Foundation\EnvInterface;
use Zef\Framework\Http\Response;

/**
 * @internal
 */
final class EnvInterfaceTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('ZEF_ENVIFACE_TEST_INT');
        putenv('ZEF_ENVIFACE_TEST_BOOL');
        putenv('ZEF_ENVIFACE_TEST_STR');
        putenv('ZEF_ENVIFACE_TEST_CSV');
    }

    public function testInstanceSurfaceImplementsThePort(): void
    {
        $env = new Env();
        self::assertInstanceOf(EnvInterface::class, $env);
        self::assertSame(Env::class, $env::class, 'kernel binding target stays the concrete Env');
    }

    public function testStaticFacadeAndInstanceSurfaceAreEquivalent(): void
    {
        putenv('ZEF_ENVIFACE_TEST_INT=42');
        putenv('ZEF_ENVIFACE_TEST_BOOL=true');
        putenv('ZEF_ENVIFACE_TEST_STR=hello');
        putenv('ZEF_ENVIFACE_TEST_CSV=a, b ,c');

        $env = new Env();

        self::assertSame(Env::int('ZEF_ENVIFACE_TEST_INT', 0, 0, 100), $env->readInt('ZEF_ENVIFACE_TEST_INT', 0, 0, 100));
        self::assertSame(42, $env->readInt('ZEF_ENVIFACE_TEST_INT', 0, 0, 100));

        self::assertSame(Env::bool('ZEF_ENVIFACE_TEST_BOOL'), $env->readBool('ZEF_ENVIFACE_TEST_BOOL'));
        self::assertTrue($env->readBool('ZEF_ENVIFACE_TEST_BOOL'));

        self::assertSame(Env::string('ZEF_ENVIFACE_TEST_STR', 'fallback'), $env->readString('ZEF_ENVIFACE_TEST_STR', 'fallback'));
        self::assertSame('hello', $env->readString('ZEF_ENVIFACE_TEST_STR', 'fallback'));

        self::assertSame(Env::csv('ZEF_ENVIFACE_TEST_CSV'), $env->readCsv('ZEF_ENVIFACE_TEST_CSV'));
        self::assertSame(['a', 'b', 'c'], $env->readCsv('ZEF_ENVIFACE_TEST_CSV'));
    }

    public function testDefaultsAndClampingHoldOnBothSurfaces(): void
    {
        $env = new Env();

        // unset vars fall back to defaults on both surfaces
        self::assertSame(7, $env->readInt('ZEF_ENVIFACE_TEST_INT', 7, 0, 100));
        self::assertSame(7, Env::int('ZEF_ENVIFACE_TEST_INT', 7, 0, 100));
        self::assertFalse($env->readBool('ZEF_ENVIFACE_TEST_BOOL'));
        self::assertFalse(Env::bool('ZEF_ENVIFACE_TEST_BOOL'));
        self::assertSame('', $env->readString('ZEF_ENVIFACE_TEST_STR'));
        self::assertSame([], $env->readCsv('ZEF_ENVIFACE_TEST_CSV'));

        // clamping contract is part of the port
        putenv('ZEF_ENVIFACE_TEST_INT=999');
        self::assertSame(100, $env->readInt('ZEF_ENVIFACE_TEST_INT', 0, 0, 100));
        self::assertSame(100, Env::int('ZEF_ENVIFACE_TEST_INT', 0, 0, 100));

        // strict-mode failure propagates identically
        putenv('ZEF_ENVIFACE_TEST_INT=not-a-number');
        $this->expectException(\InvalidArgumentException::class);
        $env->readInt('ZEF_ENVIFACE_TEST_INT', 0, 0, 100, true);
    }

    public function testKernelBindsEnvInterfaceAsASingletonService(): void
    {
        $app = new Application();
        $app->addProvider(new EnvPortProbeProvider());
        $app->boot();

        $resolved = $app->getContainer()->get(EnvInterface::class);
        self::assertInstanceOf(EnvInterface::class, $resolved);
        self::assertSame(Env::class, $resolved::class);

        // singleton: same instance across resolutions
        self::assertSame($resolved, $app->getContainer()->get(EnvInterface::class));
    }

    public function testEnvCarriesNoStaticState(): void
    {
        $statics = [];
        foreach (new \ReflectionClass(Env::class)->getProperties(\ReflectionProperty::IS_STATIC) as $prop) {
            $statics[] = $prop->getName();
        }
        self::assertSame([], $statics, 'Env wajib bebas property static (invariant worker long-running).');
    }
}

// ------------------------------------------------------------- Fixtures

final class EnvPortProbeProvider implements ConfigProviderInterface
{
    #[\Override]
    public function getModuleName(): string
    {
        return 'envport';
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function getConfig(): array
    {
        return [
            'routes' => [
                ['method' => 'GET', 'path' => '/envport/ok', 'handler' => 'envport.ok', 'priority' => 10],
            ],
            'services' => [
                'envport.ok' => [
                    'factory' => static fn (): EnvPortOkHandler => new EnvPortOkHandler(),
                    'deps' => [],
                ],
            ],
        ];
    }
}

final class EnvPortOkHandler implements RequestHandlerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'text/plain'], 'envport-ok');
    }
}
