<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Application layer (in-process orchestration)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Observability;

final class Span implements SpanInterface
{
    private bool $ended = false;
    private int $endNs = 0;
    private string $status = 'UNSET';
    private ?string $statusDescription = null;

    /**
     * @var array<string,mixed>
     */
    private array $attributes = [];

    /**
     * @var list<array{name:string,time_unix_nano:int,attributes:array<string,mixed>}>
     */
    private array $events = [];

    /** @param array<string,mixed> $attributes */
    public function __construct(
        private readonly string $name,
        private readonly SpanContext $context,
        private readonly ?SpanContext $parent,
        private readonly int $startNs,
        private readonly int $startUnixNano,
        private readonly \Closure $onEnd,
        array $attributes = [],
    ) {
        $this->setAttributes($attributes);
    }

    #[\Override]
    public function getContext(): SpanContext
    {
        return $this->context;
    }

    #[\Override]
    public function setAttribute(string $key, mixed $value): self
    {
        if (!$this->ended && $key !== '' && !TelemetrySanitizer::isSensitiveKey($key)) {
            $this->attributes[$key] = TelemetrySanitizer::value($value);
        }

        return $this;
    }

    #[\Override]
    public function setAttributes(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
        }

        return $this;
    }

    #[\Override]
    public function addEvent(string $name, array $attributes = []): self
    {
        if ($this->ended || $name === '') {
            return $this;
        }
        $clean = [];
        foreach ($attributes as $key => $value) {
            if (!TelemetrySanitizer::isSensitiveKey($key)) {
                $clean[$key] = TelemetrySanitizer::value($value);
            }
        }
        $this->events[] = [
            'name' => $name,
            'time_unix_nano' => TelemetryClock::nowUnixNano(),
            'attributes' => $clean,
        ];

        return $this;
    }

    #[\Override]
    public function setStatus(string $status, ?string $description = null): self
    {
        if (!$this->ended) {
            $status = strtoupper($status);
            if (!in_array($status, ['UNSET', 'OK', 'ERROR'], true)) {
                throw new \InvalidArgumentException('Invalid span status.');
            }
            $this->status = $status;
            $this->statusDescription = $description === null
                ? null
                : TelemetrySanitizer::string($description, 1024);
        }

        return $this;
    }

    #[\Override]
    public function end(?int $endNs = null): void
    {
        if ($this->ended) {
            return;
        }
        $this->ended = true;
        $this->endNs = max($this->startNs, $endNs ?? TelemetryClock::nowNs());
        ($this->onEnd)(new SpanData(
            $this->name,
            $this->context,
            $this->parent,
            $this->startNs,
            $this->endNs,
            $this->startUnixNano,
            $this->startUnixNano + (int) ($this->endNs - $this->startNs),
            $this->status,
            $this->statusDescription,
            $this->attributes,
            $this->events,
        ));
    }

    #[\Override]
    public function isEnded(): bool
    {
        return $this->ended;
    }
}
