<?php

declare(strict_types=1);

/*
 * ZEF Framework — Configuration System v2 (Domain layer: ports, contracts,
 * value objects). Added in v2.21.0 (multi-source application configuration:
 * PHP file sources, environment overlay, secrets provider, schema-validated
 * typed accessors with fail-fast startup).
 */

namespace Zef\Framework\Config;

use Zef\Framework\Exception\InvalidConfigurationException;

/**
 * Thrown at fail-fast startup when the merged configuration violates the
 * schema (or contains unresolvable secret references). Carries EVERY
 * violation so operators see the full report in one boot attempt.
 */
final class ConfigValidationException extends InvalidConfigurationException
{
    /**
     * @param list<ConfigViolation> $violations
     */
    public function __construct(private readonly array $violations)
    {
        parent::__construct($this->render($violations));
    }

    /**
     * @return list<ConfigViolation>
     */
    public function violations(): array
    {
        return $this->violations;
    }

    /**
     * @param list<ConfigViolation> $violations
     */
    private function render(array $violations): string
    {
        $lines = [];
        foreach ($violations as $violation) {
            $lines[] = ' - ' . $violation->__toString();
        }

        return 'Configuration validation failed with ' . count($violations) . " violation(s):\n"
            . implode("\n", $lines);
    }
}
