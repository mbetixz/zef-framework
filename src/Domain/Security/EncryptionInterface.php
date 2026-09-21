<?php

declare(strict_types=1);

/*
 * ZEF Framework — Domain layer (ports, contracts, value objects)
 * Added in the v2.8.0 roadmap continuation (additive, no behavioural changes).
 */

namespace Zef\Framework\Security;

/**
 * Authenticated symmetric encryption port.
 *
 * Implementations MUST provide confidentiality AND integrity (AEAD):
 * decrypting a tampered or wrong-key ciphertext must fail loudly. The wire
 * format is implementation-defined but self-describing so key/algo rotation
 * can be layered on top (version prefix).
 */
interface EncryptionInterface
{
    /**
     * Encrypt plaintext. Returns an opaque, transport-safe string.
     */
    public function encrypt(string $plaintext): string;

    /**
     * Decrypt a payload produced by encrypt().
     *
     * @throws \RuntimeException on tamper, truncation, or key mismatch
     */
    public function decrypt(string $payload): string;
}
