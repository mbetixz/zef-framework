<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Demo modules
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Module\Core;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zef\Framework\Foundation\ZefVersion;
use Zef\Framework\Http\Response;

final class HomeHandler implements RequestHandlerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode(
                [
                    'module' => 'core',
                    'page' => 'home',
                    'framework' => 'ZEF Framework v' . ZefVersion::VERSION,
                    'psr' => [
                        'container' => true,
                        'http-message' => true,
                        'http-server-handler' => true,
                        'http-server-middleware' => true,
                        'http-factory' => true,
                    ],
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
    }
}
