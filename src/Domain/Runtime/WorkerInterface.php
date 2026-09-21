<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Runtime;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

interface WorkerInterface
{
    public function waitRequest(): ?ServerRequestInterface;

    public function respond(ResponseInterface $response): void;

    public function error(string $message): void;

    public function stop(): void;

    public function isRunning(): bool;
}
