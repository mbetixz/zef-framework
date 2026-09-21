<?php

declare(strict_types=1);

/*
 * ZEF Framework — Adapters layer (inbound HTTP adapter)
 * Added in the v2.10.0 roadmap continuation (additive, no behavioural changes).
 */

namespace Zef\Framework\Http;

use Psr\Http\Message\ServerRequestInterface;
use Zef\Framework\Validation\ValidationResult;
use Zef\Framework\Validation\Validator;

/**
 * Form/request payload object over the v2.8.0 rules engine.
 *
 *   $validator = new Validator();
 *   $validator->field('email')->required()->email()->maxLength(254);
 *   $form = FormRequest::fromServerRequest($validator, $request);
 *   if ($form->isValid()) { save($form->validated()); }
 *
 * fromServerRequest merges getQueryParams() with getParsedBody() (parsed body
 * wins, so a JSON body can override query defaults). validated() exposes only
 * the fields declared on the validator — unknown payload keys stay available
 * via result()->data but never enter the write path.
 */
final readonly class FormRequest
{
    private function __construct(
        public ValidationResult $result,
        private array $declared,
    ) {}

    public static function fromArray(Validator $validator, array $payload): self
    {
        $result = $validator->validate($payload);

        return new self($result, $validator->fieldNames());
    }

    public static function fromServerRequest(Validator $validator, ServerRequestInterface $request): self
    {
        $query = $request->getQueryParams();
        $body = $request->getParsedBody();
        $payload = is_array($query) ? $query : [];
        if (is_array($body)) {
            foreach ($body as $key => $value) {
                $payload[$key] = $value; // parsed body wins over query defaults
            }
        }

        return self::fromArray($validator, $payload);
    }

    public function isValid(): bool
    {
        return $this->result->ok();
    }

    /** @return array<string,mixed> only fields declared on the validator */
    public function validated(): array
    {
        $out = [];
        foreach ($this->declared as $field) {
            if (array_key_exists($field, $this->result->data)) {
                $out[$field] = $this->result->data[$field];
            }
        }

        return $out;
    }

    /** @return list<array{field:string,message:string,rule:string}> */
    public function errors(): array
    {
        $out = [];
        foreach ($this->result->errors as $error) {
            $out[] = $error->toArray();
        }

        return $out;
    }
}
