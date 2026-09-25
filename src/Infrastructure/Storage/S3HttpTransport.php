<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.30.0 — Infrastructure layer (outbound adapters)
 * Ecosystem Ports: outbound HTTP seam for the S3-compatible adapter.
 */

namespace Zef\Framework\Storage;

/**
 * Outbound HTTP seam for {@see S3CompatibleStorage}.
 *
 * Keeping the HTTP call behind an interface makes the SigV4 request
 * building and response mapping deterministically testable without any
 * network: tests inject a recording fake, production wires
 * {@see CurlS3HttpTransport}. This mirrors the transport seam pattern the
 * framework already uses for message/job ports.
 */
interface S3HttpTransport
{
    /**
     * Perform one HTTP request.
     *
     * @param string              $method  HTTP verb (GET/PUT/HEAD/DELETE)
     * @param string              $url     absolute URL, already encoded
     * @param array<string,string> $headers request headers (name => value)
     * @param string              $body    request body ('' for GET/HEAD/DELETE)
     *
     * @throws StorageException on transport-level failures (DNS, TLS,
     *                          timeouts, missing extension)
     */
    public function request(string $method, string $url, array $headers, string $body): S3HttpResponse;
}
