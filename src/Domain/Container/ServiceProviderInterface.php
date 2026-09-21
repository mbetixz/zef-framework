<?php

declare(strict_types=1);

/*
 * ZEF Framework — Domain layer (ports, contracts, value objects)
 * Added in the v2.10.0 roadmap continuation (additive, no behavioural changes).
 */

namespace Zef\Framework\Container;

/**
 * Service provider port (composition-time module wiring).
 *
 * Semantics:
 *   - register(): push definitions/aliases into the container. Eager providers
 *     run at registerProvider() time; deferred providers run lazily the first
 *     time get() asks for one of the IDs returned by provides().
 *   - provides(): the service IDs this provider is able to register. Used to
 *     decide when a deferred provider must be triggered.
 *
 * Implementations MUST only compose definitions (no resolution inside
 * register()); runtime wiring belongs in BootableProviderInterface::boot().
 */
interface ServiceProviderInterface
{
    /** @return list<string> service IDs this provider can register */
    public function provides(): array;

    public function register(Container $container): void;
}

/**
 * Marker for deferred providers: register() is postponed until one of the
 * provides() IDs is actually requested via Container::get().
 */
interface DeferrableProviderInterface extends ServiceProviderInterface {}

/**
 * Optional boot hook, invoked once via Container::bootProviders() after all
 * registrations are in place (typically after validateAndFreeze()).
 */
interface BootableProviderInterface
{
    public function boot(Container $container): void;
}
