<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Application layer (in-process orchestration)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Message;

final class JsonMessageSerializer implements MessageSerializerInterface
{
    #[\Override]
    public function serialize(MessageEnvelope $message): string
    {
        $this->assertJsonSafe($message->payload);

        return json_encode(
            [
                'id' => $message->messageId,
                'type' => $message->messageType,
                'payload' => $message->payload,
                'headers' => $message->headers,
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
    }

    #[\Override]
    public function deserialize(string $payload): MessageEnvelope
    {
        if (strlen($payload) > 1_048_576) {
            throw new \InvalidArgumentException('Message payload exceeds the 1 MiB limit.');
        }
        $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        if (
            !is_array($data)
            || !isset($data['id'], $data['type'])
            || !is_string($data['id'])
            || !is_string($data['type'])
        ) {
            throw new \InvalidArgumentException('Invalid serialized message envelope.');
        }
        $headers = $data['headers'] ?? [];
        if (!is_array($headers)) {
            throw new \InvalidArgumentException('Invalid serialized headers.');
        }
        foreach ($headers as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                throw new \InvalidArgumentException('Serialized headers must be strings.');
            }
        }

        return new MessageEnvelope($data['id'], $data['type'], $data['payload'] ?? null, $headers);
    }

    private function assertJsonSafe(mixed $value): void
    {
        if (is_resource($value) || is_object($value)) {
            throw new \InvalidArgumentException('Message payload must be JSON-safe data.');
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->assertJsonSafe($item);
            }
        }
    }
}
