<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Domain layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi;

/**
 * Contact information for the exposed API (OpenAPI Contact Object).
 */
final readonly class Contact
{
    public function __construct(
        public ?string $name = null,
        public ?string $email = null,
        public ?string $url = null,
    ) {
        if ($name === null && $email === null && $url === null) {
            throw new \InvalidArgumentException('Contact must define at least one of name, email, or url.');
        }
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException("Contact email '{$email}' is not a valid email address.");
        }
        if ($url !== null && filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException("Contact url '{$url}' is not a valid URL.");
        }
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $out = [];
        if ($this->name !== null) {
            $out['name'] = $this->name;
        }
        if ($this->email !== null) {
            $out['email'] = $this->email;
        }
        if ($this->url !== null) {
            $out['url'] = $this->url;
        }

        return $out;
    }
}
