<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Infrastructure layer (outbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Config;

final class EnvironmentSecretProvider implements SecretProviderInterface
{
    #[\Override]
    public function get(string $name): ?SecretValue
    {
        if (preg_match('/^[A-Z][A-Z0-9_]{0,127}$/', $name) !== 1) {
            throw new \InvalidArgumentException('Invalid secret name.');
        }
        $value = getenv($name);

        return $value === false ? null : new SecretValue($value);
    }
}
