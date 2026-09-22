<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.16.0 — Infrastructure layer (outbound adapters).
 * ZEF Maker: scaffold a CQRS query + its handler inside an existing
 * module (Query/ subdirectory, wired for QueryBus::register()).
 */

namespace Zef\Framework\Console\Generator;

use Zef\Framework\Console\ConsoleIO;
use Zef\Framework\Console\GeneratorInterface;
use Zef\Framework\Console\NamingRules;
use Zef\Framework\Console\ScaffoldWriter;

final class QueryGenerator implements GeneratorInterface
{
    public function __construct(
        private readonly string $root,
        private readonly ConsoleIO $io,
        private readonly ScaffoldWriter $writer,
    ) {}

    public function generate(?string $rawName, array $argv = []): int
    {
        $name = NamingRules::className($rawName, 'query name');
        $module = 'core';
        foreach ($argv as $arg) {
            if (str_starts_with((string) $arg, '--module=')) {
                $module = NamingRules::moduleName(substr((string) $arg, 9));
            }
        }

        $moduleDir = HandlerGenerator::resolveModuleDir($this->root, $module);
        if ($moduleDir === null) {
            $this->io->err("Module '{$module}' does not exist (modules/{$module}). Create it first with make:module.");

            return 1;
        }

        $modulePascal = basename($moduleDir);
        $ns = "Zef\\Module\\{$modulePascal}\\Query";
        $snake = NamingRules::snake($name);
        $this->writer->writeFiles([
            "{$moduleDir}/Query/{$name}Query.php" => <<<PHP
                <?php

                declare(strict_types=1);

                /*
                 * ZEF Framework — CQRS query '{$module}.query.{$snake}' (scaffolded by bin/zef make:query).
                 */

                namespace {$ns};

                /**
                 * Immutable read-side input. Queries must never mutate state — they only
                 * describe what the caller wants to read back.
                 */
                final class {$name}Query
                {
                    public function __construct(
                        public readonly string \$id,
                    ) {
                    }
                }

                PHP,
            "{$moduleDir}/Query/{$name}QueryHandler.php" => <<<PHP
                <?php

                declare(strict_types=1);

                /*
                 * ZEF Framework — CQRS query handler (scaffolded by bin/zef make:query).
                 */

                namespace {$ns};

                use Zef\\Framework\\CQRS\\CqrsContext;
                use Zef\\Framework\\CQRS\\QueryHandlerInterface;

                final class {$name}QueryHandler implements QueryHandlerInterface
                {
                    #[\\Override]
                    public function __invoke(object \$query, CqrsContext \$context): mixed
                    {
                        // TODO: read from a repository/port and return the projection.
                        return null;
                    }
                }

                PHP,
        ]);
        $this->io->out(<<<TXT

            Next steps — wire it into modules/{$modulePascal}/ConfigProvider.php and the bus:

              'services' => [
                  ...
                  '{$module}.query.{$snake}' => [
                      'factory' => static fn(): {$name}QueryHandler => new {$name}QueryHandler(),
                      'deps'    => [],
                  ],
              ],

              // during boot (e.g. a module's register()):
              \$bus->register({$name}Query::class, '{$module}.query.{$snake}');

              // read anywhere:
              \$result = \$app->getQueryBus()->ask(new {$name}Query('id-1'));

            TXT);

        return 0;
    }
}
