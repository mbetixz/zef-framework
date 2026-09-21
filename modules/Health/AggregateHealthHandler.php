<?php

declare(strict_types=1);

/*
 * ZEF Framework — Demo modules
 * Added in the v2.8.0 roadmap continuation (aggregate health endpoint).
 */

namespace Zef\Module\Health;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zef\Framework\Http\Response;
use Zef\Framework\Observability\HealthAggregator;

final class AggregateHealthHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly HealthAggregator $aggregator,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $summary = $this->aggregator->aggregate();
        $status = $summary['status'] === 'ok' ? 200 : 503;

        return new Response(
            $status,
            ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'],
            json_encode(
                $summary,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ),
        );
    }
}
