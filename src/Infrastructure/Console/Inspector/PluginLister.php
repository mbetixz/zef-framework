<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.16.0 — Infrastructure layer (outbound adapters).
 * ZEF Inspector: `bin/zef plugin:list` — scans plugins/ on disk. Plugins
 * are plain ConfigProviders (the runtime does not tag them), so the
 * filesystem is the single source of truth for this listing.
 */

namespace Zef\Framework\Console\Inspector;

use Zef\Framework\Console\ConsoleIO;

final readonly class PluginLister
{
    public function __construct(
        private string $root,
        private ConsoleIO $io,
    ) {}

    public function run(): int
    {
        $base = "{$this->root}/plugins";
        $entries = [];
        if (is_dir($base)) {
            $scanned = scandir($base);
            if ($scanned !== false) {
                $entries = $scanned;
            }
        }

        $plugins = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $dir = "{$base}/{$entry}";
            if (!is_dir($dir)) {
                continue;
            }
            $files = [];
            $scannedFiles = scandir($dir);
            if ($scannedFiles !== false) {
                foreach ($scannedFiles as $file) {
                    if (str_ends_with($file, '.php')) {
                        $files[] = $file;
                    }
                }
            }
            // scandir() already returns entries sorted ascending (SCANDIR_SORT_ASCENDING).
            $plugins[$entry] = $files;
        }

        if ($plugins === []) {
            $this->io->out("No plugins found ({$base}).");

            return 0;
        }

        $this->io->out(sprintf('%-16s %s', 'PLUGIN', 'FILES'));
        foreach ($plugins as $name => $files) {
            $this->io->out(sprintf('%-16s %s', $name, $files === [] ? '-' : implode(', ', $files)));
        }
        $this->io->out('');
        $this->io->out(sprintf('%d plugin(s)', count($plugins)));

        return 0;
    }
}
