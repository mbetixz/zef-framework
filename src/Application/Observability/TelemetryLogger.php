<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Application layer (in-process orchestration)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Observability;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class TelemetryLogger
{
    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?Telemetry $telemetry = null,
    ) {}

    /** @param array<string,mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->write('WARN', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    /** @param array<string,mixed> $context */
    private function write(string $severity, string $message, array $context): void
    {
        $clean = $this->sanitizeContext($context);
        match ($severity) {
            'ERROR' => $this->logger->error($message, $clean),
            'WARN' => $this->logger->warning($message, $clean),
            default => $this->logger->info($message, $clean),
        };
        $this->telemetry?->recordLog($severity, $message, $clean);
    }

    /**
     * @param array<string,mixed> $context
     *
     * @return array<string,mixed>
     */
    private function sanitizeContext(array $context): array
    {
        $result = [];
        foreach ($context as $key => $value) {
            if (TelemetrySanitizer::isSensitiveKey($key)) {
                $result[$key] = '[REDACTED]';

                continue;
            }
            $result[$key] = is_object($value) && !$value instanceof \Stringable
                ? $value::class
                : TelemetrySanitizer::value($value);
        }

        return $result;
    }
}
