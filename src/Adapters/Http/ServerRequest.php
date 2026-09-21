<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Adapters layer (inbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Http;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriInterface;
use Zef\Framework\Validation\HttpMethodValidator;

final class ServerRequest extends MessageBase implements ServerRequestInterface
{
    use RequestTrait;

    private mixed $parsedBody;

    public function __construct(
        string $method,
        UriInterface $uri,
        private array $serverParams = [],
        private array $cookieParams = [],
        private array $queryParams = [],
        private array $uploadedFiles = [],
        mixed $parsedBody = null,
        array $headers = [],
        ?StreamInterface $body = null,
        string $protocolVersion = '1.1',
        string $requestTarget = '',
        private array $attributes = [],
    ) {
        if (!is_array($parsedBody) && !is_object($parsedBody) && $parsedBody !== null) {
            throw new \InvalidArgumentException('Parsed body must be array, object or null.');
        }
        parent::__construct($body, $headers, $protocolVersion);
        if (!$this->hasHeader('Host') && $uri->getHost() !== '') {
            $this->addInitialHeader('Host', $this->hostHeaderFromUri($uri));
        }
        HttpMethodValidator::assert($method);
        $this->method = $method;
        $this->uri = $uri;
        $this->parsedBody = $parsedBody;
        $this->requestTargetOverride = $requestTarget !== '' ? $requestTarget : null;
        if ($this->requestTargetOverride !== null) {
            self::assertRequestTarget($this->requestTargetOverride);
        }
        $this->assertUploadedTree($this->uploadedFiles);
    }

    #[\Override]
    public function getServerParams(): array
    {
        return $this->serverParams;
    }

    #[\Override]
    public function getCookieParams(): array
    {
        return $this->cookieParams;
    }

    #[\Override]
    public function withCookieParams(array $cookies): ServerRequestInterface
    {
        $n = clone $this;
        $n->cookieParams = $cookies;

        return $n;
    }

    #[\Override]
    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    #[\Override]
    public function withQueryParams(array $query): ServerRequestInterface
    {
        $n = clone $this;
        $n->queryParams = $query;

        return $n;
    }

    #[\Override]
    public function getUploadedFiles(): array
    {
        return $this->uploadedFiles;
    }

    #[\Override]
    public function withUploadedFiles(array $uploadedFiles): ServerRequestInterface
    {
        $this->assertUploadedTree($uploadedFiles);
        $n = clone $this;
        $n->uploadedFiles = $uploadedFiles;

        return $n;
    }

    #[\Override]
    public function getParsedBody(): mixed
    {
        return $this->parsedBody;
    }

    /**
     * @param mixed $data validated at runtime; array, object or null accepted
     */
    #[\Override]
    public function withParsedBody($data): ServerRequestInterface
    {
        if (!is_array($data) && !is_object($data) && $data !== null) {
            throw new \InvalidArgumentException('Parsed body must be array, object or null.');
        }
        $n = clone $this;
        $n->parsedBody = $data;

        return $n;
    }

    #[\Override]
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    #[\Override]
    public function getAttribute(string $name, mixed $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    #[\Override]
    public function withAttribute(string $name, mixed $value): ServerRequestInterface
    {
        $n = clone $this;
        $n->attributes[$name] = $value;

        return $n;
    }

    #[\Override]
    public function withoutAttribute(string $name): ServerRequestInterface
    {
        $n = clone $this;
        unset($n->attributes[$name]);

        return $n;
    }

    private function assertUploadedTree(array $tree): void
    {
        foreach ($tree as $value) {
            if ($value instanceof UploadedFileInterface) {
                continue;
            }
            if (is_array($value)) {
                $this->assertUploadedTree($value);

                continue;
            }

            throw new \InvalidArgumentException('Uploaded files must contain only UploadedFileInterface leaves.');
        }
    }
}
