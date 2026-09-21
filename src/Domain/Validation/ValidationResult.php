<?php

declare(strict_types=1);

/*
 * ZEF Framework — Domain layer (ports, contracts, value objects)
 * Added in the v2.8.0 roadmap continuation (additive, no behavioural changes).
 */

namespace Zef\Framework\Validation;

/**
 * Outcome of a Validator run: the (possibly cleaned) data plus every failure.
 *
 * @template TData of array<string,mixed>
 */
final readonly class ValidationResult
{
    /**
     * @param list<ValidationError> $errors
     * @param array<string,mixed> $data
     */
    public function __construct(
        public array $errors,
        public array $data,
    ) {}

    public function ok(): bool
    {
        return $this->errors === [];
    }

    /** @return list<string> error messages, one per failure */
    public function messages(): array
    {
        $messages = [];
        foreach ($this->errors as $error) {
            $messages[] = $error->message;
        }

        return $messages;
    }

    /** @return list<ValidationError> failures for one field */
    public function errorsFor(string $field): array
    {
        $found = [];
        foreach ($this->errors as $error) {
            if ($error->field === $field) {
                $found[] = $error;
            }
        }

        return $found;
    }

    public function firstMessage(): ?string
    {
        return $this->errors === [] ? null : $this->errors[0]->message;
    }
}
