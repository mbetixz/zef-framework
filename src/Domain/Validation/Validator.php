<?php

declare(strict_types=1);

/*
 * ZEF Framework — Domain layer (ports, contracts, value objects)
 * Added in the v2.8.0 roadmap continuation (additive, no behavioural changes).
 */

namespace Zef\Framework\Validation;

/**
 * Field-oriented rules engine over plain arrays (form/request payloads).
 *
 * Registration:
 *   $v = new Validator();
 *   $v->field('email')->required()->email()->maxLength(254);
 *   $v->field('age')->typeInt()->min(0)->max(130)->nullable();
 *   $result = $v->validate($payload);
 *
 * Unknown keys in the payload are preserved in the result data untouched;
 * declared fields failing validation appear in $result->errors. The engine
 * is pure: no I/O, no locale tricks, deterministic messages.
 */
final class Validator
{
    private const int MAX_FIELDS = 128;

    /**
     * @var array<string,FieldRules>
     */
    private array $fields = [];

    public function field(string $name): FieldRules
    {
        if ($name === '' || strlen($name) > 128) {
            throw new \InvalidArgumentException('Field name must be 1..128 bytes.');
        }
        if (!isset($this->fields[$name])) {
            if (count($this->fields) >= self::MAX_FIELDS) {
                throw new \OverflowException('Validator field budget exceeded (128).');
            }
            $this->fields[$name] = new FieldRules($name);
        }

        return $this->fields[$name];
    }

    public function hasField(string $name): bool
    {
        return isset($this->fields[$name]);
    }

    /** @return list<string> declared field names in registration order */
    public function fieldNames(): array
    {
        return array_keys($this->fields);
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function validate(array $payload): ValidationResult
    {
        $errors = [];
        $data = $payload;
        foreach ($this->fields as $name => $rules) {
            $value = $payload[$name] ?? null;
            $fieldErrors = $rules->validate($value);
            foreach ($fieldErrors as $fieldError) {
                $errors[] = $fieldError;
            }
        }

        return new ValidationResult($errors, $data);
    }
}
