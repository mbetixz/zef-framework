<?php

declare(strict_types=1);

/*
 * ZEF Framework — Application layer (in-process orchestration)
 * Added in the v2.10.0 roadmap continuation (additive, no behavioural changes).
 */

namespace Zef\Framework\Container;

/**
 * Fluent builder for contextual bindings:
 *
 *   $c->when(BillingService::class)->needs(PaymentGateway::class)->give('gateway.stripe');
 *
 * Implementation note: give() performs a pre-freeze REGISTRY REWRITE — the
 * consumer's definition is re-added with `$dep` substituted by the synthetic
 * ID `@contextual:<consumer>|<dep>`, which is aliased to the target. Because
 * the dependency graph now contains real edges, every existing guarantee
 * holds unchanged: missing targets fail DependencyGraphValidator compile with
 * ServiceNotFoundException, singleton closure and cycle detection still apply,
 * and cross-module budgets count against the actual target module.
 */
final class ContextualBindingBuilder
{
    private ?string $needed = null;

    public function __construct(
        private readonly Container $container,
        private readonly string $consumer,
    ) {}

    /** Declare which dependency ID of the consumer this binding replaces. */
    public function needs(string $dep): self
    {
        $this->needed = $dep;

        return $this;
    }

    /** Commit the binding: resolve `$dep` for `$consumer` through `$target`. */
    public function give(string $target): void
    {
        $dep = $this->needed
            ?? throw new \LogicException('Call needs() before give() on a contextual binding.');
        $this->container->addContextualBinding($this->consumer, $dep, $target);
    }

    public function consumer(): string
    {
        return $this->consumer;
    }
}
