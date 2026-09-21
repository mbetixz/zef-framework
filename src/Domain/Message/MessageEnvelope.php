<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Message;

use Zef\Framework\Validation\Identifier;

/**
 * Immutable transport-neutral message envelope.
 */
final readonly class MessageEnvelope
{
    /** @param array<string,string> $headers */
    public function __construct(
        public string $messageId,
        public string $messageType,
        public mixed $payload,
        public array $headers = [],
    ) {
        Identifier::assertOpaqueId($messageId, 'message ID');
        Identifier::assertMessageType($messageType);
        if (count($headers) > 64) {
            throw new \InvalidArgumentException('Message header count exceeds the limit.');
        }
        foreach ($headers as $name => $value) {
            if (preg_match('/^[A-Za-z0-9._-]{1,128}$/', $name) !== 1) {
                throw new \InvalidArgumentException('Invalid message header name.');
            }
            if (!is_string($value) || strlen($value) > 4096) {
                throw new \InvalidArgumentException('Message header value must be a string within the limit.');
            }
        }
    }
}
