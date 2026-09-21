<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Exception;

use Psr\Container\ContainerExceptionInterface;

final class ServiceResolutionException extends \RuntimeException implements ContainerExceptionInterface
{
    public function __construct(string $id, string $reason, ?\Throwable $previous = null)
    {
        parent::__construct("Cannot resolve service '{$id}': {$reason}", 0, $previous);
    }
}
