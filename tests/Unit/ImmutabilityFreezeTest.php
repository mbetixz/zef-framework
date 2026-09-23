<?php

declare(strict_types=1);

/*
 * Guild Action Item 3 — kebijakan readonly manual untuk DTO/ValueObject
 * (follow-up guard).
 *
 * ReadOnlyClassRector sengaja dikecualikan di rector.php (kelas framework
 * yang extensible memakai pola post-construction freezing), jadi keyword
 * `readonly` tidak akan pernah ditambahkan otomatis — risikonya: DTO/VO
 * baru masuk tanpa readonly dan tidak ada yang gagal.
 *
 * Tripwire ini membekukan baseline kelas yang SUDAH immutable (semua
 * property instance-nya readonly) tapi TIDAK ditandai `readonly class`.
 * Kelas baru yang immutable-tanpa-readonly akan muncul di hasil scan dan
 * membedakan snapshot — saat itu pengembang harus memilih sadar:
 *
 *   1. DTO / Value Object / kelas beku-setelah-konstruksi tanpa mutasi
 *      properti -> tambahkan keyword `readonly` (murah: tanpa perubahan
 *      perilaku) dan HAPUS dari baseline.
 *   2. Kelas sengaja non-readonly (mis. direncanakan jadi mutable, atau
 *      turunan/parent non-readonly) -> tambahkan ke baseline di bawah
 *      DENGAN komentar justifikasi.
 *
 * Catatan scope: exception di-skip secara struktural (parent \\Exception
 * mutable, `readonly class` tidak mungkin); kelas dengan property
 * non-readonly adalah service mutable by-design — di luar kebijakan ini.
 * Generator make:valueobject sudah menyusun `final readonly class`
 * (dikunci EdgeMatrixMakerGeneratorsTest) — file ini menjaga yang
 * hand-written.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ImmutabilityFreezeTest extends TestCase
{
    /**
     * Baseline beku: kelas final non-exception yang seluruh property
     * instance-nya readonly, tanpa modifier `readonly` pada kelas.
     */
    private const IMMUTABLE_WITHOUT_READONLY = [
        'src/Adapters/Kernel/Dispatcher.php',
        'src/Adapters/Kernel/MiddlewarePipeline.php',
        'src/Adapters/Kernel/ModuleBootstrapper.php',
        'src/Adapters/Kernel/PipelineFactory.php',
        'src/Adapters/Router/UrlGenerator.php',
        'src/Adapters/Runtime/RoadRunnerWorkerAdapter.php',
        'src/Adapters/Security/AuthenticationMiddleware.php',
        'src/Adapters/Security/SecurityRuntimeMiddleware.php',
        'src/Application/Container/ContainerCompiler.php',
        'src/Application/Container/RadixTreeCompilerPass.php',
        'src/Application/Container/ServiceRegistrar.php',
        'src/Application/Container/ServiceRegistryView.php',
        'src/Application/Message/DeduplicatingMiddleware.php',
        'src/Application/Observability/HealthAggregator.php',
        'src/Application/Observability/TelemetryLogger.php',
        'src/Application/Observability/Tracer.php',
        'src/Application/Security/CsrfTokenManager.php',
        'src/Application/Security/Distributed/AllowScopeAuthorizationPolicy.php',
        'src/Application/Security/Distributed/StaticCredentialProvider.php',
        'src/Domain/Autowiring/Inject.php',
        'src/Domain/Autowiring/Target.php',
        'src/Domain/Autowiring/Value.php',
        'src/Domain/Config/ModuleContext.php',
        'src/Domain/Security/Totp.php',
        'src/Domain/Validation/TrustedHostValidator.php',
        'src/Infrastructure/Cache/InMemoryCache.php',
        'src/Infrastructure/Cache/TaggableCache.php',
        'src/Infrastructure/Cache/TieredCache.php',
        'src/Infrastructure/Config/ConfigProviderModule.php',
        'src/Infrastructure/Config/ModuleConfigProvider.php',
        'src/Infrastructure/Console/Generator/CommandGenerator.php',
        'src/Infrastructure/Console/Generator/ConfigGenerator.php',
        'src/Infrastructure/Console/Generator/EntityGenerator.php',
        'src/Infrastructure/Console/Generator/HandlerGenerator.php',
        'src/Infrastructure/Console/Generator/MiddlewareGenerator.php',
        'src/Infrastructure/Console/Generator/ModuleGenerator.php',
        'src/Infrastructure/Console/Generator/PluginGenerator.php',
        'src/Infrastructure/Console/Generator/QueryGenerator.php',
        'src/Infrastructure/Console/Generator/ServiceGenerator.php',
        'src/Infrastructure/Console/Generator/ValueObjectGenerator.php',
        'src/Infrastructure/Console/Inspector/ConfigShower.php',
        'src/Infrastructure/Console/Inspector/ModuleLister.php',
        'src/Infrastructure/Console/Inspector/PluginLister.php',
        'src/Infrastructure/Console/ScaffoldWriter.php',
        'src/Infrastructure/Console/ZefMaker.php',
        'src/Infrastructure/Security/AesGcmEncryptor.php',
        'src/Infrastructure/Security/ApcuRateLimiter.php',
        'src/Infrastructure/Security/RedisRateLimiter.php',
        'src/Infrastructure/Security/RedisSharedRateLimitStore.php',
        'src/Middleware/ConfigProvider.php',
        'src/Middleware/CorsMiddleware.php',
        'src/Middleware/ErrorLogger.php',
        'src/Middleware/ErrorResponseFactory.php',
        'src/Middleware/GlobalErrorHandler.php',
        'src/Middleware/SecurityHeadersMiddleware.php',
    ];

    /**
     * Set kelas immutable-tanpa-readonly wajib identik dengan baseline.
     * Kelas BARU yang immutable tanpa keyword readonly (pola DTO/VO baru)
     * akan gagal di sini — tambahkan `readonly` atau justifikasi baseline.
     */
    public function testImmutableClassesWithoutReadonlyKeywordAreFrozen(): void
    {
        self::assertSame(
            self::IMMUTABLE_WITHOUT_READONLY,
            self::scanImmutableWithoutReadonly(),
            'Set kelas immutable-tanpa-readonly berubah (Guild Action Item 3). '
            . 'Kelas baru yang seluruh property-nya readonly WAJIB diberi keyword `readonly class` '
            . '— ReadOnlyClassRector sengaja tidak akan melakukannya. '
            . 'Jika non-readonly adalah keputusan sadar, tambahkan ke baseline dengan justifikasi.',
        );
    }

    /**
     * Kelas yang sudah `readonly class` tidak boleh mundur ke non-readonly
     * (regresi kebijakan) — jumlahnya wajib tidak menurun.
     */
    public function testReadonlyClassCountDoesNotRegress(): void
    {
        $current = self::countReadonlyClasses();
        self::assertGreaterThanOrEqual(
            63,
            $current,
            sprintf('Jumlah kelas `readonly` menurun (%d < 63) — regresi kebijakan immutability.', $current),
        );
    }

    // ------------------------------------------------------------- Helpers

    /** @return list<string> path relatif repo (src/...) terurut */
    private static function scanImmutableWithoutReadonly(): array
    {
        $root = dirname(__DIR__, 2) . '/src/';
        $out = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = str_replace($root, '', $file->getPathname());
            if (str_starts_with($relative, 'Compat/')) {
                continue; // PSR shim — artefak byte-stable, di luar kebijakan
            }

            $source = (string) file_get_contents($file->getPathname());
            $className = self::classNameIn($source);
            if ($className === null || !class_exists($className)) {
                continue;
            }

            $reflection = new \ReflectionClass($className);
            if (!$reflection->isFinal() || $reflection->isReadOnly()) {
                continue;
            }
            if ($reflection->isSubclassOf('Exception') || $reflection->isSubclassOf('Error')) {
                continue;
            }

            $instanceProps = [];
            foreach ($reflection->getProperties() as $prop) {
                if (!$prop->isStatic()) {
                    $instanceProps[] = $prop;
                }
            }
            if ($instanceProps === []) {
                continue;
            }

            $allReadonly = true;
            foreach ($instanceProps as $prop) {
                if (!$prop->isReadOnly()) {
                    $allReadonly = false;

                    break;
                }
            }
            if ($allReadonly) {
                $out[] = 'src/' . $relative;
            }
        }

        sort($out);

        return $out;
    }

    private static function countReadonlyClasses(): int
    {
        $count = 0;
        $root = dirname(__DIR__, 2) . '/src/';

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = str_replace($root, '', $file->getPathname());
            if (str_starts_with($relative, 'Compat/')) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            $className = self::classNameIn($source);
            if ($className === null || !class_exists($className)) {
                continue;
            }
            if (new \ReflectionClass($className)->isReadOnly()) {
                ++$count;
            }
        }

        return $count;
    }

    private static function classNameIn(string $source): ?string
    {
        if (preg_match('/^namespace\s+([^;]+);/m', $source, $ns) !== 1) {
            return null;
        }
        if (preg_match('/^\s*(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/m', $source, $cl) !== 1) {
            return null;
        }

        return trim($ns[1]) . '\\' . $cl[1];
    }
}
