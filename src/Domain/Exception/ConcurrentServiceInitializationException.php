<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Exception;

use Psr\Container\ContainerExceptionInterface;

final class ConcurrentServiceInitializationException extends \RuntimeException implements ContainerExceptionInterface
{
    public function __construct(public readonly string $serviceId)
    {
        parent::__construct("Concurrent initialization detected for service '{$serviceId}'.");
    }
}
