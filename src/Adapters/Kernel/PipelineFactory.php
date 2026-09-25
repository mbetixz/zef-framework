<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Adapters layer (inbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zef\Framework\Config\ConfigAggregator;
use Zef\Framework\Container\Container;
use Zef\Framework\Exception\InvalidConfigurationException;

final readonly class PipelineFactory
{
    public function __construct(
        private Container $container,
        private ConfigAggregator $config,
        private RequestHandlerInterface $terminal,
    ) {}

    public function build(): MiddlewarePipeline
    {
        $entries = $this->config->get('middleware.stack', []);
        if (!is_array($entries)) {
            $entries = [];
        }
        $stack = [];
        $definitions = [];
        foreach ($entries as $entry) {
            try {
                $definitions[] = MiddlewareDefinition::fromLegacy($entry);
            } catch (\Throwable $e) {
                throw new InvalidConfigurationException($e->getMessage(), 0, $e);
            }
        }
        foreach ($definitions as $definition) {
            $id = $definition->serviceId;
            if (!$this->container->has($id)) {
                throw new InvalidConfigurationException("Middleware service '{$id}' is not registered.");
            }
            $mw = $this->container->get($id);
            if (!$mw instanceof MiddlewareInterface) {
                throw new InvalidConfigurationException("Service '{$id}' does not implement MiddlewareInterface.");
            }
            // Collect into a plain array and construct ONCE: each
            // withMiddleware() previously copied the whole stack (O(n²)
            // build time, 317ms at 5000 middlewares).
            $stack[] = $mw;
        }

        return new MiddlewarePipeline($stack, $this->terminal);
    }
}
