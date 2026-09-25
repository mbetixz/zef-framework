<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.16.0 — Infrastructure layer (outbound adapters).
 * ZEF Maker: transactional file writer. A scaffold is all-or-nothing:
 * every target is checked for collisions BEFORE anything touches the disk,
 * so a failed generator never leaves a half-written module behind.
 */

namespace Zef\Framework\Console;

final readonly class ScaffoldWriter
{
    public function __construct(private ConsoleIO $io) {}

    public function writeFile(string $path, string $contents): void
    {
        $this->writeFiles([$path => $contents]);
    }

    /**
     * Write every file atomically: collision-check all targets first, then
     * create directories and write. Any collision or IO failure aborts the
     * whole batch before/at the first offending path.
     *
     * @param array<string,string> $files absolute path => file contents
     */
    public function writeFiles(array $files): void
    {
        $existing = [];
        foreach (array_keys($files) as $path) {
            if (is_file($path)) {
                $existing[] = $path;
            }
        }

        if ($existing !== []) {
            throw new ScaffoldCollisionException(
                'Refusing to overwrite existing file: ' . implode(', ', $existing),
            );
        }

        foreach ($files as $path => $contents) {
            $dir = dirname($path);
            // Race-safe mkdir guard; the `||`-variant is behaviourally identical
            // here (dir either exists or the throw fires). @infection-ignore-all
            if (!is_dir($dir) && !@mkdir($dir, 0o777, true) && !is_dir($dir)) {
                throw new ScaffoldWriteException("Cannot create directory: {$dir}");
            }
            if (@file_put_contents($path, $contents) === false) {
                throw new ScaffoldWriteException("Cannot write file: {$path}");
            }
            $this->io->out("Created {$path}");
        }
    }
}
