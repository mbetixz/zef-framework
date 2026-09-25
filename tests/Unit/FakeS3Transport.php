<?php

declare(strict_types=1);

/*
 * ZEF Framework — v2.30.0 Ecosystem Ports: recording fake HTTP transport
 * for the S3-compatible storage suite.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\Assert;
use Zef\Framework\Storage\S3HttpResponse;
use Zef\Framework\Storage\S3HttpTransport;

/**
 * Records every request and serves scripted responses (FIFO or a
 * catch-all responder) — no network involved.
 *
 * @internal
 */
final class FakeS3Transport implements S3HttpTransport
{
    /** @var list<array{method:string, url:string, headers:array<string,string>, body:string}> */
    public array $requests = [];

    /** @var list<S3HttpResponse> */
    private array $scripted;

    /** @var null|callable(string,string,array<string,string>,string): S3HttpResponse */
    private $responder;

    /**
     * @param null|list<S3HttpResponse> $scripted
     * @param null|callable(string,string,array<string,string>,string): S3HttpResponse $responder
     */
    public function __construct(?array $scripted = null, ?callable $responder = null)
    {
        $this->scripted = $scripted ?? [];
        $this->responder = $responder;
    }

    #[\Override]
    public function request(string $method, string $url, array $headers, string $body): S3HttpResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        if ($this->scripted !== []) {
            return array_shift($this->scripted);
        }
        if ($this->responder !== null) {
            return ($this->responder)($method, $url, $headers, $body);
        }

        return new S3HttpResponse(200, [], '');
    }

    /**
     * Convenience: the (single) recorded request.
     *
     * @return array{method: string, url: string, headers: array<string, string>, body: string}
     */
    public function single(): array
    {
        if (count($this->requests) !== 1) {
            Assert::fail('Expected exactly one recorded request, got ' . count($this->requests) . '.');
        }

        return $this->requests[0];
    }
}
