<?php

declare(strict_types=1);

// ZEF Framework v2.21.0 — Configuration System v2 test fixture: minimal
// in-memory source for cases where file plumbing is irrelevant.

namespace Zef\Test\Unit;

use Zef\Framework\Config\ConfigSourceInterface;

/**
 * @internal
 */
final class ConfigV2ArraySource implements ConfigSourceInterface
{
    /**
     * @param array<string,mixed> $values
     */
    public function __construct(private readonly array $values, private readonly string $id = 'array') {}

    #[\Override]
    public function name(): string
    {
        return $this->id;
    }

    #[\Override]
    public function load(): array
    {
        return $this->values;
    }
}
