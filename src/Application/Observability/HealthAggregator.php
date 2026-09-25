<?php

declare(strict_types=1);

/*
 * ZEF Framework — Application layer (in-process orchestration)
 * Added in the v2.8.0 roadmap continuation (additive, no behavioural changes).
 */

namespace Zef\Framework\Observability;

/**
 * Runs registered health indicators and aggregates them into a single
 * status: "ok" when every probe passes, "degraded" when any fails.
 *
 * Indicator exceptions are contained: a crashing probe degrades to "down"
 * with a sanitized message instead of failing the whole request. Indicators
 * are typically collected through the TaggedServiceLocator using the
 * `health.indicator` tag.
 */
final readonly class HealthAggregator
{
    /** @param list<HealthIndicatorInterface> $indicators */
    public function __construct(
        private array $indicators = [],
    ) {}

    /** @return list<HealthIndicatorInterface> */
    public function indicators(): array
    {
        return $this->indicators;
    }

    /**
     * @return array{status:string, checks:list<array{name:string,status:string,message:string}>}
     */
    public function aggregate(): array
    {
        $checks = [];
        $status = 'ok';
        foreach ($this->indicators as $indicator) {
            $name = $indicator->name();
            if (!is_string($name) || $name === '') {
                $name = 'unnamed';
            }
            $name = substr(preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?? 'unnamed', 0, 64);

            try {
                $result = $indicator->check();
                $healthy = $result instanceof HealthCheckResult && $result->healthy;
                $message = $result instanceof HealthCheckResult ? $result->message : 'invalid check result';
            } catch (\Throwable $e) {
                $healthy = false;
                $message = 'probe failure: ' . substr($e::class, 0, 128);
            }
            if (!$healthy) {
                $status = 'degraded';
            }
            $checks[] = [
                'name' => $name,
                'status' => $healthy ? HealthCheckResult::UP : HealthCheckResult::DOWN,
                'message' => substr($message, 0, 256),
            ];
        }

        return ['status' => $status, 'checks' => $checks];
    }

    public function toJson(): string
    {
        return json_encode(
            $this->aggregate(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }
}
