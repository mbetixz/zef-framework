<?php

declare(strict_types=1);

namespace Zef\Test\Unit;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zef\Framework\Application;
use Zef\Framework\Config\ConfigProviderInterface;
use Zef\Framework\Http\RequestBodyPolicy;
use Zef\Framework\Http\RequestFactory;
use Zef\Framework\Http\Response;
use Zef\Framework\Runtime\InMemoryWorker;
use Zef\Framework\Runtime\RoadRunnerRuntime;

/** @internal */
final class ApplicationIngressTest extends TestCase
{
    private IngressEchoHandler $handler;

    protected function setUp(): void
    {
        $this->handler = new IngressEchoHandler();
    }

    #[DataProvider('requests')]
    public function testDirectHandleEnforcesIngressBeforeDispatch(ServerRequestInterface $request, int $status): void
    {
        $expectedBody = (string) $request->getBody();
        $request->getBody()->rewind();
        $response = $this->application()->handle($request);
        self::assertSame($status, $response->getStatusCode());
        self::assertSame($status === 200 ? 1 : 0, $this->handler->calls);
        if ($status === 200) {
            self::assertSame($expectedBody, (string) $response->getBody());
        }
    }

    /** @return iterable<string, array{ServerRequestInterface, int}> */
    public static function requests(): iterable
    {
        yield 'allowed host and exact body limit' => [new ServerRequest('POST', 'http://example.com/', [], '1234'), 200];

        yield 'case insensitive host with port' => [new ServerRequest('POST', 'http://EXAMPLE.com:8080/'), 200];

        yield 'untrusted URI' => [new ServerRequest('POST', 'http://untrusted.invalid/'), 400];

        yield 'untrusted Host header' => [new ServerRequest('POST', 'http://example.com/', ['Host' => 'untrusted.invalid']), 400];

        yield 'blank Host header' => [new ServerRequest('POST', 'http://example.com/', ['Host' => ' ']), 400];

        yield 'missing authority' => [new ServerRequest('POST', '/'), 400];

        yield 'oversized declared length' => [new ServerRequest('POST', 'http://example.com/', ['Content-Length' => '5']), 413];

        yield 'oversized actual body without length' => [new ServerRequest('POST', 'http://example.com/', [], '12345'), 413];

        yield 'understated length' => [new ServerRequest('POST', 'http://example.com/', ['Content-Length' => '1'], '12345'), 413];

        yield 'untrusted forwarded host ignored' => [new ServerRequest('POST', 'http://example.com/', ['X-Forwarded-Host' => 'untrusted.invalid']), 200];
    }

    public function testUnknownSizeNonSeekableBodyIsBoundedBeforeDispatch(): void
    {
        $body = $this->unknownSizeBody('123456789');
        $response = $this->application()->handle(new ServerRequest('POST', 'http://example.com/', [], $body));
        self::assertSame(413, $response->getStatusCode());
        self::assertSame(0, $this->handler->calls);
        self::assertSame(5, $body->tell());
    }

    public function testNonSeekableBodyAtLimitRemainsAvailableToHandler(): void
    {
        $response = $this->application()->handle(new ServerRequest('POST', 'http://example.com/', [], $this->unknownSizeBody('1234')));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('1234', (string) $response->getBody());
    }

    public function testSeekableBodyIsCheckedFromStartAndPositionIsPreserved(): void
    {
        $body = Stream::create('1234');
        $body->seek(2);
        $response = $this->application()->handle(new ServerRequest('POST', 'http://example.com/', [], $body));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('34', (string) $response->getBody());
        self::assertSame(2, $body->tell());
    }

    public function testSeekedOversizedBodyCannotBypassLimit(): void
    {
        $body = $this->unknownSizeBody('12345', true);
        $body->seek(4);
        $response = $this->application()->handle(new ServerRequest('POST', 'http://example.com/', [], $body));
        self::assertSame(413, $response->getStatusCode());
        self::assertSame(4, $body->tell());
        self::assertSame(0, $this->handler->calls);
    }

    public function testNativeTrustedProxyRequestStillPasses(): void
    {
        $app = $this->application();
        $app->setTrustedProxies(['10.0.0.1']);
        $request = RequestFactory::fromServer([
            'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/', 'HTTP_HOST' => 'internal.proxy',
            'REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_HOST' => 'example.com',
        ], ['example.com'], ['10.0.0.1'], new RequestBodyPolicy(4));
        self::assertSame(200, $app->handle($request)->getStatusCode());
    }

    public function testRoadRunnerRejectsBadRequestsAndContinuesServing(): void
    {
        $worker = new InMemoryWorker([
            new ServerRequest('POST', 'http://untrusted.invalid/'),
            new ServerRequest('POST', 'http://example.com/', [], $this->unknownSizeBody('12345')),
            new ServerRequest('POST', 'http://example.com/', [], '1234'),
        ]);
        $runtime = new RoadRunnerRuntime($this->application(), $worker, maxJobs: 3, installSignalHandlers: false);
        self::assertSame(0, $runtime->run());
        self::assertSame([400, 413, 200], array_map(static fn (ResponseInterface $response): int => $response->getStatusCode(), $worker->responses()));
        self::assertSame(1, $this->handler->calls);
        self::assertSame('1234', (string) $worker->responses()[2]->getBody());
    }

    private function unknownSizeBody(string $data, bool $seekable = false): StreamInterface
    {
        $inner = Stream::create($data);
        $body = $this->createMock(StreamInterface::class);
        $body->method('getSize')->willReturn(null);
        $body->method('isSeekable')->willReturn($seekable);
        $body->method('read')->willReturnCallback($inner->read(...));
        $body->method('eof')->willReturnCallback($inner->eof(...));
        $body->method('tell')->willReturnCallback($inner->tell(...));
        $body->method('seek')->willReturnCallback($inner->seek(...));
        $body->method('rewind')->willReturnCallback($inner->rewind(...));

        return $body;
    }

    private function application(): Application
    {
        $app = new Application(bodyPolicy: new RequestBodyPolicy(4));
        $app->setTrustedHosts(['example.com']);
        $app->addProvider(new readonly class($this->handler) implements ConfigProviderInterface {
            public function __construct(private RequestHandlerInterface $handler) {}

            public function getModuleName(): string
            {
                return 'ingress-test';
            }

            /** @return array<string, mixed> */
            public function getConfig(): array
            {
                return [
                    'services' => ['echo' => ['factory' => fn (): RequestHandlerInterface => $this->handler, 'deps' => []]],
                    'routes' => [['method' => 'POST', 'path' => '/', 'handler' => 'echo']],
                ];
            }
        });

        return $app;
    }
}

final class IngressEchoHandler implements RequestHandlerInterface
{
    public int $calls = 0;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        ++$this->calls;

        return new Response(200, [], $request->getBody()->getContents());
    }
}
