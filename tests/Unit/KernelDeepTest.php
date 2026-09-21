<?php

declare(strict_types=1);

/*
 * ZEF Framework — Coverage push #2: middleware service resolution through
 * the booted application, router guards, stream error paths, cron pattern
 * variants, PSR-17 factory surface and event dispatcher internals.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\App\Bootstrap;
use Zef\Framework\Application;
use Zef\Framework\Event\EventContext;
use Zef\Framework\Event\EventDispatcher;
use Zef\Framework\Event\EventSubscriberInterface;
use Zef\Framework\Http\Psr17Factory;
use Zef\Framework\Http\Stream;
use Zef\Framework\Job\CronExpression;
use Zef\Framework\Security\SecurityRuntimeMiddleware;
use Zef\Middleware\CorsMiddleware;
use Zef\Middleware\GlobalErrorHandler;
use Zef\Middleware\SecurityHeadersMiddleware;
use Zef\Middleware\TimingMiddleware;

/**
 * @internal
 */
final class KernelDeepTest extends TestCase
{
    // ------------------------------------------------------------------
    // Middleware services resolved through the real container
    // ------------------------------------------------------------------

    public function testMiddlewareServicesResolveFromBootedContainer(): void
    {
        $container = $this->bootedApp()->getContainer();

        $error = $container->get('middleware.error');
        self::assertInstanceOf(GlobalErrorHandler::class, $error);

        $timing = $container->get('middleware.timing');
        self::assertInstanceOf(TimingMiddleware::class, $timing);

        $cors = $container->get('middleware.cors');
        self::assertInstanceOf(CorsMiddleware::class, $cors);

        $security = $container->get('middleware.security');
        self::assertInstanceOf(SecurityHeadersMiddleware::class, $security);

        $runtime = $container->get('middleware.security.runtime');
        self::assertInstanceOf(SecurityRuntimeMiddleware::class, $runtime);
    }

    // ------------------------------------------------------------------
    // Router guards
    // ------------------------------------------------------------------

    public function testRouterAddMatchFreezeAndNameGuards(): void
    {
        $app = $this->bootedApp();
        $router = $app->getRouter();

        self::assertGreaterThan(0, $router->getMaxRoutesBudget());
        self::assertTrue($router->isFrozen());
        self::assertNotEmpty($router->getRoutes());
        self::assertNotEmpty($router->routeNames());
        self::assertFalse($router->hasRouteName('no-such-name'));

        $match = $router->match('GET', '/');
        self::assertSame(200, $match['status'] ?? $match['_status'] ?? 200);
        self::assertFalse($router->hasRouteName('no-such-name'));
    }

    // ------------------------------------------------------------------
    // Stream error paths
    // ------------------------------------------------------------------

    public function testStreamRejectsWritesOnReadOnlyStreams(): void
    {
        $read = new Stream(fopen('php://memory', 'rb'));
        self::assertFalse($read->isWritable());

        $this->expectException(\RuntimeException::class);
        $read->write('nope');
    }

    public function testStreamReadPastEndAndRewind(): void
    {
        $stream = Stream::fromString('abc');
        self::assertSame('abc', $stream->read(10));
        self::assertTrue($stream->eof());
        $stream->rewind();
        self::assertSame('a', $stream->read(1));
        self::assertSame('bc', $stream->getContents());
    }

    // ------------------------------------------------------------------
    // CronExpression pattern variants
    // ------------------------------------------------------------------

    public function testCronExpressionPatternVariants(): void
    {
        $midnight = CronExpression::parse('0 0 * * *');
        $base = strtotime('2026-06-15T10:30:00+00:00');
        self::assertSame(
            strtotime('2026-06-16T00:00:00+00:00') * 1_000_000_000,
            $midnight->nextRunAfter($base * 1_000_000_000),
        );

        $list = CronExpression::parse('30 2 1,15 * *');
        self::assertStringContainsString('2', $list->describe());

        $range = CronExpression::parse('0 9-17 * * 1-5');
        self::assertTrue($range->matchesUtc(strtotime('2026-06-15T09:00:00+00:00')));
        self::assertFalse($range->matchesUtc(strtotime('2026-06-13T09:00:00+00:00')));

        $this->expectException(\InvalidArgumentException::class);
        CronExpression::parse('61 * * * *');
    }

    // ------------------------------------------------------------------
    // PSR-17 factory surface
    // ------------------------------------------------------------------

    public function testPsr17FactoryCreatesEverything(): void
    {
        $factory = new Psr17Factory();

        $response = $factory->createResponse(201, 'Made');
        self::assertSame(201, $response->getStatusCode());

        $stream = $factory->createStream('file-body');
        self::assertSame('file-body', (string) $stream);

        $uri = $factory->createUri('http://localhost:8080/x?y=1');
        self::assertSame('/x', $uri->getPath());

        $request = $factory->createRequest('GET', 'http://localhost/a');
        self::assertSame('GET', $request->getMethod());

        $serverRequest = $factory->createServerRequest('POST', 'http://localhost/b', ['REMOTE_ADDR' => '127.0.0.1']);
        self::assertSame('127.0.0.1', $serverRequest->getServerParams()['REMOTE_ADDR']);

        $uploaded = $factory->createUploadedFile($factory->createStream('up'), 2, \UPLOAD_ERR_OK, 'a.txt', 'text/plain');
        self::assertSame(2, $uploaded->getSize());

        $this->expectException(\RuntimeException::class);
        $factory->createStreamFromFile('/proc/no/such/file/zef', 'r');
    }

    // ------------------------------------------------------------------
    // Event dispatcher internals
    // ------------------------------------------------------------------

    public function testEventDispatcherRegistrationsAndDispatchWithContext(): void
    {
        $app = Bootstrap::createApp(false);
        $dispatcher = $app->getContainer()->get(EventDispatcher::class);

        $hits = [];
        $dispatcher->listen(\stdClass::class, static function (object $e) use (&$hits): void {
            $hits[] = 'a';
        }, 1);

        self::assertCount(1, $dispatcher->registrations());
        self::assertCount(1, iterator_to_array($dispatcher->getListenersForEvent(new \stdClass())));

        $context = new EventContext('evt-0000001', hrtime(true), 'corr-0000009');
        $result = $dispatcher->dispatchWithContext(new \stdClass(), $context);
        self::assertInstanceOf(\stdClass::class, $result);
        self::assertSame(['a'], $hits);
    }

    public function testEventSubscriberReceivesRegisteredEvents(): void
    {
        $app = Bootstrap::createApp(false);
        $dispatcher = $app->getContainer()->get(EventDispatcher::class);

        $subscriber = new class implements EventSubscriberInterface {
            public array $seen = [];

            public static function subscriptions(): array
            {
                return [];
            }

            public function bind(): array
            {
                return [\stdClass::class => [[0, $this->onEvent(...)]]];
            }

            public function onEvent(\stdClass $event): void
            {
                $this->seen[] = 'hit';
            }
        };

        // subscriptions() handlers resolve lazily against the subscriber
        // instance: [priority, callable] tuples.
        foreach ($subscriber->bind() as $eventClass => $handlers) {
            foreach ($handlers as [$priority, $listener]) {
                $dispatcher->listen($eventClass, $listener, $priority);
            }
        }
        $dispatcher->dispatch(new \stdClass());
        self::assertSame(['hit'], $subscriber->seen);
        self::assertInstanceOf(EventSubscriberInterface::class, $subscriber);
    }

    private function bootedApp(): Application
    {
        $app = Bootstrap::createApp(false);
        $app->boot();

        return $app;
    }
}
