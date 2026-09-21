<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Demo application
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Middleware;

use Zef\Framework\Config\ConfigProviderInterface;
use Zef\Framework\Foundation\Env;
use Zef\Framework\Http\JsonResponse;
use Zef\Framework\Http\Response;
use Zef\Framework\Security\InMemoryRateLimiter;
use Zef\Framework\Security\OriginPolicy;
use Zef\Framework\Security\SecurityPolicy;
use Zef\Framework\Security\SecurityRuntimeMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ConfigProvider implements ConfigProviderInterface
{
    public function __construct(private readonly bool $devMode = false) {}

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
                    'factory' => static fn(\Psr\Container\ContainerInterface $c): GlobalErrorHandler =>
                        new GlobalErrorHandler(
                            $c->get(\Psr\Log\LoggerInterface::class),
                            new ErrorResponseFactory($devMode),
                        ),
                    'deps' => [\Psr\Log\LoggerInterface::class],
                ],
                'middleware.timing' => [
                    'factory' => static fn(): TimingMiddleware => new TimingMiddleware(),
                    'deps'    => [],
                ],
                'middleware.cors' => [
                    'factory' => static fn(): CorsMiddleware => self::buildCors(),
                    'deps'    => [],
                ],
                'middleware.security' => [
                    'factory' => static function (): SecurityHeadersMiddleware {
                        return new SecurityHeadersMiddleware([
                            'hsts' => Env::bool('ZEF_SECURITY_HSTS'),
                            'csp'  => Env::bool('ZEF_SECURITY_CSP'),
                        ]);
                    },
                    'deps' => [],
                ],
                'middleware.security.runtime' => [
                    'factory' => static function (\Psr\Container\ContainerInterface $c): SecurityRuntimeMiddleware {
                        $logger = null;
                        try {
                            $logger = $c->get(\Psr\Log\LoggerInterface::class);
                        } catch (\Throwable) {}
                        $policy = SecurityPolicy::fromEnvironment($logger);
                        $rateLimiter = self::buildRateLimiter($policy, $logger);
                        return new SecurityRuntimeMiddleware($policy, $rateLimiter);
                    },
                    'deps' => [\Psr\Log\LoggerInterface::class],
                ],
            ],
            'stack' => [
                'middleware.error',
                'middleware.security.runtime',
                'middleware.security',
                'middleware.timing',
                'middleware.cors',
            ],
        ];
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
        ?\Psr\Log\LoggerInterface $logger = null,
    ): \Zef\Framework\Security\RateLimiterInterface {
        $store = strtolower(trim(Env::string('ZEF_RATE_LIMIT_STORE', 'memory')));
        try {
            return match ($store) {
                'apcu'  => new \Zef\Framework\Security\ApcuRateLimiter($policy->rateLimitMaxKeys),
                'redis' => new \Zef\Framework\Security\RedisRateLimiter(
                    new \Zef\Framework\Security\RedisSharedRateLimitStore(self::connectRedis()),
                    $policy->rateLimitMaxKeys,
                ),
                default => new InMemoryRateLimiter($policy->rateLimitMaxKeys),
            };
        } catch (\Throwable $e) {
            $msg = '[ZEF][security] Rate-limit store "' . $store . '" unavailable (' . $e->getMessage() . '); falling back to in-memory per-process limiter.';
            if ($logger !== null) {
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

    private static function buildCors(): CorsMiddleware
    {
        if (Env::bool('ZEF_CORS_ORIGIN_ANY')) {
            return new CorsMiddleware(['*']);
        }
        $origins = Env::csv('ZEF_CORS_ORIGIN');
        return new CorsMiddleware($origins);
    }
}
