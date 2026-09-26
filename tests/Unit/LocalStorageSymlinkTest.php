<?php

declare(strict_types=1);

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Storage\LocalStorage;
use Zef\Framework\Storage\StorageException;

/**
 * @internal
 */
final class LocalStorageSymlinkTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/zef-storage-symlink-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/root/nested', 0o777, true);
        mkdir($this->base . '/outside', 0o777, true);
        file_put_contents($this->base . '/outside/object.txt', 'outside');
        file_put_contents($this->base . '/root/inside.txt', 'inside');
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }
            if ($item->isLink() || !$item->isDir()) {
                unlink($item->getPathname()); // nosemgrep: php.lang.security.unlink-use (test sandbox)
            } else {
                rmdir($item->getPathname());
            }
        }
        rmdir($this->base);
    }

    /**
     * @dataProvider symlinkOperations
     */
    public function testRejectsSymlinksWithoutTouchingTargets(string $operation, string $target, string $suffix): void
    {
        $storage = new LocalStorage($this->base . '/root');
        $this->link($this->base . '/' . $target, $this->base . '/root/nested/link');
        $key = 'nested/link' . $suffix;

        try {
            match ($operation) {
                'put' => $storage->put($key, 'changed'),
                'get' => $storage->get($key),
                'delete' => $storage->delete($key),
                'exists' => $storage->exists($key),
                'stat' => $storage->stat($key),
                default => self::fail('Unknown storage operation.'),
            };
            self::fail('An object path containing a symlink must be rejected.');
        } catch (StorageException $error) {
            self::assertSame("Storage key '{$key}' must not contain symbolic links.", $error->getMessage());
        }
        self::assertSame('outside', file_get_contents($this->base . '/outside/object.txt'));
        self::assertSame('inside', file_get_contents($this->base . '/root/inside.txt'));
        self::assertSame(['object.txt'], array_values(array_diff(scandir($this->base . '/outside'), ['.', '..'])));
        self::assertFileDoesNotExist($this->base . '/missing');
        self::assertTrue(is_link($this->base . '/root/nested/link'));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function symlinkOperations(): iterable
    {
        foreach (['get', 'put', 'delete', 'exists', 'stat'] as $operation) {
            foreach ([
                'outside directory' => ['outside', '/object.txt'],
                'missing object' => ['outside', '/new.txt'],
                'missing parents' => ['outside', '/new/deep/object.txt'],
                'outside file' => ['outside/object.txt', ''],
                'dangling file' => ['missing', ''],
                'dangling parent' => ['missing', '/object.txt'],
                'inside directory' => ['root', '/inside.txt'],
                'inside file' => ['root/inside.txt', ''],
            ] as $name => [$target, $suffix]) {
                yield $operation . ' ' . $name => [$operation, $target, $suffix];
            }
        }
    }

    public function testListingOmitsFileAndDirectorySymlinks(): void
    {
        $storage = new LocalStorage($this->base . '/root');
        $this->link($this->base . '/outside', $this->base . '/root/outside');
        $this->link($this->base . '/outside/object.txt', $this->base . '/root/nested/file');
        $this->link($this->base . '/root/inside.txt', $this->base . '/root/alias');
        $this->link($this->base . '/missing', $this->base . '/root/dangling');
        $this->link($this->base . '/root', $this->base . '/root/loop');
        $storage->put('nested/normal.txt', 'normal');

        self::assertSame(['inside.txt', 'nested/normal.txt'], $storage->list());
        self::assertSame(['nested/normal.txt'], $storage->list('nested/', 1));
        self::assertSame([], $storage->list('outside/'));
    }

    public function testDirectoryReplacedBetweenOperationsIsRejected(): void
    {
        $storage = new LocalStorage($this->base . '/root');
        $storage->put('nested/object.txt', 'original');
        self::assertSame('original', $storage->get('nested/object.txt'));
        rename($this->base . '/root/nested', $this->base . '/root/previous');
        $this->link($this->base . '/outside', $this->base . '/root/nested');

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage("Storage key 'nested/object.txt' must not contain symbolic links.");
        $storage->get('nested/object.txt');
    }

    public function testConfiguredRootSymlinkIsCanonicalised(): void
    {
        $this->link($this->base . '/root', $this->base . '/root-alias');
        $storage = new LocalStorage($this->base . '/root-alias');
        $storage->put('new/deep/object.txt', 'normal');
        self::assertSame('normal', $storage->get('new/deep/object.txt'));
        self::assertSame('normal', file_get_contents($this->base . '/root/new/deep/object.txt'));
    }

    private function link(string $target, string $path): void
    {
        if (!@symlink($target, $path)) {
            self::markTestSkipped('Creating symbolic links is not supported in this environment.');
        }
    }
}
