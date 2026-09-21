<?php

declare(strict_types=1);

/*
 * ZEF Framework — Domain layer (ports, contracts, value objects)
 * Added in the v2.8.0 roadmap continuation (additive, no behavioural changes).
 */

namespace Zef\Framework\Security;

/**
 * RFC 4648 Base32 codec (A-Z, 2-7) used for TOTP shared secrets.
 *
 * Encoding emits unpadded output (Google Authenticator convention); decoding
 * accepts missing padding, lowercase input, and both '=' padding styles.
 */
final class Base32
{
    private const string ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function encode(string $raw): string
    {
        if ($raw === '') {
            return '';
        }
        $output = '';
        $bits = 0;
        $buffer = 0;
        for ($i = 0, $n = strlen($raw); $i < $n; ++$i) {
            $buffer = ($buffer << 8) | ord($raw[$i]);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $output .= self::ALPHABET[($buffer >> $bits) & 31];
            }
        }
        if ($bits > 0) {
            $output .= self::ALPHABET[($buffer << (5 - $bits)) & 31];
        }

        return $output;
    }

    /** @return string decoded bytes
     * @throws \InvalidArgumentException on invalid characters */
    public static function decode(string $encoded): string
    {
        $encoded = strtoupper(preg_replace('/[=\s]/', '', $encoded) ?? '');
        if (preg_match('/^[A-Z2-7]*$/', $encoded) !== 1) {
            throw new \InvalidArgumentException('Invalid base32 input.');
        }
        $output = '';
        $bits = 0;
        $buffer = 0;
        for ($i = 0, $n = strlen($encoded); $i < $n; ++$i) {
            $position = strpos(self::ALPHABET, $encoded[$i]);
            if ($position === false) {
                throw new \InvalidArgumentException('Invalid base32 input.');
            }
            $buffer = ($buffer << 5) | $position;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $output .= chr(($buffer >> $bits) & 255);
            }
        }
        // Remaining bits (< 8) are the canonical zero padding; nonzero
        // trailing bits indicate a corrupt input.
        if ($bits > 0 && ($buffer & ((1 << $bits) - 1)) !== 0) {
            throw new \InvalidArgumentException('Invalid base32 padding bits.');
        }

        return $output;
    }
}
