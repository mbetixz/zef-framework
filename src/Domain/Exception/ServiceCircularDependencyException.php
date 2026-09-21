<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Exception;

use Psr\Container\ContainerExceptionInterface;

final class ServiceCircularDependencyException extends \RuntimeException implements ContainerExceptionInterface
{
    public function __construct(public readonly array $chain)
    {
        parent::__construct('Circular service dependency detected: ' . implode(' -> ', $chain));
    }

    public function getChain(): array
    {
        return $this->chain;
    }
}
