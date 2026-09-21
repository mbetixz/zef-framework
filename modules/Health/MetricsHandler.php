<?php

declare(strict_types=1);

/*
 * ZEF Framework — Demo modules
 * Added in the v2.8.0 roadmap continuation (Prometheus metrics endpoint).
 */

namespace Zef\Module\Health;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zef\Framework\Http\Response;
use Zef\Framework\Observability\PrometheusRenderer;
use Zef\Framework\Observability\Telemetry;

final class MetricsHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Telemetry $telemetry,
        private readonly PrometheusRenderer $renderer,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->renderer->render($this->telemetry->meter(), ['module' => 'health']);

        return new Response(
            200,
            [
                'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
                'Cache-Control' => 'no-store',
            ],
            $body,
        );
    }
}
