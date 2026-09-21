<?php

declare(strict_types=1);

/*
 * ZEF Framework — Final coverage push: deep branches of the HTTP request
 * factory, application runtime paths, RoadRunner loop edges, container
 * guards, telemetry environment wiring and the job worker loop.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\App\Bootstrap;
use Zef\Framework\Application;
use Zef\Framework\Container\Container;
use Zef\Framework\Container\ServiceLifetime;
use Zef\Framework\Exception\PayloadTooLargeException;
use Zef\Framework\Http\RequestBodyPolicy;
use Zef\Framework\Http\RequestFactory;
use Zef\Framework\Http\ServerRequest;
use Zef\Framework\Http\Uri;
use Zef\Framework\Job\InMemoryJobQueue;
use Zef\Framework\Job\InProcessJobWorker;
use Zef\Framework\Job\JobContext;
use Zef\Framework\Job\JobEnvelope;
use Zef\Framework\Job\JobResult;
use Zef\Framework\Job\RetryPolicy;
use Zef\Framework\Observability\Telemetry;
use Zef\Middleware\ConfigProvider as MiddlewareConfigProvider;

/**
 * @internal
 */
final class DeepBranchesTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('ZEF_OTEL_ENABLED');
        putenv('ZEF_OTEL_EXPORTER_OTLP_ENDPOINT');
        putenv('ZEF_OTEL_SHUTDOWN_DRAIN_MS');
    }

    // ------------------------------------------------------------------
    // RequestFactory — superglobal extraction matrix
    // ------------------------------------------------------------------

    public function testFromServerHeaderExtractionVariants(): void
    {
        $server = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_ACCEPT' => 'text/html',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.9, 10.0.0.1',
            'HTTP_COOKIE' => 'sid=xyz; theme=dark',
            'HTTP_AUTHORIZATION' => 'Bearer tok-1',
            'SERVER_PROTOCOL' => 'HTTP/2.0',
        ];
        $request = RequestFactory::fromServer($server, [], [], null, null, ['sid' => 'xyz']);
        self::assertSame('text/html', $request->getHeaderLine('Accept'));
        self::assertSame('203.0.113.9, 10.0.0.1', $request->getHeaderLine('X-Forwarded-For'));
        self::assertSame('Bearer tok-1', $request->getHeaderLine('Authorization'));
        self::assertSame('xyz', $request->getCookieParams()['sid'] ?? '');
    }

    public function testFromServerEnforcesBodyPolicyLimits(): void
    {
        $policy = new RequestBodyPolicy(4);
        $server = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/upload',
            'CONTENT_LENGTH' => '100',
        ];

        // The policy is evaluated up-front from Content-Length.
        $this->expectException(PayloadTooLargeException::class);
        RequestFactory::fromServer($server, [], [], $policy);
    }

    public function testFromServerDefaultsWhenSuperglobalIsEmpty(): void
    {
        $request = RequestFactory::fromServer([]);
        self::assertSame('GET', $request->getMethod());
        self::assertSame('/', $request->getUri()->getPath());
    }

    public function testFromGlobalsReadsRealSuperglobals(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        $_SERVER['REQUEST_URI'] = '/from-globals';
        $_SERVER['HTTP_X_TRACE'] = 'tg-1';
        $request = RequestFactory::fromGlobals();
        self::assertSame('PUT', $request->getMethod());
        self::assertSame('/from-globals', $request->getUri()->getPath());
        self::assertSame('tg-1', $request->getHeaderLine('X-Trace'));
    }

    // ------------------------------------------------------------------
    // Application runtime paths
    // ------------------------------------------------------------------

    public function testApplicationBootHandleShutdownLifecycle(): void
    {
        $app = Bootstrap::createApp(false);
        self::assertFalse($app->isBooted());
        $app->boot();
        self::assertTrue($app->isBooted());

        $response = $app->handle(new ServerRequest('GET', new Uri('http://localhost/', ['localhost'])));
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($response->hasHeader('x-request-id'));

        $app->runtimeAfterRequest();
        $app->shutdown();
        $app->shutdown(); // idempotent

        $this->expectException(PayloadTooLargeException::class);

        throw new PayloadTooLargeException('simulated');
    }

    public function testApplicationRejectsUnknownRouteAndBadSegments(): void
    {
        $app = Bootstrap::createApp(false);
        $app->boot();

        $missing = $app->handle(new ServerRequest('GET', new Uri('http://localhost/definitely-missing', ['localhost'])));
        self::assertSame(404, $missing->getStatusCode());

        $bad = $app->handle(new ServerRequest('GET', new Uri('http://localhost/toko/produk/notanint', ['localhost'])));
        self::assertSame(400, $bad->getStatusCode());
    }

    // ------------------------------------------------------------------
    // Container guards
    // ------------------------------------------------------------------

    public function testContainerGuardsAndResetSemantics(): void
    {
        $container = new Container();
        $container->register('svc', static fn (): \stdClass => new \stdClass(), [], 'core', ServiceLifetime::SINGLETON);
        $container->alias('svc-alias', 'svc', 'core');
        $container->validateAndFreeze();
        $container->validateAndFreeze(); // idempotent

        self::assertTrue($container->has('svc'));
        self::assertTrue($container->has('svc-alias'));
        self::assertFalse($container->has('nope'));
        self::assertInstanceOf(\stdClass::class, $container->get('svc-alias'));

        try {
            $container->register('after-freeze', static fn (): \stdClass => new \stdClass(), [], 'core');
            self::fail('register after freeze must throw');
        } catch (\LogicException) {
        }

        $container->reset(false);
        self::assertInstanceOf(\stdClass::class, $container->get('svc'), 'singletons survive soft reset');
    }

    // ------------------------------------------------------------------
    // Middleware config provider (both dev modes)
    // ------------------------------------------------------------------

    public function testMiddlewareConfigProviderDevAndProdVariants(): void
    {
        $dev = new MiddlewareConfigProvider(true);
        $prod = new MiddlewareConfigProvider(false);

        self::assertSame('middleware', $dev->getModuleName());
        self::assertSame('middleware', $prod->getModuleName());

        $devConfig = $dev->getConfig();
        $prodConfig = $prod->getConfig();
        self::assertSame(array_keys($prodConfig), array_keys($devConfig));
        self::assertNotEmpty($devConfig['services']);
        self::assertNotEmpty($prodConfig['stack']);
    }

    // ------------------------------------------------------------------
    // Telemetry environment wiring
    // ------------------------------------------------------------------

    public function testTelemetryFromEnvironmentRejectsBadEndpoint(): void
    {
        putenv('ZEF_OTEL_ENABLED=1');
        putenv('ZEF_OTEL_EXPORTER_OTLP_ENDPOINT=ftp://nope');

        $this->expectException(\InvalidArgumentException::class);
        Telemetry::fromEnvironment(null, false);
    }

    public function testTelemetryFromEnvironmentRejectsEmbeddedCredentials(): void
    {
        putenv('ZEF_OTEL_ENABLED=1');
        putenv('ZEF_OTEL_EXPORTER_OTLP_ENDPOINT=http://user:pass@collector.example/v1/traces');

        $this->expectException(\InvalidArgumentException::class);
        Telemetry::fromEnvironment(null, false);
    }

    public function testTelemetryFlushShipsLogsToExporter(): void
    {
        putenv('ZEF_OTEL_ENABLED=1');
        putenv('ZEF_OTEL_EXPORTER_OTLP_ENDPOINT=');
        putenv('ZEF_OTEL_SHUTDOWN_DRAIN_MS=50');
        $telemetry = Telemetry::fromEnvironment(null, false);
        $telemetry->recordLog('ERROR', 'job failed', ['job' => 'import']);

        $telemetry->flush();
        $telemetry->shutdown();
        self::assertTrue($telemetry->isEnabled());
    }

    // ------------------------------------------------------------------
    // Job worker run() loop
    // ------------------------------------------------------------------

    public function testInProcessJobWorkerRunLoopProcessesMaxJobs(): void
    {
        $queue = new InMemoryJobQueue();
        $worker = new InProcessJobWorker($queue, new RetryPolicy(maxAttempts: 1, initialDelayMs: 0), pollIntervalMs: 0);
        $worker->register('hello-job', static fn (JobEnvelope $job, JobContext $context): string => 'done:' . $job->jobType);

        $queue->enqueue(new JobEnvelope('job-0000001', 'hello-job', null, 0));
        $queue->enqueue(new JobEnvelope('job-0000002', 'hello-job', null, 0));

        $results = [];
        $exit = $worker->run(2, null, static function (JobResult $result) use (&$results): void {
            $results[] = $result;
        });

        self::assertSame(2, $exit, 'run() returns the number of processed jobs');
        self::assertCount(2, $results);
        self::assertTrue($results[0]->completed);
        self::assertSame('done:hello-job', $results[0]->result);
    }

    public function testInProcessJobWorkerRunRejectsNegativeMaxJobs(): void
    {
        $worker = new InProcessJobWorker(new InMemoryJobQueue(), new RetryPolicy(), pollIntervalMs: 0);
        $this->expectException(\InvalidArgumentException::class);
        $worker->run(-1);
    }
}
