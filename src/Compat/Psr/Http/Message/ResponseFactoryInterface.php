<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Compat layer (PSR conditional shims — zero-composer fallback)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Psr\Http\Message;

if (!interface_exists(ResponseFactoryInterface::class)) {
    interface ResponseFactoryInterface
    {
        public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface;
    }
}
