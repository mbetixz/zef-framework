<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Demo modules
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Module\Health;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zef\Framework\Http\Response;

final class ReadyHandler implements RequestHandlerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(
            200,
            ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'],
            '{"status":"ready"}',
        );
    }
}
