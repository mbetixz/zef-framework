<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Adapters layer (inbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework;

use Psr\Http\Message\ResponseInterface;
use Zef\Framework\Http\Stream;

final class ResponseEmitter
{
    public function emit(
        ResponseInterface $response,
        bool $closeBody = false,
        bool $suppressBody = false,
    ): void {
        if (!headers_sent()) {
            $status = $response->getStatusCode();
            // Foreign PSR-7 implementations skip Zef's construction-time
            // validation: re-validate here and fail closed (skip the
            // header) instead of trusting attacker-shaped payloads.
            if ($status >= 100 && $status <= 599) {
                http_response_code($status);
            }
            $validator = new Validation\HeaderValidator();
            // A lying Content-Length (declared ≠ octets actually sent)
            // truncates clients and desyncs keep-alive proxies (RFC 9110
            // §8.6; CL.CL smuggling). Reconcile against the stream.
            $body = $response->getBody();
            $declared = $response->getHeaderLine('Content-Length');
            if ($declared !== '') {
                $size = $body->getSize();
                $consumed = $body->isSeekable() ? $body->tell() : null;
                $remaining = ($size !== null && $consumed !== null) ? max(0, $size - $consumed) : $size;
                if (!ctype_digit($declared) || $remaining === null || (int) $declared !== $remaining) {
                    $response = $response->withoutHeader('Content-Length');
                }
            }
            foreach ($response->getHeaders() as $name => $values) {
                foreach (!is_array($values) ? [$values] : $values as $value) {
                    try {
                        $validator->assertName((string) $name);
                        $validator->assertValue((string) $name, (string) $value);
                    } catch (\Throwable) {
                        continue;
                    }
                    header($name . ': ' . $value, false);
                }
            }
        }
        $body = $response->getBody();

        try {
            $status = $response->getStatusCode();
            if (
                !$suppressBody
                && $status >= 200
                && $status !== 204
                && $status !== 205
                && $status !== 304
            ) {
                if (!$body->isReadable()) {
                    throw new \RuntimeException('Response body stream is not readable.');
                }
                while (!$body->eof()) {
                    $chunk = $body->read(8192);
                    if ($chunk === '') {
                        break;
                    }
                    echo $chunk;
                }
            }
        } finally {
            if ($closeBody) {
                $body->close();
            }
        }
    }
}
