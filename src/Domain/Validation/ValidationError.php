<?php

declare(strict_types=1);

/*
 * ZEF Framework — Domain layer (ports, contracts, value objects)
 * Added in the v2.8.0 roadmap continuation (additive, no behavioural changes).
 */

namespace Zef\Framework\Validation;

/**
 * A single field validation failure.
 */
final readonly class ValidationError
{
    public function __construct(
        public string $field,
        public string $message,
        public string $rule = '',
    ) {
        if ($this->field === '') {
            throw new \InvalidArgumentException('Validation error field must not be empty.');
        }
    }

    public function toArray(): array
    {
        return ['field' => $this->field, 'message' => $this->message, 'rule' => $this->rule];
    }
}
