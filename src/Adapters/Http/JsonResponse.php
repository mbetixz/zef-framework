<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Adapters layer (inbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Http;

/**
 * Static factory for JSON error responses.
 * Eliminates hand-built JSON strings scattered across the codebase.
 */
final class JsonResponse
{
    /**
     * @param array<string,mixed> $extra
     * @param array<string,string> $headers
     */
    public static function error(
        int $status,
        string $error,
        array $extra = [],
        array $headers = [],
    ): Response {
        $body = array_merge(['error' => $error, 'status' => $status], $extra);
        $allHeaders = array_merge(['Content-Type' => 'application/json'], $headers);

        return new Response(
            $status,
            $allHeaders,
            json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }
}
