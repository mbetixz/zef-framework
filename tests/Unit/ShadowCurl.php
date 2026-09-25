<?php

declare(strict_types=1);

/*
 * ZEF Framework — v2.30.0 Ecosystem Ports: namespace-level cURL shadow for
 * CurlS3HttpTransport tests.
 *
 * PHP resolves UNQUALIFIED function calls from a namespace against that
 * namespace first, then the global table — so these shadow definitions
 * intercept every curl_* call inside CurlS3HttpTransport without touching
 * the global extension. The shadow is toggleable: $enabled = false mimics
 * a host without ext-curl (curl_init() throws Error, exactly like PHP does
 * for an undefined function).
 *
 * IMPORTANT: loaded ONLY by the storage mutation/unit tests — never ship it
 * through the autoloader (it is not registered in the classmap).
 */

namespace Zef\Framework\Storage;

final class ShadowCurlState
{
    public static bool $enabled = false;

    /** @var array<int, mixed> CURLOPT_* option => value */
    public static array $opts = [];

    /** @var list<mixed> return values captured from the header function */
    public static array $headerReturns = [];

    public static bool $initReturnsFalse = false;

    public static bool|string $execResult = 'shadow-body';

    public static int $responseCode = 200;

    public static string $curlError = 'boom-shadow';
}

function curl_init(?string $url = null): false|\stdClass
{
    if (!ShadowCurlState::$enabled) {
        throw new \Error('Call to undefined function curl_init()');
    }
    if (ShadowCurlState::$initReturnsFalse) {
        return false;
    }
    ShadowCurlState::$opts = [];
    ShadowCurlState::$headerReturns = [];

    return new \stdClass();
}

/**
 * @param array<int, mixed> $options
 */
function curl_setopt_array(\stdClass $handle, array $options): bool
{
    ShadowCurlState::$opts = $options;

    return true;
}

function curl_setopt(\stdClass $handle, int $option, mixed $value): bool
{
    ShadowCurlState::$opts[$option] = $value;

    return true;
}

function curl_exec(\stdClass $handle): bool|string
{
    $headerFunction = ShadowCurlState::$opts[CURLOPT_HEADERFUNCTION] ?? null;
    if ($headerFunction instanceof \Closure) {
        foreach (["HTTP/1.1 200 OK\r\n", "Content-Length: 12\r\n", "Last-Modified: Wed, 01 Mar 2023 12:00:00 GMT\r\n", "\r\n"] as $line) {
            ShadowCurlState::$headerReturns[] = $headerFunction($handle, $line);
        }
    }

    return ShadowCurlState::$execResult;
}

function curl_getinfo(\stdClass $handle, int $option): int
{
    return ShadowCurlState::$responseCode;
}

function curl_error(\stdClass $handle): string
{
    return ShadowCurlState::$curlError;
}

function curl_close(\stdClass $handle): void {}
