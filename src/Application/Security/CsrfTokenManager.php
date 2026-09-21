<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Application layer (in-process orchestration)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Security;

final class CsrfTokenManager
{
    public function __construct(
        private readonly string $secret,
        private readonly int $tokenBytes = 32,
    ) {
        if (strlen($secret) < 32) {
            throw new \InvalidArgumentException('CSRF secret must be at least 32 bytes.');
        }
        if ($tokenBytes < 16) {
            throw new \InvalidArgumentException('tokenBytes must be >= 16.');
        }
    }

    public function issue(): string
    {
        // Constructor already validates tokenBytes >= 16; no re-clamp needed.
        $token = rtrim(strtr(base64_encode(random_bytes($this->tokenBytes)), '+/', '-_'), '=');
        $mac = hash_hmac('sha256', $token, $this->secret);

        return $token . '.' . $mac;
    }

    public function isValid(string $token): bool
    {
        [$value, $signature] = array_pad(explode('.', $token, 2), 2, '');
        if (
            $value === ''
            || $signature === ''
            || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $signature) !== 1
        ) {
            return false;
        }
        $expected = hash_hmac('sha256', $value, $this->secret);

        return hash_equals($expected, $signature);
    }
}

// Bug fix #4: added guards $maxKeys>=1, $key!='', $limit>=1, $windowSeconds>=1.
