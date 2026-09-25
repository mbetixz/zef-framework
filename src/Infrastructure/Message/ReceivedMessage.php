<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.30.0 — Infrastructure layer (outbound adapters)
 * Ecosystem Ports: received-message snapshot for the in-memory transport.
 */

namespace Zef\Framework\Message;

/**
 * Immutable record handed out by {@see InMemoryMessageTransport::receive()}:
 * the original envelope, the send-time context, and the transport-assigned
 * id that was already echoed back in the send() MessageResult.
 */
final readonly class ReceivedMessage
{
    public function __construct(
        public MessageEnvelope $envelope,
        public MessageContext $context,
        public string $transportId,
    ) {}
}
