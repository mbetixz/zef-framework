<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.16.0 — Infrastructure layer (outbound adapters).
 * ZEF Inspector: `bin/zef module:list` — prints every module registered in
 * the (already booted) ModuleRegistry with its concrete class. The
 * registry is injected from bin/zef AFTER boot; this class never boots
 * the application itself and never reaches up into the App layer.
 */

namespace Zef\Framework\Console\Inspector;

use Zef\Framework\Config\ModuleRegistry;
use Zef\Framework\Console\ConsoleIO;

final class ModuleLister
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly ConsoleIO $io,
    ) {}

    public function run(): int
    {
        $modules = $this->registry->modules();

        if ($modules === []) {
            $this->io->out('No modules registered.');

            return 0;
        }

        $this->io->out(sprintf('%-16s %s', 'MODULE', 'CLASS'));
        foreach ($modules as $module) {
            $this->io->out(sprintf('%-16s %s', $module->getName(), $module::class));
        }
        $this->io->out('');
        $this->io->out(sprintf('%d module(s)', count($modules)));

        return 0;
    }
}
