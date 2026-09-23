<?php

declare(strict_types=1);

/*
 * ZEF Framework — Event Sourcing (Domain layer: ports, contracts, value objects)
 * Added in v2.19.0 (Event Sourcing: event store, projections, snapshots,
 * transactional outbox built on the Database Core port).
 */

namespace Zef\Framework\EventSourcing;

/**
 * One queued message in the transactional outbox.
 *
 * Lifecycle: `pending` → (relayed) → `processed`, or `pending` →
 * (attempts exhausted) → `failed` (dead letter). `attempts` counts relay
 * failures recorded by the store; `nextAttemptAtUnixNano` gates retry
 * eligibility (exponential backoff computed by the relay).
 */
final readonly class OutboxEntry
{
    public const string STATUS_PENDING = 'pending';
    public const string STATUS_PROCESSED = 'processed';
    public const string STATUS_FAILED = 'failed';

    private const array STATUSES = [
        self::STATUS_PENDING => true,
        self::STATUS_PROCESSED => true,
        self::STATUS_FAILED => true,
    ];

    /**
     * @param array<mixed> $payload
     * @param array<mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $messageType,
        public array $payload,
        public array $metadata,
        public int $attempts,
        public string $status,
        public int $nextAttemptAtUnixNano,
        public ?string $lastError,
        public int $createdAtUnixNano,
    ) {
        EventGrammar::assertEventId($id, 'outbox entry id');
        EventGrammar::assertEventType($messageType, 'outbox message type');
        EventGrammar::assertPayload($payload, 'Outbox entry payload');
        EventGrammar::assertPayload($metadata, 'Outbox entry metadata');
        if ($attempts < 0) {
            throw new EventSourcingException("Outbox entry attempts must be >= 0 (got {$attempts}).");
        }
        if (!isset(self::STATUSES[$status])) {
            throw new EventSourcingException(
                "Invalid outbox status '{$status}' (allowed: pending, processed, failed).",
            );
        }
        EventGrammar::assertUnixNano($nextAttemptAtUnixNano, 'nextAttemptAtUnixNano');
        EventGrammar::assertUnixNano($createdAtUnixNano, 'createdAtUnixNano');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isProcessed(): bool
    {
        return $this->status === self::STATUS_PROCESSED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }
}
