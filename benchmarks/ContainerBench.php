<?php

/**
 * ZEF Framework — Container benchmark (composer bench).
 *
 * Measures the two steady-state resolution paths of the frozen container:
 *
 *  - benchGetSingleton: frozen fast-path lookup of an already-built
 *    singleton (validateAndFreeze() + first build amortized away).
 *  - benchGetTransient: full resolve-and-build cycle per call through a
 *    transient lifetime, including one dependency hop.
 */

declare(strict_types=1);

namespace Zef\Bench;

use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use Zef\Framework\Container\Container;
use Zef\Framework\Container\ServiceLifetime;

final class ContainerBench
{
    private Container $container;

    private Container $transient;

    public function __construct()
    {
        $this->container = new Container();
        for ($i = 0; $i < 50; $i++) {
            $this->container->register("svc.{$i}", static fn () => new \stdClass(), [], 'bench');
        }
        $this->container->register(
            'app.entry',
            static fn ($ctx, \stdClass $d) => new \stdClass(),
            ['svc.0'],
            'bench',
        );
        $this->container->validateAndFreeze();
        // Warm the singleton so the benchmark measures the frozen
        // fast-path, not the initial build.
        $this->container->get('app.entry');

        $this->transient = new Container();
        $this->transient->register(
            'leaf',
            static fn () => new \stdClass(),
            [],
            'bench',
            ServiceLifetime::TRANSIENT,
        );
        $this->transient->register(
            'entry',
            static fn ($ctx, \stdClass $d) => new \stdClass(),
            ['leaf'],
            'bench',
            ServiceLifetime::TRANSIENT,
        );
        $this->transient->validateAndFreeze();
    }

    #[Revs(10000)]
    #[Iterations(5)]
    public function benchGetSingleton(): void
    {
        $this->container->get('app.entry');
    }

    #[Revs(1000)]
    #[Iterations(5)]
    public function benchGetTransient(): void
    {
        $this->transient->get('entry');
    }
}
