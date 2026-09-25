<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.16.0 — Infrastructure layer (outbound adapters).
 * ZEF Maker: command catalog + dispatcher for every generator. `bin/zef`
 * stays a thin composition root; all maker logic lives here so it is
 * unit-tested and inside the mutation-testing gate.
 */

namespace Zef\Framework\Console;

use Zef\Framework\Console\Generator\CommandGenerator;
use Zef\Framework\Console\Generator\ConfigGenerator;
use Zef\Framework\Console\Generator\EntityGenerator;
use Zef\Framework\Console\Generator\HandlerGenerator;
use Zef\Framework\Console\Generator\MiddlewareGenerator;
use Zef\Framework\Console\Generator\ModuleGenerator;
use Zef\Framework\Console\Generator\PluginGenerator;
use Zef\Framework\Console\Generator\QueryGenerator;
use Zef\Framework\Console\Generator\ServiceGenerator;
use Zef\Framework\Console\Generator\ValueObjectGenerator;

final readonly class ZefMaker
{
    public function __construct(
        private string $root,
        private ConsoleIO $io,
    ) {}

    /**
     * The single source of truth for the `zef list` output and for the
     * unknown-generator error message.
     *
     * @return array<string,array{usage:string,desc:string}>
     */
    public function catalog(): array
    {
        return [
            'make:module' => [
                'usage' => 'make:module <name>',
                'desc' => 'Scaffold modules/<Pascal>/ (ConfigProvider + HomeHandler).',
            ],
            'make:plugin' => [
                'usage' => 'make:plugin <Name>',
                'desc' => 'Scaffold plugins/<Name>/ (ConfigProvider + Service + Handler).',
            ],
            'make:handler' => [
                'usage' => 'make:handler <Name> [--module=<name>] [--path=/uri]',
                'desc' => 'Scaffold a PSR-15 handler inside a module.',
            ],
            'make:middleware' => [
                'usage' => 'make:middleware <Name>',
                'desc' => 'Scaffold a src/Middleware PSR-15 middleware.',
            ],
            'make:config' => [
                'usage' => 'make:config <Name> [--module=<name>]',
                'desc' => 'Scaffold a module ConfigProvider (the framework config mechanism).',
            ],
            'make:command' => [
                'usage' => 'make:command <Name> [--module=<name>]',
                'desc' => 'Scaffold a CQRS command + CommandHandlerInterface handler.',
            ],
            'make:query' => [
                'usage' => 'make:query <Name> [--module=<name>]',
                'desc' => 'Scaffold a CQRS query + QueryHandlerInterface handler.',
            ],
            'make:entity' => [
                'usage' => 'make:entity <Name> [--module=<name>]',
                'desc' => 'Scaffold a Domain entity with identity + equals().',
            ],
            'make:valueobject' => [
                'usage' => 'make:valueobject <Name> [--module=<name>]',
                'desc' => 'Scaffold a final readonly value object with validation.',
            ],
            'make:service' => [
                'usage' => 'make:service <Name> [--module=<name>]',
                'desc' => 'Scaffold an application service + wiring snippet.',
            ],
        ];
    }

    public function listCommands(bool $asJson): int
    {
        $catalog = $this->catalog();

        if ($asJson) {
            $this->io->out(json_encode($catalog, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return 0;
        }

        $this->io->out('ZEF maker commands:');
        foreach ($catalog as $spec) {
            $this->io->out(sprintf('  %-58s %s', $spec['usage'], $spec['desc']));
        }
        $this->io->out('');
        $this->io->out(sprintf('%d command(s)', count($catalog)));

        return 0;
    }

    /**
     * @param list<string> $args full argv (index 0 = program, 1 = command)
     */
    public function run(array $args): int
    {
        $command = $args[1] ?? null;

        if ($command === 'list') {
            return $this->listCommands(in_array('--json', $args, true));
        }

        if ($command !== null && str_starts_with($command, 'make:')) {
            return $this->dispatch($command, array_slice($args, 2));
        }

        $this->io->err("Unknown command '" . ($command ?? '') . "'. Run 'bin/zef list' for all commands.");

        return 1;
    }

    /** @param list<string> $argv generator arguments (no command word) */
    private function dispatch(string $command, array $argv): int
    {
        $generator = $this->resolve($command);
        if (!$generator instanceof GeneratorInterface) {
            $this->io->err(
                "Unknown generator '{$command}'. Supported: " . implode(', ', array_keys($this->catalog())) . '.',
            );

            return 1;
        }

        try {
            return $generator->generate($argv[0] ?? null, $argv);
        } catch (ConsoleException $e) {
            $this->io->err($e->getMessage());

            return 1;
        }
    }

    private function resolve(string $command): ?GeneratorInterface
    {
        $root = $this->root;
        $io = $this->io;
        $writer = new ScaffoldWriter($io);

        return match ($command) {
            'make:module' => new ModuleGenerator($root, $io, $writer),
            'make:plugin' => new PluginGenerator($root, $io, $writer),
            'make:handler' => new HandlerGenerator($root, $io, $writer),
            'make:middleware' => new MiddlewareGenerator($root, $io, $writer),
            'make:config' => new ConfigGenerator($root, $io, $writer),
            'make:command' => new CommandGenerator($root, $io, $writer),
            'make:query' => new QueryGenerator($root, $io, $writer),
            'make:entity' => new EntityGenerator($root, $io, $writer),
            'make:valueobject' => new ValueObjectGenerator($root, $io, $writer),
            'make:service' => new ServiceGenerator($root, $io, $writer),
            default => null,
        };
    }
}
