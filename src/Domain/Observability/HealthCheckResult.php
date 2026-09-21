<?php

declare(strict_types=1);

/*
 * ZEF Framework — Domain layer (ports, contracts, value objects)
 * Added in the v2.8.0 roadmap continuation (additive, no behavioural changes).
 */

namespace Zef\Framework\Observability;

/**
 * Outcome of a single health indicator check.
 */
final readonly class HealthCheckResult
{
    public const string UP = 'up';
    public const string DOWN = 'down';

    public function __construct(
        public bool $healthy,
        public string $message = '',
    ) {
        if (strlen($this->message) > 512) {
            throw new \InvalidArgumentException('Health check message must be <= 512 bytes.');
        }
    }

    public static function up(string $message = ''): self
    {
        return new self(true, $message);
    }

    public static function down(string $message = ''): self
    {
        return new self(false, $message);
    }

    public function status(): string
    {
        return $this->healthy ? self::UP : self::DOWN;
    }
}
