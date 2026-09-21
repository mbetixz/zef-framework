<?php

declare(strict_types=1);

/*
 * ZEF Framework — Domain layer (ports, contracts, value objects)
 * Added in the v2.10.0 roadmap continuation (additive, no behavioural changes).
 */

namespace Zef\Framework\Validation;

/**
 * Rewrites the human-readable messages of a ValidationResult for a target
 * locale using a MessageCatalog. Rule names drive lookup, so any custom
 * message passed to FieldRules is preserved whenever no template exists.
 */
final readonly class ValidationTranslator
{
    public function __construct(
        private MessageCatalog $catalog,
    ) {}

    /**
     * @param array<string,string> $fieldLabels display names for placeholders ({{label}})
     */
    public function translate(ValidationResult $result, string $locale, array $fieldLabels = []): ValidationResult
    {
        if ($result->errors === []) {
            return $result;
        }
        $errors = [];
        foreach ($result->errors as $error) {
            $template = $this->catalog->templateFor($locale, $error->rule);
            if ($template === null) {
                $errors[] = $error; // unknown rule/locale: keep the original message

                continue;
            }
            $label = $fieldLabels[$error->field] ?? $error->field;
            $message = strtr($template, [
                '{{field}}' => $error->field,
                '{{label}}' => $label,
                '{{rule}}' => $error->rule,
            ]);
            $errors[] = new ValidationError($error->field, $message, $error->rule);
        }

        return new ValidationResult($errors, $result->data);
    }
}
