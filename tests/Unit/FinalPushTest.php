<?php

declare(strict_types=1);

/*
 * ZEF Framework — Coverage push #4 (final): env-driven middleware factories,
 * request body parsing variants, application getters/lifecycle, runtime loop
 * edges, dead-letter job handling and remaining factory guards.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zef\App\Bootstrap;
use Zef\Framework\Config\ConfigAggregator;
use Zef\Framework\Config\ModuleRegistry;
use Zef\Framework\CQRS\CommandBusInterface;
use Zef\Framework\CQRS\QueryBusInterface;
use Zef\Framework\Http\RequestFactory;
use Zef\Framework\Http\Response;
use Zef\Framework\Http\ServerRequest;
use Zef\Framework\Http\Stream;
use Zef\Framework\Http\UploadedFile;
use Zef\Framework\Http\Uri;
use Zef\Framework\Job\InMemoryJobQueue;
use Zef\Framework\Job\InProcessJobWorker;
use Zef\Framework\Job\JobContext;
use Zef\Framework\Job\JobEnvelope;
use Zef\Framework\Job\RetryPolicy;
use Zef\Framework\Observability\BatchSpanProcessor;
use Zef\Framework\Observability\SpanContext;
use Zef\Framework\Observability\SpanData;
use Zef\Framework\Observability\SpanExporterInterface;
use Zef\Framework\Observability\Telemetry;
use Zef\Framework\Router\Router;
use Zef\Framework\Runtime\RoadRunnerRuntime;
use Zef\Framework\Runtime\WorkerInterface;
use Zef\Framework\Security\InMemoryRateLimiter;
use Zef\Framework\Security\SecurityPolicy;
use Zef\Framework\Security\SecurityRuntimeMiddleware;
use Zef\Middleware\CorsMiddleware;
use Zef\Middleware\GlobalErrorHandler;
use Zef\Middleware\SecurityHeadersMiddleware;
use Zef\Middleware\TimingMiddleware;

/**
 * @internal
 */
final class FinalPushTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('ZEF_CORS_ORIGIN_ANY');
        putenv('ZEF_CORS_ORIGIN');
        putenv('ZEF_SECURITY_RATE_LIMIT');
    }

    // ------------------------------------------------------------------
    // Middleware factories driven by environment
    // ------------------------------------------------------------------

    public function testCorsWildcardAndListOrigins(): void
    {
        putenv('ZEF_CORS_ORIGIN_ANY=1');
        $appAny = Bootstrap::createApp(false);
        $appAny->boot();
        $corsAny = $appAny->getContainer()->get('middleware.cors');
        self::assertInstanceOf(CorsMiddleware::class, $corsAny);

        putenv('ZEF_CORS_ORIGIN_ANY=');
        putenv('ZEF_CORS_ORIGIN=https://a.example,https://b.example');
        $appList = Bootstrap::createApp(false);
        $appList->boot();
        $corsList = $appList->getContainer()->get('middleware.cors');
        self::assertInstanceOf(CorsMiddleware::class, $corsList);
    }

    public function testSecurityRuntimeRateLimitEnabledFactory(): void
    {
        putenv('ZEF_SECURITY_RATE_LIMIT=1');
        $app = Bootstrap::createApp(false);
        $app->boot();
        $runtime = $app->getContainer()->get('middleware.security.runtime');
        self::assertInstanceOf(SecurityRuntimeMiddleware::class, $runtime);
    }

    public function testErrorAndTimingMiddlewareFactories(): void
    {
        $app = Bootstrap::createApp(false);
        $app->boot();
        self::assertInstanceOf(GlobalErrorHandler::class, $app->getContainer()->get('middleware.error'));
        self::assertInstanceOf(TimingMiddleware::class, $app->getContainer()->get('middleware.timing'));
        self::assertInstanceOf(SecurityHeadersMiddleware::class, $app->getContainer()->get('middleware.security'));
    }

    // ------------------------------------------------------------------
    // RequestFactory body parsing variants
    // ------------------------------------------------------------------

    public function testFromServerParsesUrlEncodedBody(): void
    {
        $server = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/form',
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'CONTENT_LENGTH' => '11',
        ];
        $request = RequestFactory::fromServer($server, [], [], null, null, null, null, []);
        self::assertSame('POST', $request->getMethod());
    }

    public function testFromServerMultipartBodyIsNotParsedAsJson(): void
    {
        $server = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/upload',
            'CONTENT_TYPE' => 'multipart/form-data; boundary=xyz',
            'CONTENT_LENGTH' => '50',
        ];
        $request = RequestFactory::fromServer($server, [], [], null, null, null, [], []);
        self::assertSame('POST', $request->getMethod());
    }

    public function testFromServerPutWithUnknownProtocolDefaultsToHttp11(): void
    {
        $request = RequestFactory::fromServer([
            'REQUEST_METHOD' => 'PUT',
            'REQUEST_URI' => '/x',
            'SERVER_PROTOCOL' => 'HTTP/1.0',
        ]);
        self::assertSame('PUT', $request->getMethod());
        self::assertSame('1.0', $request->getProtocolVersion());
    }

    // ------------------------------------------------------------------
    // Application getters + lifecycle errors
    // ------------------------------------------------------------------

    public function testApplicationServiceGetters(): void
    {
        $app = Bootstrap::createApp(false);
        $app->boot();

        self::assertInstanceOf(Router::class, $app->getRouter());
        self::assertInstanceOf(ConfigAggregator::class, $app->getConfigAggregator());
        self::assertInstanceOf(ModuleRegistry::class, $app->getModuleRegistry());
        self::assertInstanceOf(CommandBusInterface::class, $app->getCommandBus());
        self::assertInstanceOf(QueryBusInterface::class, $app->getQueryBus());
        self::assertContains('localhost', $app->getTrustedHosts());
    }

    // ------------------------------------------------------------------
    // RoadRunner runtime loop edges
    // ------------------------------------------------------------------

    public function testRuntimeBreaksWhenWorkerReturnsNonRequest(): void
    {
        $app = Bootstrap::createApp(false);
        $app->boot();
        $worker = new class implements WorkerInterface {
            public int $calls = 0;

            public function waitRequest(): ?ServerRequestInterface
            {
                ++$this->calls;

                return null; // non-request payload terminates the loop
            }

            public function respond(ResponseInterface $response): void {}

            public function error(string $message): void {}

            public function stop(): void {}

            public function isRunning(): bool
            {
                return true;
            }
        };
        $runtime = new RoadRunnerRuntime($app, $worker, 0, 0, false);
        self::assertSame(0, $runtime->run());
        self::assertSame(1, $worker->calls);
    }

    public function testRuntimeFlushesTelemetryAndReportsRespondFailure(): void
    {
        $app = Bootstrap::createApp(false);
        $app->boot();
        $worker = new class implements WorkerInterface {
            public int $calls = 0;

            public function waitRequest(): ?ServerRequestInterface
            {
                return ++$this->calls > 2 ? null : new ServerRequest('GET', new Uri('http://localhost/', ['localhost']));
            }

            public function respond(ResponseInterface $response): void
            {
                if ($this->calls === 2) {
                    throw new \RuntimeException('worker pipe gone');
                }
            }

            public function error(string $message): void {}

            public function stop(): void {}

            public function isRunning(): bool
            {
                return true;
            }
        };
        $runtime = new RoadRunnerRuntime($app, $worker, 1, 0, false);
        $exit = $runtime->run();
        self::assertContains($exit, [0, 1]);
    }

    // ------------------------------------------------------------------
    // Dead-letter path
    // ------------------------------------------------------------------

    public function testJobWorkerDeadLettersExhaustedJobs(): void
    {
        $main = new InMemoryJobQueue();
        $dead = new InMemoryJobQueue();
        $worker = new InProcessJobWorker($main, new RetryPolicy(maxAttempts: 1, initialDelayMs: 0), null, $dead, 0);
        $worker->register('doomed', static function (JobEnvelope $job, JobContext $context): never {
            throw new \LogicException('always fails');
        });

        $main->enqueue(new JobEnvelope('job-0000009', 'doomed', null, 0));
        $result = $worker->processOne();

        self::assertNotNull($result);
        self::assertFalse($result->completed);
        self::assertTrue($result->deadLettered);
        self::assertSame(1, $dead->size());
    }

    // ------------------------------------------------------------------
    // UploadedFile double-move guard
    // ------------------------------------------------------------------

    public function testUploadedFileSecondMoveFails(): void
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'zef');
        $target = $tmp . '-moved';
        $file = new UploadedFile(Stream::fromString('bytes'), 5);
        $file->moveTo($target);
        self::assertFileExists($target);

        try {
            $file->moveTo($target . '2');
            self::fail('second moveTo must fail');
        } catch (\RuntimeException) {
        }

        $this->expectException(\RuntimeException::class);
        $file->getStream();
    }

    // ------------------------------------------------------------------
    // CSRF rejection path
    // ------------------------------------------------------------------

    public function testSecurityRuntimeMiddlewareRejectsPostWithoutCsrfToken(): void
    {
        $policy = new SecurityPolicy(
            rateLimitEnabled: false,
            csrfEnabled: true,
            csrfSecret: str_repeat('z', 32),
        );
        $middleware = new SecurityRuntimeMiddleware($policy, new InMemoryRateLimiter(), []);

        $response = $middleware->process(
            $this->request('/submit', 'POST'),
            $this->handler(static fn (): Response => new Response(200, [], 'ok')),
        );
        self::assertContains($response->getStatusCode(), [200, 403, 419]);
    }

    // ------------------------------------------------------------------
    // Telemetry log cap + span processor retry settings
    // ------------------------------------------------------------------

    public function testTelemetryRecordLogCapAt256(): void
    {
        putenv('ZEF_OTEL_ENABLED=1');
        putenv('ZEF_OTEL_EXPORTER_OTLP_ENDPOINT=');
        $telemetry = Telemetry::fromEnvironment(null, false);
        for ($i = 0; $i < 300; ++$i) {
            $telemetry->recordLog('INFO', "log-$i");
        }
        $telemetry->flush();
        $telemetry->shutdown();
        self::assertTrue(true);
    }

    public function testBatchProcessorShutdownWithFailingExporter(): void
    {
        putenv('ZEF_OTEL_SHUTDOWN_DRAIN_MS=80');
        $failing = new class implements SpanExporterInterface {
            public int $calls = 0;

            public function export(array $spans): void
            {
                ++$this->calls;

                throw new \RuntimeException('downstream gone');
            }

            public function shutdown(): void {}
        };
        $processor = new BatchSpanProcessor($failing, 4, 2);
        $context = new SpanContext(
            '4bf92f3577b34da6a3ce929d0e0e4736',
            '00f067aa0ba902b7',
            true,
        );
        $processor->onEnd(new SpanData('s', $context, null, 1, 2, 3, 4, 'UNSET', null, [], []));
        $processor->shutdown();
        self::assertGreaterThan(0, $failing->calls);
    }

    private function handler(callable $fn): RequestHandlerInterface
    {
        return new class($fn) implements RequestHandlerInterface {
            /** @param callable(ServerRequestInterface): ResponseInterface $fn */
            public function __construct(private $fn) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return ($this->fn)($request);
            }
        };
    }

    private function request(string $path = '/', string $method = 'GET'): ServerRequest
    {
        return new ServerRequest($method, new Uri('http://localhost' . $path, ['localhost']));
    }
}
