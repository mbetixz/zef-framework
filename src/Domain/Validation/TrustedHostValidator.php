<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Validation;

final class TrustedHostValidator
{
    public function __construct(private readonly array $trustedHosts = []) {}

    public function assert(string $host): void
    {
        if ($host === '' || $this->trustedHosts === []) {
            return;
        }
        $normalize = static function (string $value): string {
            $value = strtolower(trim($value));
            if (strlen($value) >= 2 && $value[0] === '[' && $value[strlen($value) - 1] === ']') {
                return substr($value, 1, -1);
            }

            return $value;
        };
        $normalized = $normalize($host);
        foreach ($this->trustedHosts as $allowed) {
            if ($normalized === $normalize((string) $allowed)) {
                return;
            }
        }

        throw new \InvalidArgumentException("Untrusted host: {$host}.");
    }
}
