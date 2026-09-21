<?php

declare(strict_types=1);

/*
 * ZEF Framework — Domain layer (ports, contracts, value objects)
 * Added in the v2.8.0 roadmap continuation (additive, no behavioural changes).
 */

namespace Zef\Framework\Security;

/**
 * RFC 6238 time-based one-time passwords (TOTP), SHA-1/SHA-256/SHA-512.
 *
 * Pure implementation over hash_hmac (core ext). Verification uses
 * constant-time comparison (hash_equals) and a configurable ± window
 * (default ±1 period) to tolerate clock drift. 8-digit defaults are NOT
 * used; 6 digits is the interoperable default (see class methods).
 *
 * RFC 6238 Appendix B vectors (SHA-1, 8 digits, secret
 * "12345678901234567890") are covered by the self-test suite.
 */
final class Totp
{
    public const int DEFAULT_PERIOD = 30;
    public const int DEFAULT_DIGITS = 6;

    private const array ALGORITHMS = ['sha1' => 20, 'sha256' => 32, 'sha512' => 64];

    public function __construct(
        public readonly int $period = self::DEFAULT_PERIOD,
        public readonly int $digits = self::DEFAULT_DIGITS,
        public readonly string $algorithm = 'sha1',
    ) {
        if ($this->period < 1 || $this->period > 86400) {
            throw new \InvalidArgumentException('TOTP period must be 1..86400 seconds.');
        }
        if ($this->digits < 6 || $this->digits > 8) {
            throw new \InvalidArgumentException('TOTP digits must be 6..8.');
        }
        if (!isset(self::ALGORITHMS[$this->algorithm])) {
            throw new \InvalidArgumentException("Unsupported TOTP algorithm '{$this->algorithm}'.");
        }
    }

    /**
     * Compute the OTP for a unix timestamp.
     *
     * @param string $secret raw binary secret (decode base32 first if needed)
     */
    public function at(string $secret, int $unixTime): string
    {
        self::assertSecret($secret);
        $counter = intdiv($unixTime, $this->period);

        return self::hotp($secret, $counter, $this->digits, $this->algorithm);
    }

    /**
     * Verify a user-supplied code within ±$window periods around $unixTime.
     * Timing-safe against the candidate set.
     */
    public function verify(string $secret, string $code, int $unixTime, int $window = 1): bool
    {
        self::assertSecret($secret);
        if (preg_match('/^\d{' . $this->digits . '}$/', $code) !== 1) {
            return false;
        }
        for ($offset = -$window; $offset <= $window; ++$offset) {
            $candidate = $this->at($secret, $unixTime + ($offset * $this->period));
            if (hash_equals($candidate, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * RFC 4226 HOTP (dynamic truncation). Exposed for deterministic vectors.
     */
    public static function hotp(string $secret, int $counter, int $digits = 6, string $algorithm = 'sha1'): string
    {
        self::assertSecret($secret);
        if (!isset(self::ALGORITHMS[$algorithm])) {
            throw new \InvalidArgumentException("Unsupported HOTP algorithm '{$algorithm}'.");
        }
        $binaryCounter = pack('N2', ($counter >> 32) & 0xFFFFFFFF, $counter & 0xFFFFFFFF);
        $hash = hash_hmac($algorithm, $binaryCounter, $secret, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $value
            = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($value % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    private static function assertSecret(string $secret): void
    {
        if (strlen($secret) < 8) {
            throw new \InvalidArgumentException('TOTP secret must be at least 8 bytes.');
        }
    }
}
