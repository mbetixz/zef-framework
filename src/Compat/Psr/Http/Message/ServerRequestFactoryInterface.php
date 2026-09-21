<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Compat layer (PSR conditional shims — zero-composer fallback)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Psr\Http\Message;

if (!interface_exists(ServerRequestFactoryInterface::class)) {
    interface ServerRequestFactoryInterface
    {
        public function createServerRequest(string $method, $uri, array $serverParams = []): ServerRequestInterface;
    }
}
