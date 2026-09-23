<?php

declare(strict_types=1);

/*
 * Guild Action Item 2 — validasi memory leak di RoadRunner (follow-up guard).
 *
 * RoadRunnerRuntime adalah long-running process: satu worker PHP menangani
 * ribuan request secara berurutan dalam satu proses, sehingga kebocoran
 * state yang tak terlihat pada test satu-request akan terakumulasi dan
 * akhirnya membunuh worker di produksi (atau memicu restart berulang via
 * memoryLimitBytes).
 *
 * Dua invariant dikawal di sini:
 *   1. Soak: 3.300 request berurutan lewat jalur produksi penuh (stack
 *      middleware asli + probe di ujung) — pertumbuhan memori pasca-warmup
 *      wajib terikat (kalibrasi lokal: +56 KiB used / +2 MiB arena untuk
 *      3.000 request = ~19 byte/request; ambang diberi margin 9x).
 *   2. Static-state: layer yang hidup sepanjang umur worker (Infrastructure,
 *      Adapters/Runtime, Middleware) tidak boleh punya property static
 *      SAMA SEKALI — static property adalah vektor kebocoran klasik pada
 *      long-running process. Menambah satu = wajib buka baseline ini secara
 *      sadar (atau pindahkan state ke service bersingleton).
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zef\Framework\Application;
use Zef\Framework\Config\ConfigProviderInterface;
use Zef\Framework\Http\Response;
use Zef\Framework\Http\ServerRequest;
use Zef\Framework\Http\Uri;
use Zef\Framework\Runtime\RoadRunnerRuntime;
use Zef\Framework\Runtime\WorkerInterface;
use Zef\Middleware\ConfigProvider;

/**
 * @internal
 */
final class RuntimeSoakTest extends TestCase
{
    public const WARMUP_REQUESTS = 300;

    public const TOTAL_REQUESTS = 3300;

    /** @var int 512 KiB — kalibrasi lokal: 56 KiB untuk 3.000 request pasca-warmup */
    private const MAX_USED_GROWTH_BYTES = 524288;

    /** @var int 8 MiB — arena allocator (granularitas chunk 2 MiB); kalibrasi lokal: +2 MiB */
    private const MAX_REAL_GROWTH_BYTES = 8388608;

    /**
     * Layer umur-panjang (Infrastructure + Adapters/Runtime + Middleware)
     * wajib bebas property static — vektor kebocoran klasik long-running
     * worker. Semua static yang ada hari ini adalah pure function (Env,
     * NamingRules, BlockingSleeper) tanpa state.
     *
     * @var list<string>
     */
    private const STATIC_FREE_DIRS = [
        'src/Infrastructure',
        'src/Adapters/Runtime',
        'src/Middleware',
    ];

    /**
     * Soak 3.300 request berurutan: exit code bersih, seluruh respons 200,
     * dan pertumbuhan memori pasca-warmup terikat.
     */
    public function testRoadRunnerSoakKeepsMemoryBounded(): void
    {
        SoakProbeMiddleware::reset();

        $app = new Application();
        $app->addProvider(new SoakStackProvider());
        $app->addProvider(new SoakRoutesProvider());
        $app->boot();

        $worker = new SoakWorker();
        $runtime = new RoadRunnerRuntime(
            $app,
            $worker,
            self::TOTAL_REQUESTS,
            0,
            false,
        );

        $exit = $runtime->run();

        self::assertSame(0, $exit, 'soak wajib selesai dengan exit code 0');
        self::assertSame(self::TOTAL_REQUESTS, $runtime->handledRequests(), 'semua request wajib tertangani');
        self::assertSame(self::TOTAL_REQUESTS, $worker->responded, 'semua request wajib direspons');
        self::assertSame(0, $worker->nonOk, 'tidak ada respons non-200 selama soak');
        self::assertSame(0, $worker->errors, 'tidak ada error worker selama soak');

        $baselineUsed = SoakProbeMiddleware::$baselineUsed;
        $finalUsed = SoakProbeMiddleware::$finalUsed;
        $baselineReal = SoakProbeMiddleware::$baselineReal;
        $finalReal = SoakProbeMiddleware::$finalReal;
        self::assertNotNull($baselineUsed, 'snapshot memori warmup wajib terekam');
        self::assertNotNull($finalUsed, 'snapshot memori akhir wajib terekam');
        self::assertNotNull($baselineReal, 'snapshot arena warmup wajib terekam');
        self::assertNotNull($finalReal, 'snapshot arena akhir wajib terekam');

        $measured = self::TOTAL_REQUESTS - self::WARMUP_REQUESTS;
        $usedGrowth = $finalUsed - $baselineUsed;
        $realGrowth = $finalReal - $baselineReal;

        self::assertLessThanOrEqual(
            self::MAX_USED_GROWTH_BYTES,
            $usedGrowth,
            sprintf(
                'Kebocoran memori terdeteksi: +%d byte (%.2f KiB) setelah %d request pasca-warmup '
                . '(ambang %d byte, ~%.1f byte/request). Cari state yang terakumulasi per-request.',
                $usedGrowth,
                $usedGrowth / 1024,
                $measured,
                self::MAX_USED_GROWTH_BYTES,
                $usedGrowth / $measured,
            ),
        );
        self::assertLessThanOrEqual(
            self::MAX_REAL_GROWTH_BYTES,
            $realGrowth,
            sprintf(
                'Pertumbuhan arena allocator melebihi ambang: +%d byte (%.2f MiB) setelah %d request '
                . '(ambang %d byte) — indikasi buffer/arena yang tumbuh per-request.',
                $realGrowth,
                $realGrowth / 1048576,
                $measured,
                self::MAX_REAL_GROWTH_BYTES,
            ),
        );
    }

    public function testLongRunningLayersCarryNoStaticState(): void
    {
        $offenders = [];

        foreach (self::STATIC_FREE_DIRS as $dir) {
            $absolute = dirname(__DIR__, 2) . '/' . $dir;
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }
                $className = self::classNameIn((string) file_get_contents($file->getPathname()));
                if ($className === null || !class_exists($className)) {
                    continue;
                }

                $statics = [];
                foreach (new \ReflectionClass($className)->getProperties(\ReflectionProperty::IS_STATIC) as $prop) {
                    $statics[] = $prop->getName();
                }
                if ($statics !== []) {
                    $offenders[] = $className . '::$' . implode(', $', $statics);
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            'Property static baru di layer umur-panjang (lihat Guild Action Item 2): '
            . implode('; ', $offenders)
            . '. Pada worker RoadRunner long-running, static property adalah kebocoran memori '
            . 'menunggu kejadian — pindahkan ke service singleton, atau buka baseline ini '
            . 'secara sadar dengan justifikasi soak.',
        );
    }

    // ------------------------------------------------------------- Helpers

    private static function classNameIn(string $source): ?string
    {
        if (preg_match('/^namespace\s+([^;]+);/m', $source, $ns) !== 1) {
            return null;
        }
        if (preg_match('/^\s*(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/m', $source, $cl) !== 1) {
            return null;
        }
        $namespace = trim($ns[1]);

        return $namespace . '\\' . $cl[1];
    }
}

// ------------------------------------------------------------- Fixtures

final class SoakProbeMiddleware implements MiddlewareInterface
{
    public static int $handled = 0;

    public static ?int $baselineUsed = null;

    public static ?int $baselineReal = null;

    public static ?int $finalUsed = null;

    public static ?int $finalReal = null;

    public static function reset(): void
    {
        self::$handled = 0;
        self::$baselineUsed = null;
        self::$baselineReal = null;
        self::$finalUsed = null;
        self::$finalReal = null;
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        ++self::$handled;
        if (self::$handled === RuntimeSoakTest::WARMUP_REQUESTS) {
            \gc_collect_cycles();
            self::$baselineUsed = \memory_get_usage(false);
            self::$baselineReal = \memory_get_usage(true);
        }
        if (self::$handled === RuntimeSoakTest::TOTAL_REQUESTS) {
            \gc_collect_cycles();
            self::$finalUsed = \memory_get_usage(false);
            self::$finalReal = \memory_get_usage(true);
        }

        return $handler->handle($request);
    }
}

/**
 * Stack produksi (ConfigProvider asli) + probe di ujung — soak melewati
 * jalur middleware yang dipakai aplikasi nyata, bukan stack tiruan.
 */
final class SoakStackProvider implements ConfigProviderInterface
{
    #[\Override]
    public function getModuleName(): string
    {
        return 'middleware';
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function getConfig(): array
    {
        /** @var array<string, mixed> $base */
        $base = new ConfigProvider()->getConfig();
        $services = $base['services'] ?? null;
        $stack = $base['stack'] ?? null;
        if (!\is_array($services) || !\is_array($stack)) {
            throw new \LogicException('Production middleware config must expose services+stack.');
        }

        $services['soak.probe.mw'] = [
            'factory' => static fn (): SoakProbeMiddleware => new SoakProbeMiddleware(),
            'deps' => [],
        ];
        $stack[] = 'soak.probe.mw';
        $base['services'] = $services;
        $base['stack'] = $stack;

        return $base;
    }
}

final class SoakRoutesProvider implements ConfigProviderInterface
{
    #[\Override]
    public function getModuleName(): string
    {
        return 'soak';
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function getConfig(): array
    {
        return [
            'routes' => [
                ['method' => 'GET', 'path' => '/soak/a', 'handler' => 'soak.handler.ok', 'priority' => 10],
                ['method' => 'GET', 'path' => '/soak/b', 'handler' => 'soak.handler.ok', 'priority' => 10],
                ['method' => 'GET', 'path' => '/soak/c', 'handler' => 'soak.handler.ok', 'priority' => 10],
            ],
            'services' => [
                'soak.handler.ok' => [
                    'factory' => static fn (): SoakOkHandler => new SoakOkHandler(),
                    'deps' => [],
                ],
            ],
        ];
    }
}

final class SoakOkHandler implements RequestHandlerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'text/plain'], 'soak-ok');
    }
}

/**
 * Worker soak: request selalu tersedia (loop hanya berhenti via maxJobs),
 * respons divalidasi lalu DIBUANG — tidak ada akumulasi di sisi worker,
 * meniru worker RoadRunner asli yang mengirim respons ke socket.
 */
final class SoakWorker implements WorkerInterface
{
    public int $responded = 0;

    public int $nonOk = 0;

    public int $errors = 0;

    private bool $running = true;

    private int $served = 0;

    #[\Override]
    public function waitRequest(): ServerRequestInterface
    {
        $paths = ['/soak/a', '/soak/b', '/soak/c'];
        $path = $paths[$this->served % 3];
        ++$this->served;

        return new ServerRequest('GET', new Uri('http://localhost' . $path, ['localhost']));
    }

    #[\Override]
    public function respond(ResponseInterface $response): void
    {
        ++$this->responded;
        if ($response->getStatusCode() !== 200) {
            ++$this->nonOk;
        }
    }

    #[\Override]
    public function error(string $message): void
    {
        ++$this->errors;
    }

    #[\Override]
    public function stop(): void
    {
        $this->running = false;
    }

    #[\Override]
    public function isRunning(): bool
    {
        return $this->running;
    }
}
