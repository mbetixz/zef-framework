<?php

declare(strict_types=1);

/*
 * ZEF Framework — Domain layer (ports, contracts, value objects)
 * Added in the issue #36 exit ramp (additive, no behavioural changes).
 */

namespace Zef\Framework\Container;

/**
 * Composition port (issue #36 exit ramp for the ContainerImpl carve-out):
 * the registrar surface a service provider may compose against.
 *
 * The provider contracts (ServiceProviderInterface and friends) type their
 * register()/boot() hooks against this port instead of the concrete
 * container, so the Domain layer keeps zero outward dependencies beyond
 * Compat and the concrete container resolves as an ordinary Application
 * class. The narrow surface also turns the long-standing convention
 * "providers compose definitions, they never resolve" into a type-level
 * guarantee: no get()/has() is reachable from the composition handle.
 *
 * The Application-layer container implements this port 1:1; the method
 * signatures mirror the public composition-time API exactly.
 */
interface ServiceRegistrarInterface
{
    /**
     * Push a factory definition plus its dependency IDs, optional module
     * scope and lifetime. Service IDs must be unique; duplicate IDs raise
     * an InvalidFactoryException at composition time.
     *
     * @param list<string> $deps service IDs the factory receives
     */
    public function register(
        string $id,
        callable $factory,
        array $deps = [],
        ?string $module = null,
        string $lifetime = ServiceLifetime::SINGLETON,
    ): void;

    /**
     * Register an alias resolving to another service ID. Aliases must not
     * collide with registered factories or existing aliases.
     */
    public function alias(string $alias, string $target, ?string $module = null): void;
}
