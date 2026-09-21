<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Config;

final readonly class SecretValue implements \Stringable
{
    public function __construct(private string $value)
    {
        if ($value === '' || strlen($value) > 4096) {
            throw new \InvalidArgumentException('Secret value is empty or exceeds the 4096-byte limit.');
        }
    }

    #[\Override]
    public function __toString(): string
    {
        return '[REDACTED]';
    }

    public function reveal(): string
    {
        return $this->value;
    }
}
