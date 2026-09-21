<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Security\Distributed;

final readonly class SecurityRequest
{
    use BoundedTrait;

    public const int MAX_OPERATION_BYTES = 128;
    public const int MAX_RESOURCE_BYTES = 128;
    public const int MAX_ACTION_BYTES = 64;
    public const int MAX_REPLAY_ID_BYTES = 128;
    public const int MAX_ATTRIBUTES = 16;
    public const int MAX_ATTRIBUTE_KEY_BYTES = 64;
    public const int MAX_ATTRIBUTE_VALUE_BYTES = 256;
    public const int MAX_ATTRIBUTE_BYTES = 4096;

    /** @param array<string, mixed> $attributes */
    public function __construct(
        public string $operationClass,
        public string $resource,
        public string $action,
        public ?string $replayId = null,
        public array $attributes = [],
    ) {
        self::assertBounded($operationClass, self::MAX_OPERATION_BYTES, 'operationClass');
        self::assertBounded($resource, self::MAX_RESOURCE_BYTES, 'resource');
        self::assertBounded($action, self::MAX_ACTION_BYTES, 'action');
        if ($replayId !== null) {
            self::assertBounded($replayId, self::MAX_REPLAY_ID_BYTES, 'replayId');
        }
        $this->validateAttributes($attributes);
    }

    /** @param array<string, mixed> $attributes */
    private function validateAttributes(array $attributes): void
    {
        if (count($attributes) > self::MAX_ATTRIBUTES) {
            throw new \InvalidArgumentException('Too many security attributes.');
        }
        $total = 0;
        foreach ($attributes as $key => $value) {
            if ($key === '' || strlen($key) > self::MAX_ATTRIBUTE_KEY_BYTES) {
                throw new \InvalidArgumentException('Invalid security attribute key.');
            }
            $normalizedKey = strtolower($key);
            foreach (['authorization', 'proxy-authorization', 'token', 'secret', 'password', 'private_key', 'private-key'] as $forbidden) {
                if ($normalizedKey === $forbidden || str_contains($normalizedKey, $forbidden)) {
                    throw new \InvalidArgumentException('Sensitive credential material is not permitted in generic security attributes.');
                }
            }
            if (!is_scalar($value) && $value !== null) {
                throw new \InvalidArgumentException('Security attributes must be scalar or null.');
            }
            $stringValue = $value === null ? '' : (string) $value;
            if (strlen($stringValue) > self::MAX_ATTRIBUTE_VALUE_BYTES) {
                throw new \InvalidArgumentException('Security attribute value exceeds its bound.');
            }
            $total += strlen($key) + strlen($stringValue);
        }
        if ($total > self::MAX_ATTRIBUTE_BYTES) {
            throw new \InvalidArgumentException('Security attribute bytes exceed their bound.');
        }
    }
}
