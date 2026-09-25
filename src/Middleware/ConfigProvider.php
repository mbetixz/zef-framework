<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Demo application
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Middleware;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Zef\Framework\Config\ConfigProviderInterface;
use Zef\Framework\Foundation\Env;
use Zef\Framework\Security\ApcuRateLimiter;
use Zef\Framework\Security\HrTimeClock;
use Zef\Framework\Security\InMemoryRateLimiter;
use Zef\Framework\Security\RateLimitAlgorithm;
use Zef\Framework\Security\RateLimiterInterface;
use Zef\Framework\Security\RateLimitMiddleware;
use Zef\Framework\Security\RateLimitRule;
use Zef\Framework\Security\RedisRateLimiter;
use Zef\Framework\Security\RedisSharedRateLimitStore;
use Zef\Framework\Security\SecurityPolicy;
use Zef\Framework\Security\SecurityRuntimeMiddleware;
use Zef\Framework\Security\SlidingWindowRateLimiter;
use Zef\Framework\Security\TieredRateLimiter;
use Zef\Framework\Security\TokenBucketRateLimiter;

final readonly class ConfigProvider implements ConfigProviderInterface
{
    public function __construct(private bool $devMode = false) {}

    #[\Override]
    public function getModuleName(): string
    {
        return 'middleware';
    }

    #[\Override]
    public function getConfig(): array
    {
        $devMode = $this->devMode;

        return [
            'services' => [
                'middleware.error' => [
                    'factory' => static fn (ContainerInterface $c): GlobalErrorHandler => new GlobalErrorHandler(
                        $c->get(LoggerInterface::class),
                        new ErrorResponseFactory($devMode),
                    ),
                    'deps' => [LoggerInterface::class],
                ],
                'middleware.timing' => [
                    'factory' => static fn (): TimingMiddleware => new TimingMiddleware(),
                    'deps' => [],
                ],
                'middleware.cors' => [
                    'factory' => $this->buildCors(...),
                    'deps' => [],
                ],
                'middleware.security' => [
                    'factory' => static fn (): SecurityHeadersMiddleware => new SecurityHeadersMiddleware([
                        'hsts' => Env::bool('ZEF_SECURITY_HSTS'),
                        'csp' => Env::bool('ZEF_SECURITY_CSP'),
                    ]),
                    'deps' => [],
                ],
                'middleware.security.runtime' => [
                    'factory' => static function (ContainerInterface $c): SecurityRuntimeMiddleware {
                        $logger = null;

                        try {
                            $logger = $c->get(LoggerInterface::class);
                        } catch (\Throwable) {
                        }
                        $policy = SecurityPolicy::fromEnvironment($logger);
                        $rateLimiter = self::buildRateLimiter($policy, $logger);

                        return new SecurityRuntimeMiddleware($policy, $rateLimiter);
                    },
                    'deps' => [LoggerInterface::class],
                ],
                'middleware.security.rate_limit' => [
                    'factory' => static function (): RateLimitMiddleware {
                        $rules = self::parseRateLimitTiers(Env::string('ZEF_SECURITY_RATE_LIMIT_TIERS'));
                        $algorithm = RateLimitAlgorithm::fromString(
                            Env::string('ZEF_SECURITY_RATE_LIMIT_ALGORITHM', 'sliding'),
                        );
                        $limiter = $algorithm === RateLimitAlgorithm::TokenBucket
                            ? new TokenBucketRateLimiter(new HrTimeClock())
                            : new SlidingWindowRateLimiter(new HrTimeClock());

                        return new RateLimitMiddleware(
                            new TieredRateLimiter($limiter),
                            $rules,
                            failOpen: Env::bool('ZEF_SECURITY_RATE_LIMIT_FAIL_OPEN'),
                        );
                    },
                    'deps' => [],
                ],
            ],
            'stack' => $this->buildStack(),
        ];
    }

    /**
     * The tiered rate-limit middleware joins the stack (right after the
     * global security runtime middleware) ONLY when tiers are configured —
     * an unconfigured tier middleware would be inert, and registering it
     * anyway would suggest protection that is not there.
     *
     * @return list<string>
     */
    private function buildStack(): array
    {
        $stack = [
            'middleware.error',
            'middleware.security.runtime',
            'middleware.security',
            'middleware.timing',
            'middleware.cors',
        ];
        if (trim(Env::string('ZEF_SECURITY_RATE_LIMIT_TIERS')) !== '') {
            array_splice($stack, 2, 0, ['middleware.security.rate_limit']);
        }

        return $stack;
    }

    /**
     * Parses ZEF_SECURITY_RATE_LIMIT_TIERS (a JSON list of tier objects)
     * fail-fast: malformed JSON, a non-list root, a non-object entry or an
     * invalid tier are BOOT errors — silently dropping a configured quota
     * would be a security hole.
     *
     * @return list<RateLimitRule>
     */
    private static function parseRateLimitTiers(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }

        try {
            $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('ZEF_SECURITY_RATE_LIMIT_TIERS is not valid JSON: ' . $e->getMessage(), $e->getCode(), $e);
        }
        if (!is_array($decoded) || array_values($decoded) !== $decoded) {
            throw new \RuntimeException('ZEF_SECURITY_RATE_LIMIT_TIERS must be a JSON list of tier objects.');
        }
        $rules = [];
        foreach ($decoded as $index => $entry) {
            if (!is_array($entry)) {
                throw new \RuntimeException(sprintf('ZEF_SECURITY_RATE_LIMIT_TIERS entry %u must be an object.', $index));
            }

            /** @var array<string, mixed> $object */
            $object = $entry;

            try {
                $rules[] = RateLimitRule::fromArray($object);
            } catch (\InvalidArgumentException $e) {
                throw new \RuntimeException(sprintf('ZEF_SECURITY_RATE_LIMIT_TIERS entry %u invalid: %s', $index, $e->getMessage()), $e->getCode(), $e);
            }
        }

        return $rules;
    }

    /**
     * Bug fix #17: logger passed to buildRateLimiter for fallback warning.
     *
     * v2.6.0: the 'redis' option now REQUIRES ZEF_REDIS_URL and actually
     * connects (pconnect + optional auth/db) inside the try block, so an
     * unreachable server fails fast at boot and falls back to in-memory —
     * instead of constructing a never-connected \Redis and returning 503
     * for every request at runtime.
     */
    private static function buildRateLimiter(
        SecurityPolicy $policy,
        ?LoggerInterface $logger = null,
    ): RateLimiterInterface {
        $store = strtolower(trim(Env::string('ZEF_RATE_LIMIT_STORE', 'memory')));

        try {
            return match ($store) {
                'apcu' => new ApcuRateLimiter($policy->rateLimitMaxKeys),
                'redis' => new RedisRateLimiter(
                    new RedisSharedRateLimitStore(self::connectRedis()),
                    $policy->rateLimitMaxKeys,
                ),
                default => new InMemoryRateLimiter($policy->rateLimitMaxKeys),
            };
        } catch (\Throwable $e) {
            $msg = '[ZEF][security] Rate-limit store "' . $store . '" unavailable (' . $e->getMessage() . '); falling back to in-memory per-process limiter.';
            if ($logger instanceof LoggerInterface) {
                $logger->warning($msg);
            } else {
                error_log($msg);
            }

            return new InMemoryRateLimiter($policy->rateLimitMaxKeys);
        }
    }

    /**
     * Builds a connected \Redis client from ZEF_REDIS_URL
     * (redis://[:password@]host[:port][/db]). Throws on failure so the
     * caller can fall back at boot instead of failing every request.
     */
    private static function connectRedis(): \Redis
    {
        if (!class_exists(\Redis::class)) {
            throw new \RuntimeException('The phpredis extension is not installed.');
        }
        $dsn = trim(Env::string('ZEF_REDIS_URL', ''));
        if ($dsn === '') {
            throw new \RuntimeException('ZEF_REDIS_URL is required when ZEF_RATE_LIMIT_STORE=redis.');
        }
        $parts = parse_url($dsn);
        if ($parts === false || !isset($parts['host']) || $parts['host'] === '') {
            throw new \RuntimeException('Invalid ZEF_REDIS_URL DSN.');
        }
        $redis = new \Redis();
        $timeout = (float) (Env::int('ZEF_REDIS_TIMEOUT_MS', 2000, 100, 10000) / 1000);
        if (!$redis->pconnect((string) $parts['host'], (int) ($parts['port'] ?? 6379), $timeout)) {
            throw new \RuntimeException('Unable to connect to Redis.');
        }
        $password = $parts['pass'] ?? null;
        if ($password !== null) {
            $username = $parts['user'] ?? null;
            if (!$redis->auth($username !== null && $username !== '' ? [$username, $password] : $password)) {
                throw new \RuntimeException('Redis authentication failed.');
            }
        }
        $db = isset($parts['path']) ? trim((string) $parts['path'], '/') : '';
        if ($db !== '') {
            // "redis://h/abc" previously (int)-cast to 0 and SILENTLY
            // selected db 0 — a wrong-database isolation bug.
            if (!ctype_digit($db)) {
                // ctype_digit also rejects signed forms like "-1"/"+1":
                // the fail-closed behaviour is correct for both, but the
                // message must say WHY (non-negative integer required).
                throw new \RuntimeException('Redis DB index in ZEF_REDIS_URL must be a non-negative integer.');
            }
            if (!$redis->select((int) $db)) {
                throw new \RuntimeException('Redis SELECT failed.');
            }
        }

        return $redis;
    }

    private function buildCors(): CorsMiddleware
    {
        if (Env::bool('ZEF_CORS_ORIGIN_ANY')) {
            return new CorsMiddleware(['*']);
        }
        $origins = Env::csv('ZEF_CORS_ORIGIN');

        return new CorsMiddleware($origins);
    }
}
