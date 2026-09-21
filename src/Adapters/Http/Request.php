<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Adapters layer (inbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Http;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Zef\Framework\Validation\HttpMethodValidator;

final class Request extends MessageBase implements RequestInterface
{
    use RequestTrait;

    public function __construct(
        string $method,
        UriInterface $uri,
        array $headers = [],
        ?StreamInterface $body = null,
        string $protocolVersion = '1.1',
        string $requestTarget = '',
    ) {
        parent::__construct($body, $headers, $protocolVersion);
        HttpMethodValidator::assert($method);
        if (!$this->hasHeader('Host') && $uri->getHost() !== '') {
            $this->addInitialHeader('Host', $this->hostHeaderFromUri($uri));
        }
        $this->method = $method;
        $this->uri = $uri;
        $this->requestTargetOverride = $requestTarget !== '' ? $requestTarget : null;
        if ($this->requestTargetOverride !== null) {
            self::assertRequestTarget($this->requestTargetOverride);
        }
    }
}
