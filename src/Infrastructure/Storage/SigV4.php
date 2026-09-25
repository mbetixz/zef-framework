<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.30.0 — Infrastructure layer (outbound adapters)
 * Ecosystem Ports: AWS Signature Version 4 request signer (pure logic).
 */

namespace Zef\Framework\Storage;

/**
 * Minimal, dependency-free AWS Signature Version 4 signer for the
 * S3-compatible object-storage adapter (single-shot header signing).
 *
 * Pure and deterministic: no clock, no network, no randomness — the AMZ
 * date is an explicit argument, so the unit suite can pin the signer
 * against published AWS test vectors. Only the header-based variant is
 * implemented (query-string presigning is out of scope by design).
 *
 * Encoding authority: the caller passes the ALREADY-ENCODED URI path
 * (S3CompatibleStorage builds it with one rawurlencode pass per segment —
 * the "s3 service does not double-encode" rule of SigV4), while query
 * pairs arrive raw and are encoded exactly once here.
 */
final class SigV4
{
    public const string ALGORITHM = 'AWS4-HMAC-SHA256';
    public const string SERVICE = 's3';
    public const string TERMINATOR = 'aws4_request';
    public const string EMPTY_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    /**
     * Build the Authorization header value for one request.
     *
     * @param string               $method       HTTP verb
     * @param string               $encodedPath  URI path, already rawurlencoded per segment
     * @param array<string,string> $query        raw (undecoded) query pairs, encoded here
     * @param array<string,string> $headers      headers that WILL be sent (names lowercased here)
     * @param string               $payloadHash  hex sha256 of the request body
     * @param string               $amzDate      ISO8601 basic, e.g. 20260926T101112Z
     */
    public static function authorization(
        string $method,
        string $encodedPath,
        array $query,
        array $headers,
        string $payloadHash,
        string $accessKeyId,
        string $secretAccessKey,
        string $region,
        string $amzDate,
    ): string {
        $normalized = self::normalizeHeaders($headers);
        $signedHeaders = implode(';', array_keys($normalized));
        $canonicalQuery = self::canonicalQuery($query);
        $dateStamp = substr($amzDate, 0, 8);

        $canonicalRequest = implode("\n", [
            $method,
            // Callers always pass an absolute already-encoded path
            // ('/' . bucket . '/' . encodedKey), so no re-encoding guard.
            $encodedPath,
            $canonicalQuery,
            self::canonicalHeadersBlock($normalized),
            $signedHeaders,
            $payloadHash,
        ]);

        $scope = $dateStamp . '/' . $region . '/' . self::SERVICE . '/' . self::TERMINATOR;
        $stringToSign = implode("\n", [
            self::ALGORITHM,
            $amzDate,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);

        $signature = hash_hmac('sha256', $stringToSign, self::signingKey($secretAccessKey, $dateStamp, $region));

        return self::ALGORITHM
            . ' Credential=' . $accessKeyId . '/' . $scope
            . ', SignedHeaders=' . $signedHeaders
            . ', Signature=' . $signature;
    }

    /** Hex sha256 of a payload (empty payload = EMPTY_SHA256). */
    public static function payloadHash(string $body): string
    {
        return hash('sha256', $body);
    }

    /**
     * Canonical query string: pairs encoded once (RFC 3986) and sorted by
     * encoded name then value. Public so the request builder can produce
     * the ACTUAL URL query with byte-identical ordering/encoding to the
     * canonical form the signature covers.
     *
     * @param array<string,string> $query
     */
    public static function canonicalQuery(array $query): string
    {
        $pairs = [];
        foreach ($query as $name => $value) {
            $pairs[] = [rawurlencode((string) $name), rawurlencode((string) $value)];
        }
        usort($pairs, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $encoded = '';
        foreach ($pairs as [$name, $value]) {
            $encoded .= ($encoded === '' ? '' : '&') . $name . '=' . $value;
        }

        return $encoded;
    }

    /** @param array<string,string> $headers
     *
     * @return array<string, string> lowercase-name → trimmed value, sorted
     */
    private static function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower((string) $name)] = trim((string) $value);
        }
        ksort($normalized);

        return $normalized;
    }

    /** @param array<string,string> $normalized lowercase-name → trimmed value, sorted */
    private static function canonicalHeadersBlock(array $normalized): string
    {
        $block = '';
        foreach ($normalized as $name => $value) {
            $block .= $name . ':' . $value . "\n";
        }

        return $block;
    }

    private static function signingKey(string $secretAccessKey, string $dateStamp, string $region): string
    {
        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $secretAccessKey, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', self::SERVICE, $kRegion, true);

        return hash_hmac('sha256', self::TERMINATOR, $kService, true);
    }
}
