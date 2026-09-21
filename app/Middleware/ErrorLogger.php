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

/**
 * Retained for legacy modules; core now depends on Psr\Log\LoggerInterface.
 */
final class ErrorLogger
{
    public function __construct(private readonly bool $includeMessage = false) {}

    public function log(string $correlationId, \Throwable $e, ServerRequestInterface $request): void
    {
        $message = \Zef\Framework\Observability\TelemetrySanitizer::redact($e->getMessage());
        $entry = [
            'timestamp'      => date(DATE_ATOM),
            'level'          => 'error',
            'correlation_id' => $correlationId,
            'method'         => $request->getMethod(),
            'path'           => $request->getUri()->getPath(),
            'exception'      => get_class($e),
        ];
        if ($this->includeMessage) {
            $entry['message'] = $message;
        }
        $json = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        error_log($json === false ? 'ZEF error logging failure' : $json);
    }
}
