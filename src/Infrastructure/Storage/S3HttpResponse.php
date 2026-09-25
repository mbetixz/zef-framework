<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.30.0 — Infrastructure layer (outbound adapters)
 * Ecosystem Ports: HTTP response VO for the S3-compatible transport.
 */

namespace Zef\Framework\Storage;

/**
 * Immutable HTTP response as consumed by {@see S3CompatibleStorage}.
 *
 * Headers are normalised to lowercase names with a list of values in
 * arrival order (repeated headers are legal in HTTP); {@see header()}
 * does the case-insensitive first-value lookup used for Content-Length
 * and Last-Modified parsing.
 */
final readonly class S3HttpResponse
{
    /** @param array<string, list<string>> $headers lowercase-name → values */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
    ) {}

    /** Case-insensitive first-value header lookup (null when absent). */
    public function header(string $name): ?string
    {
        $lower = strtolower($name);

        return $this->headers[$lower][0] ?? null;
    }
}
