<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Domain layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi;

/**
 * OpenAPI Tag Object — documentation grouping for operations.
 */
final readonly class Tag
{
    public function __construct(
        public string $name,
        public string $description = '',
    ) {
        if (trim($name) === '') {
            throw new \InvalidArgumentException('Tag name must not be empty.');
        }
        if (mb_strlen($name) > 128) {
            throw new \InvalidArgumentException('Tag name must not exceed 128 characters.');
        }
    }

    /**
     * @return array{name: string, description?: string}
     */
    public function toArray(): array
    {
        $out = ['name' => $this->name];
        if ($this->description !== '') {
            $out['description'] = $this->description;
        }

        return $out;
    }
}
