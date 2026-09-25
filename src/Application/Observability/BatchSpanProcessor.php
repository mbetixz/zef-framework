<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Application layer (in-process orchestration)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Observability;

use Zef\Framework\Foundation\Env;
use Zef\Framework\Foundation\EnvInterface;
use Zef\Framework\Runtime\SleeperInterface;
use Zef\Framework\Runtime\SystemSleeper;

final class BatchSpanProcessor
{
    /**
     * @var list<SpanData>
     */
    private array $queue = [];
    private bool $shutdown = false;

    public function __construct(
        private readonly SpanExporterInterface $exporter,
        private readonly int $maxQueueSize = 2048,
        private readonly int $batchSize = 256,
        private readonly SleeperInterface $sleeper = new SystemSleeper(),
        private readonly ?EnvInterface $env = null,
    ) {
        if ($maxQueueSize < 1) {
            throw new \InvalidArgumentException('maxQueueSize must be >= 1.');
        }
        if ($batchSize < 1) {
            throw new \InvalidArgumentException('batchSize must be >= 1.');
        }
    }

    public function onEnd(SpanData $span): void
    {
        if ($this->shutdown || count($this->queue) >= $this->maxQueueSize) {
            return;
        }
        $this->queue[] = $span;
    }

    public function flush(): void
    {
        if ($this->queue === [] || $this->shutdown) {
            return;
        }
        $policy = RetryBackoffPolicy::fromEnvironment();
        while ($this->queue !== []) {
            $batch = array_splice($this->queue, 0, min($this->batchSize, count($this->queue)));
            $retryIndex = 0;
            while (true) {
                try {
                    $this->exporter->export($batch);

                    break;
                } catch (\InvalidArgumentException) {
                    break;
                } catch (\Throwable) {
                    if (!$policy->shouldRetry($retryIndex)) {
                        break;
                    }
                    $sleep = $policy->delayMs($retryIndex);
                    if ($sleep > 0) {
                        $this->sleeper->sleepMilliseconds($sleep);
                    }
                    ++$retryIndex;
                }
            }
        }
    }

    public function shutdown(): void
    {
        if ($this->shutdown) {
            return;
        }
        $env = $this->env ?? new Env();
        $deadline = microtime(true) + $env->readInt('ZEF_OTEL_SHUTDOWN_DRAIN_MS', 2000, 0, 60000) / 1000;
        while ($this->queue !== [] && microtime(true) < $deadline) {
            $batch = array_splice($this->queue, 0, min($this->batchSize, count($this->queue)));
            for ($attempt = 0; $attempt < 3; ++$attempt) {
                try {
                    $this->exporter->export($batch);

                    break;
                } catch (\Throwable) {
                    if ($attempt === 2 || microtime(true) >= $deadline) {
                        break;
                    }
                    if (function_exists('usleep')) {
                        usleep(50000);
                    }
                }
            }
        }
        $this->shutdown = true;

        try {
            $this->exporter->shutdown();
        } catch (\Throwable) {
        }
        $this->queue = [];
    }

    public function isInMemoryExporter(): bool
    {
        return $this->exporter instanceof InMemorySpanExporter;
    }
}

// Immutable, bounded causal context for remote/distributed execution.
