<?php

declare(strict_types=1);

namespace Zef\Test\Unit;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zef\Framework\Application;
use Zef\Framework\Http\RequestBodyPolicy;
use Zef\Framework\Http\RequestFactory;
use Zef\Framework\Http\Response;
use Zef\Framework\Runtime\InMemoryWorker;
use Zef\Framework\Runtime\RoadRunnerRuntime;

/** @internal */
final class ApplicationIngressTest extends TestCase
{
    /** @param array<string, string> $headers */
    #[DataProvider('hosts')]
    public function testHostAdmission(string $uri, array $headers, int $status): void
    {
        $app = $this->application($status === 200 ? 1 : 0);
        self::assertSame($status, $app->handle(new ServerRequest('POST', $uri, $headers))->getStatusCode());
    }

    /** @return iterable<string, array{string, array<string, string>, int}> */
    public static function hosts(): iterable
    {
        yield 'untrusted URI' => ['http://evil.invalid/', [], 400];

        yield 'untrusted Host overrides trusted URI' => ['http://example.com/', ['Host' => 'evil.invalid'], 400];

        yield 'missing host' => ['/', [], 400];

        yield 'allowed host' => ['http://example.com/', [], 200];

        yield 'case, root dot and port' => ['http://example.com/', ['Host' => 'EXAMPLE.COM.:8080'], 200];

        yield 'untrusted forwarded host ignored' => ['http://evil.invalid/', ['X-Forwarded-Host' => 'example.com'], 400];
    }

    public function testEmptyAllowlistRemainsUnrestricted(): void
    {
        $app = $this->application(1);
        $app->setTrustedHosts([]);
        self::assertSame(200, $app->handle(new ServerRequest('POST', 'http://other.example/'))->getStatusCode());
    }

    public function testTrustedProxyMatchesNativeHostPolicy(): void
    {
        $server = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/', 'HTTP_HOST' => 'internal.example',
            'HTTP_X_FORWARDED_HOST' => 'example.com:443, proxy.example', 'REMOTE_ADDR' => '10.0.0.1'];
        $app = $this->application(2);
        $app->setTrustedProxies(['10.0.0.1']);
        $native = RequestFactory::fromServer($server, ['example.com'], ['10.0.0.1']);
        $worker = new ServerRequest('POST', 'http://internal.example/', [
            'X-Forwarded-Host' => 'example.com:443, proxy.example',
        ], '', '1.1', $server);
        self::assertSame(200, $app->handle($native)->getStatusCode());
        self::assertSame(200, $app->handle($worker)->getStatusCode());
    }

    /** @param array<string, string> $headers */
    #[DataProvider('oversizedBodies')]
    public function testOversizedBodiesNeverReachHandler(string $body, array $headers): void
    {
        $app = $this->application(0);
        self::assertSame(413, $app->handle(new ServerRequest('POST', 'http://example.com/', $headers, $body))->getStatusCode());
    }

    /** @return iterable<string, array{string, array<string, string>}> */
    public static function oversizedBodies(): iterable
    {
        yield 'declared length' => ['', ['Content-Length' => '5']];

        yield 'missing length' => ['12345', []];

        yield 'understated length' => ['12345', ['Content-Length' => '1']];
    }

    public function testBodyAtLimitAndCursorArePreserved(): void
    {
        $app = $this->application(1, '234');
        $request = new ServerRequest('POST', 'http://example.com/', ['Content-Length' => '4'], '1234');
        $request->getBody()->seek(1);
        self::assertSame(200, $app->handle($request)->getStatusCode());
    }

    public function testUnknownSizeNonSeekableBodyIsBoundedBeforeDispatch(): void
    {
        $app = $this->application(0);
        $request = new ServerRequest('POST', 'http://example.com/');
        self::assertSame(413, $app->handle($request->withBody($this->unknownBody('12345')))->getStatusCode());
    }

    public function testUnknownSizeNonSeekableBodyRemainsReadable(): void
    {
        $app = $this->application(1, '1234');
        $request = new ServerRequest('POST', 'http://example.com/');
        self::assertSame(200, $app->handle($request->withBody($this->unknownBody('1234')))->getStatusCode());
    }

    public function testSeekableBodyIsCheckedFromStartEvenIfAlreadyConsumed(): void
    {
        $app = $this->application(0);
        $request = new ServerRequest('POST', 'http://example.com/', [], '12345');
        $request->getBody()->getContents();
        self::assertSame(413, $app->handle($request)->getStatusCode());
    }

    public function testRoadRunnerRejectsBadRequestsAndContinuesServing(): void
    {
        $app = $this->application(1, '1234');
        $worker = new InMemoryWorker([
            new ServerRequest('POST', 'http://evil.invalid/'),
            new ServerRequest('POST', 'http://example.com/', [], '12345'),
            new ServerRequest('POST', 'http://example.com/', [], '1234'),
        ]);
        $runtime = new RoadRunnerRuntime($app, $worker, maxJobs: 3, installSignalHandlers: false);
        self::assertSame(0, $runtime->run());
        self::assertSame([400, 413, 200], array_map(static fn (ResponseInterface $response): int => $response->getStatusCode(), $worker->responses()));
    }

    private function application(int $calls, string $body = ''): Application
    {
        $app = new Application(bodyPolicy: new RequestBodyPolicy(4));
        $app->setTrustedHosts(['example.com']);
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::exactly($calls))->method('handle')->willReturnCallback(
            static function (ServerRequestInterface $request) use ($body): ResponseInterface {
                self::assertSame($body, $request->getBody()->getContents());

                return new Response(200);
            },
        );
        $app->getContainer()->register('ingress.handler', static fn (): RequestHandlerInterface => $handler);
        $app->getRouter()->add('POST', '/', 'ingress.handler');

        return $app;
    }

    private function unknownBody(string $body): StreamInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $offset = 0;
        $stream->method('getSize')->willReturn(null);
        $stream->method('isSeekable')->willReturn(false);
        $stream->method('isReadable')->willReturn(true);
        $stream->method('getContents')->willReturnCallback(static function () use (&$offset, $body): string {
            $remaining = substr($body, $offset);
            $offset = strlen($body);

            return $remaining;
        });
        $stream->method('eof')->willReturnCallback(static function () use (&$offset, $body): bool {
            return $offset >= strlen($body);
        });
        $stream->method('read')->willReturnCallback(static function (int $length) use (&$offset, $body): string {
            self::assertLessThanOrEqual(5, $length, 'Ingress reads at most the limit plus one byte.');
            $chunk = substr($body, $offset, $length);
            $offset += strlen($chunk);

            return $chunk;
        });

        return $stream;
    }
}
