<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Adapters layer (inbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zef\Framework\Http\Response;

final readonly class MiddlewarePipeline implements RequestHandlerInterface
{
    /**
     * @param list<MiddlewareInterface> $stack
     */
    public function __construct(
        private array $stack = [],
        private ?RequestHandlerInterface $terminal = null,
        private int $index = 0,
    ) {}

    public function withMiddleware(MiddlewareInterface $middleware): self
    {
        $s = $this->stack;
        $s[] = $middleware;

        return new self($s, $this->terminal, $this->index);
    }

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->index >= count($this->stack)) {
            if (!$this->terminal instanceof RequestHandlerInterface) {
                return new Response(500, ['Content-Type' => 'text/plain'], 'Pipeline terminal missing.');
            }

            return $this->terminal->handle($request);
        }
        $next = new self($this->stack, $this->terminal, $this->index + 1);

        return $this->stack[$this->index]->process($request, $next);
    }
}
