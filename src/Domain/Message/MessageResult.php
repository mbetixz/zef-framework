<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Message;

use Zef\Framework\Validation\Identifier;

final readonly class MessageResult
{
    public function __construct(
        public string $messageId,
        public bool $accepted,
        public ?string $transportId = null,
    ) {
        Identifier::assertOpaqueId($messageId, 'result message ID');
        if ($transportId !== null && strlen($transportId) > 255) {
            throw new \InvalidArgumentException('Transport ID exceeds the limit.');
        }
    }
}
