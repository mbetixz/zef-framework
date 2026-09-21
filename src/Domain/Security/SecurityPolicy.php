<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Security;

use Psr\Log\LoggerInterface;
use Zef\Framework\Foundation\Env;

final readonly class SecurityPolicy
{
    /**
     * @var list<string>
     */
    public array $allowedOrigins;

    /** @param list<string> $allowedOrigins */
    public function __construct(
        public bool $rateLimitEnabled = false,
        public int $rateLimitMaxRequests = 100,
        public int $rateLimitWindowSeconds = 60,
        public int $rateLimitMaxKeys = 10000,
        public bool $csrfEnabled = true,
        public string $csrfSecret = '',
        public string $csrfCookieName = 'ZEF-XSRF-TOKEN',
        public string $csrfHeaderName = 'X-CSRF-Token',
        public bool $csrfSecureCookie = true,
        public bool $csrfHttpOnlyCookie = true,
        public string $csrfSameSite = 'Strict',
        array $allowedOrigins = [],
        public bool $originEnabled = false,
        public int $csrfTokenBytes = 32,
    ) {
        if ($this->csrfTokenBytes < 16) {
            throw new \InvalidArgumentException('csrfTokenBytes must be >= 16.');
        }
        if ($this->rateLimitMaxRequests < 1) {
            throw new \InvalidArgumentException('rateLimitMaxRequests must be >= 1.');
        }
        if ($this->rateLimitWindowSeconds < 1) {
            throw new \InvalidArgumentException('rateLimitWindowSeconds must be >= 1.');
        }
        if ($this->rateLimitMaxKeys < 1) {
            throw new \InvalidArgumentException('rateLimitMaxKeys must be >= 1.');
        }
        if ($this->csrfEnabled && $this->csrfSecret !== '' && strlen($this->csrfSecret) < 32) {
            throw new \InvalidArgumentException('CSRF secret must be at least 32 bytes when CSRF is enabled.');
        }
        if (preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $this->csrfCookieName) !== 1) {
            throw new \InvalidArgumentException('Invalid CSRF cookie name.');
        }
        if (preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $this->csrfHeaderName) !== 1) {
            throw new \InvalidArgumentException('Invalid CSRF header name.');
        }
        if (!in_array($this->csrfSameSite, ['Strict', 'Lax', 'None'], true)) {
            throw new \InvalidArgumentException('Invalid CSRF SameSite policy.');
        }
        if ($this->csrfSameSite === 'None' && !$this->csrfSecureCookie) {
            throw new \InvalidArgumentException('SameSite=None requires Secure cookies.');
        }
        $normalized = [];
        foreach ($allowedOrigins as $origin) {
            $normalized[] = OriginPolicy::normalizeOrigin($origin);
        }
        $this->allowedOrigins = array_values(array_unique($normalized));
        if ($this->originEnabled && $this->allowedOrigins === []) {
            throw new \InvalidArgumentException('Origin policy enabled without allowed origins.');
        }
    }

    /**
     * Bug fix #17: error_log routed through LoggerInterface where reachable.
     */
    public static function fromEnvironment(
        ?LoggerInterface $logger = null,
    ): self {
        $csrfDefault = true;
        $csrfEnv = getenv('ZEF_SECURITY_CSRF');
        if ($csrfEnv !== false && trim((string) $csrfEnv) === '') {
            $csrfEnv = false;
        }
        $csrfEnabled = $csrfEnv === false
            ? $csrfDefault
            : filter_var($csrfEnv, FILTER_VALIDATE_BOOL);
        $csrfSecret = Env::string('ZEF_SECURITY_CSRF_SECRET');
        if ($csrfEnabled && $csrfSecret === '' && $csrfEnv !== false && filter_var($csrfEnv, FILTER_VALIDATE_BOOL)) {
            throw new \RuntimeException('ZEF_SECURITY_CSRF=1 requires ZEF_SECURITY_CSRF_SECRET (>= 32 bytes).');
        }
        if ($csrfEnabled && $csrfSecret === '') {
            $csrfEnabled = false;
            $msg = '[ZEF][security] ZEF_SECURITY_CSRF_SECRET is not set; CSRF protection disabled. Set a secret of at least 32 bytes in production.';
            if ($logger instanceof LoggerInterface) {
                $logger->warning($msg);
            } else {
                error_log($msg);
            }
        }

        return new self(
            rateLimitEnabled: Env::bool('ZEF_SECURITY_RATE_LIMIT'),
            rateLimitMaxRequests: self::envPositiveInt('ZEF_SECURITY_RATE_LIMIT_MAX', 100),
            rateLimitWindowSeconds: self::envPositiveInt('ZEF_SECURITY_RATE_LIMIT_WINDOW', 60),
            rateLimitMaxKeys: self::envPositiveInt('ZEF_SECURITY_RATE_LIMIT_MAX_KEYS', 10000),
            csrfEnabled: $csrfEnabled,
            csrfSecret: $csrfSecret,
            csrfCookieName: trim(Env::string('ZEF_SECURITY_CSRF_COOKIE', 'ZEF-XSRF-TOKEN')),
            csrfHeaderName: trim(Env::string('ZEF_SECURITY_CSRF_HEADER', 'X-CSRF-Token')),
            csrfSecureCookie: Env::bool('ZEF_SECURITY_CSRF_SECURE', true),
            csrfHttpOnlyCookie: Env::bool('ZEF_SECURITY_CSRF_HTTP_ONLY', true),
            csrfSameSite: trim(Env::string('ZEF_SECURITY_CSRF_SAMESITE', 'Strict')),
            allowedOrigins: Env::csv('ZEF_SECURITY_ALLOWED_ORIGINS'),
            originEnabled: Env::bool('ZEF_SECURITY_ORIGIN_POLICY'),
            csrfTokenBytes: max(16, self::envPositiveInt('ZEF_SECURITY_CSRF_TOKEN_BYTES', 32)),
        );
    }

    /**
     * Non-numeric env values previously (int)-cast to 0, so a typo like
     * ZEF_SECURITY_RATE_LIMIT_MAX=1OO silently became 1 request/window
     * (not the documented default). Fall back to the default instead.
     */
    private static function envPositiveInt(string $name, int $default): int
    {
        $raw = getenv($name);
        if ($raw === false || trim((string) $raw) === '' || !ctype_digit(trim((string) $raw))) {
            return $default;
        }

        return max(1, (int) $raw);
    }
}
