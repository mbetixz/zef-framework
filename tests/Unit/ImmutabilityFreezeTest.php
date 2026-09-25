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
     * Baseline beku — TERMINAL (Guild Action Item 3 selesai via final sweep):
     * seluruh 55 kelas immutable kini `readonly class`; snapshot dikosongkan.
     * Kelas final non-exception baru yang seluruh property instance-nya
     * readonly TANPA modifier `readonly` pada kelas akan gagal test ini
     * secara default — tambahkan `readonly` atau justifikasi eksplisit di sini.
     */
    private const array IMMUTABLE_WITHOUT_READONLY = [];

    /**
     * Set kelas immutable-tanpa-readonly wajib identik dengan baseline
     * (sekarang kosong — terminal state). Kelas BARU yang immutable tanpa
     * keyword readonly (pola DTO/VO baru) akan gagal di sini — tambahkan
     * `readonly` atau justifikasi baseline.
     */
    public function testImmutableClassesWithoutReadonlyKeywordAreFrozen(): void
    {
        self::assertSame(
            self::IMMUTABLE_WITHOUT_READONLY,
            $this->scanImmutableWithoutReadonly(),
            'Set kelas immutable-tanpa-readonly berubah (Guild Action Item 3). '
            . 'Kelas baru yang seluruh property-nya readonly WAJIB diberi keyword `readonly class` '
            . '— ReadOnlyClassRector sengaja tidak akan melakukannya. '
            . 'Jika non-readonly adalah keputusan sadar, tambahkan ke baseline dengan justifikasi.',
        );
    }

    /**
     * Kelas yang sudah `readonly class` tidak boleh mundur ke non-readonly
     * (regresi kebijakan) — jumlahnya wajib tidak menurun. Nilai terminal
     * 132 = 55 kelas ledger Guild Action Item 3 + kelas yang mengeras
     * sepanjang jalur zone-by-zone (#59, #64, #72, #78, final sweep).
     */
    public function testReadonlyClassCountDoesNotRegress(): void
    {
        $current = $this->countReadonlyClasses();
        self::assertGreaterThanOrEqual(
            132,
            $current,
            sprintf('Jumlah kelas `readonly` menurun (%d < 132) — regresi kebijakan immutability.', $current),
        );
    }

    // ------------------------------------------------------------- Helpers

    /** @return list<string> path relatif repo (src/...) terurut */
    private function scanImmutableWithoutReadonly(): array
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
            $className = $this->classNameIn($source);
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
            $allReadonly = array_all($instanceProps, fn ($prop): bool => $prop->isReadOnly());
            if ($allReadonly) {
                $out[] = 'src/' . $relative;
            }
        }

        sort($out);

        return $out;
    }

    private function countReadonlyClasses(): int
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
            $className = $this->classNameIn($source);
            if ($className === null || !class_exists($className)) {
                continue;
            }
            if (new \ReflectionClass($className)->isReadOnly()) {
                ++$count;
            }
        }

        return $count;
    }

    private function classNameIn(string $source): ?string
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
