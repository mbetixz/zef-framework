<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Compat layer (PSR conditional shims — zero-composer fallback)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Psr\Http\Server;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

if (!interface_exists(RequestHandlerInterface::class)) {
    interface RequestHandlerInterface
    {
        public function handle(ServerRequestInterface $request): ResponseInterface;
    }
}
