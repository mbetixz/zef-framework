<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Domain layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi;

/**
 * License information for the exposed API (OpenAPI License Object).
 */
final readonly class License
{
    public function __construct(
        public string $name,
        public ?string $identifier = null,
        public ?string $url = null,
    ) {
        if (trim($name) === '') {
            throw new \InvalidArgumentException('License name must not be empty.');
        }
        if ($url !== null && filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException("License url '{$url}' is not a valid URL.");
        }
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $out = ['name' => $this->name];
        if ($this->identifier !== null) {
            $out['identifier'] = $this->identifier;
        }
        if ($this->url !== null) {
            $out['url'] = $this->url;
        }

        return $out;
    }
}
