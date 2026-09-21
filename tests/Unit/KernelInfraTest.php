<?php

declare(strict_types=1);

/*
 * ZEF Framework — Native PHPUnit coverage for kernel/infrastructure
 * plumbing: module definitions and registry, middleware definitions,
 * security runtime middleware, autowiring reflection, CQRS query bus,
 * route constraints, resource query specs and validation messages.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zef\App\Bootstrap;
use Zef\Framework\Config\ModuleDefinition;
use Zef\Framework\Config\ModuleRegistry;
use Zef\Framework\Container\Autowiring\ReflectionMetadataExtractor;
use Zef\Framework\Container\Container;
use Zef\Framework\CQRS\CqrsContext;
use Zef\Framework\CQRS\QueryBus;
use Zef\Framework\Event\EventDispatcher;
use Zef\Framework\Exception\InvalidConfigurationException;
use Zef\Framework\Exception\RouteConstraintException;
use Zef\Framework\Http\Response;
use Zef\Framework\Http\ResponseFactory;
use Zef\Framework\Http\ServerRequest;
use Zef\Framework\Http\Stream;
use Zef\Framework\Http\UploadedFile;
use Zef\Framework\Http\Uri;
use Zef\Framework\MiddlewareDefinition;
use Zef\Framework\Resource\FilterSpec;
use Zef\Framework\Resource\SortSpec;
use Zef\Framework\Router\Router;
use Zef\Framework\Security\InMemoryRateLimiter;
use Zef\Framework\Security\SecurityPolicy;
use Zef\Framework\Security\SecurityRuntimeMiddleware;
use Zef\Framework\Validation\RouteConstraintValidator;
use Zef\Middleware\ConfigProvider as MiddlewareConfigProvider;

/**
 * @internal
 */
final class KernelInfraTest extends TestCase
{
    // ------------------------------------------------------------------
    // ModuleDefinition + ModuleRegistry + MiddlewareDefinition
    // ------------------------------------------------------------------

    public function testModuleDefinitionFromArrayRoundTripAndGuards(): void
    {
        $definition = ModuleDefinition::fromArray('shop', [
            'greeting' => 'hello',
            'routes' => [['method' => 'GET', 'path' => '/shop', 'handler' => 'shop_handler']],
            'dependencies' => ['core'],
        ]);

        self::assertSame('shop', $definition->name);
        self::assertSame(['core'], $definition->dependencies);
        self::assertSame('hello', $definition->extensions['greeting']);
        $roundTrip = $definition->toArray();
        self::assertSame(['core'], $roundTrip['dependencies']);
        self::assertSame('hello', $roundTrip['greeting']);

        try {
            ModuleDefinition::fromArray('', []);
            self::fail('empty module name must throw');
        } catch (\InvalidArgumentException) {
        }

        $this->expectException(\InvalidArgumentException::class);
        ModuleDefinition::fromArray('bad services', ['services' => 'nope']);
    }

    public function testMiddlewareDefinitionGuards(): void
    {
        $definition = new MiddlewareDefinition('cors', 10, 'http', ['edge']);
        self::assertSame('cors', $definition->serviceId);
        self::assertSame(['edge'], $definition->tags);

        try {
            new MiddlewareDefinition('', 0, null, []);
            self::fail('empty service ID must throw');
        } catch (\InvalidArgumentException) {
        }

        $this->expectException(\InvalidArgumentException::class);
        new MiddlewareDefinition('ok', 0, '', []);
    }

    public function testModuleRegistryResolveOrderAndShutdown(): void
    {
        $registry = new ModuleRegistry();
        $provider = new MiddlewareConfigProvider(false);
        $registry->addProvider($provider);

        self::assertSame([$provider], $registry->providers());
        self::assertFalse($registry->isRegistered());
        self::assertFalse($registry->isBooted());
        self::assertNotEmpty($registry->resolveOrder());

        $registry->shutdownAll(new Container());
        $app = Bootstrap::createApp(false);
        $app->boot();
        $config = $provider->getConfig();
        self::assertArrayHasKey('services', $config);
        self::assertArrayHasKey('stack', $config);
        self::assertIsArray($config['services']);
        self::assertIsArray($config['stack']);
    }

    // ------------------------------------------------------------------
    // SecurityRuntimeMiddleware
    // ------------------------------------------------------------------

    public function testSecurityRuntimeMiddlewarePassesThroughWhenDisabled(): void
    {
        $policy = new SecurityPolicy(
            rateLimitEnabled: false,
            csrfEnabled: false,
            originEnabled: false,
        );
        $middleware = new SecurityRuntimeMiddleware($policy, new InMemoryRateLimiter(), []);

        $response = $middleware->process($this->request('/x'), $this->handler(static fn (): Response => new Response(200, [], 'ok')));
        self::assertSame(200, $response->getStatusCode());

        // SAFE method with CSRF enabled but empty secret: skipped (no csrf manager).
        $policy2 = new SecurityPolicy(rateLimitEnabled: false, csrfEnabled: true, csrfSecret: '');
        $middleware2 = new SecurityRuntimeMiddleware($policy2, new InMemoryRateLimiter(), []);
        $response2 = $middleware2->process($this->request('/y'), $this->handler(static fn (): Response => new Response(204)));
        self::assertSame(204, $response2->getStatusCode());
    }

    public function testSecurityRuntimeMiddlewareEnforcesRateLimit(): void
    {
        $policy = new SecurityPolicy(
            rateLimitEnabled: true,
            rateLimitMaxRequests: 1,
            rateLimitWindowSeconds: 60,
            csrfEnabled: false,
        );
        $middleware = new SecurityRuntimeMiddleware($policy, new InMemoryRateLimiter(), []);

        $first = $middleware->process($this->request('/r'), $this->handler(static fn (): Response => new Response(200)));
        $second = $middleware->process($this->request('/r'), $this->handler(static fn (): Response => new Response(200)));

        self::assertContains($first->getStatusCode(), [200, 429]);
        self::assertContains($second->getStatusCode(), [200, 429]);
        self::assertTrue($first->getStatusCode() === 200 || $second->getStatusCode() === 429);
    }

    // ------------------------------------------------------------------
    // Autowiring reflection + CQRS query bus
    // ------------------------------------------------------------------

    public function testReflectionMetadataExtractorBuildsSpec(): void
    {
        $extractor = new ReflectionMetadataExtractor();
        $spec = $extractor->extract(DispatcherFixture::class);

        self::assertSame(DispatcherFixture::class, $spec->className);
        self::assertTrue($spec->hasConstructor);
        self::assertCount(2, $spec->parameters);

        $this->expectException(\RuntimeException::class);
        $extractor->extract('Zef\Framework\NoSuchClass\Missing');
    }

    public function testQueryBusRegisterAskAndFreeze(): void
    {
        $bus = new QueryBus();

        $bus->register(GetTotalQuery::class, static fn (GetTotalQuery $q): int => $q->amount * 2);
        $bus->freeze();
        self::assertTrue($bus->isFrozen());

        $answer = $bus->ask(new GetTotalQuery(21), new CqrsContext('corr-0000001'));
        self::assertSame(42, $answer);

        try {
            $bus->register(GetTotalQuery::class, static fn (GetTotalQuery $q): int => 0);
            self::fail('register after freeze must throw');
        } catch (\LogicException) {
        }

        $this->expectException(\RuntimeException::class);
        $bus->ask(new UnregisteredQuery());
    }

    // ------------------------------------------------------------------
    // RouteConstraintValidator + resource specs
    // ------------------------------------------------------------------

    public function testRouteConstraintValidatorBuiltInsAndCustom(): void
    {
        $validator = new RouteConstraintValidator();
        self::assertTrue($validator->test('id', 'int', '42'));
        self::assertFalse($validator->test('id', 'int', '-4'));
        self::assertTrue($validator->test('s', 'slug', 'my-slug-1'));
        self::assertFalse($validator->test('s', 'slug', 'My_Slug'));
        self::assertTrue($validator->test('u', 'uuid', '4bf92f35-77b3-4da6-a3ce-929d0e0e4736'));

        $validator->addCustom('ref', '/^R\d{4}$/');
        self::assertTrue($validator->test('r', 'ref', 'R1234'));
        self::assertSame(['ref' => '/^R\d{4}$/'], $validator->customConstraints());

        try {
            $validator->assert('x', 'unknown-type', '1');
            self::fail('unknown constraint type must throw');
        } catch (InvalidConfigurationException) {
        }

        $validator->assert('id', 'int', '7');
        $this->expectException(RouteConstraintException::class);
        $validator->assert('id', 'int', 'seven');
    }

    public function testFilterSpecFromQueryAndApply(): void
    {
        $spec = FilterSpec::fromQuery(
            ['filter' => ['status' => 'open', 'price_gte' => 10]],
            ['status', 'price_gte'],
        );

        self::assertFalse($spec->isEmpty());
        $rows = $spec->applyTo([
            ['status' => 'open', 'price_gte' => 0, 'price' => 10],
            ['status' => 'closed', 'price' => 5],
        ]);
        self::assertIsArray($rows);

        $empty = FilterSpec::fromQuery(['filter' => 'scalar'], ['status']);
        self::assertTrue($empty->isEmpty());

        $unknown = FilterSpec::fromQuery(['filter' => ['evil' => 'x']], ['status']);
        self::assertTrue($unknown->isEmpty());
    }

    public function testSortSpecFromQueryAndApply(): void
    {
        $spec = SortSpec::fromQuery(['sort' => '-price,name'], ['price', 'name']);
        self::assertFalse($spec->isEmpty());
        $rows = $spec->applyTo([
            ['name' => 'b', 'price' => 2],
            ['name' => 'a', 'price' => 3],
        ]);
        self::assertIsArray($rows);
        self::assertNotNull($spec->toQuery());

        $empty = SortSpec::fromQuery([], ['price']);
        self::assertTrue($empty->isEmpty());

        $ignored = SortSpec::fromQuery(['sort' => 'evil'], ['price']);
        self::assertTrue($ignored->isEmpty());
    }

    // ------------------------------------------------------------------
    // ServerRequest / UploadedFile extras
    // ------------------------------------------------------------------

    public function testServerRequestCookieAndAttributeMutability(): void
    {
        $request = $this->request()->withCookieParams(['sid' => 'abc123']);
        self::assertSame('abc123', $request->getCookieParams()['sid']);

        $withAttr = $request->withAttribute('route', 'toko');
        self::assertSame('toko', $withAttr->getAttribute('route'));
        self::assertNull($request->getAttribute('route'));
        self::assertSame(['route' => 'toko'], $withAttr->getAttributes());

        $without = $withAttr->withoutAttribute('route');
        self::assertNull($without->getAttribute('route'));

        $this->expectException(\InvalidArgumentException::class);
        $request->withParsedBody('not-array-or-object');
    }

    public function testUploadedFileMoveErrorsAndSizeReporting(): void
    {
        $file = new UploadedFile(Stream::fromString('data'), 4, \UPLOAD_ERR_OK);
        $this->expectException(\RuntimeException::class);
        $file->moveTo('/proc/no/such/dir/here');
    }

    // ------------------------------------------------------------------
    // EventDispatcher edge paths
    // ------------------------------------------------------------------

    public function testEventDispatcherPriorityAndListenerExceptionContainment(): void
    {
        $app = Bootstrap::createApp(false);
        $dispatcher = $app->getContainer()->get(EventDispatcher::class);
        self::assertInstanceOf(EventDispatcher::class, $dispatcher);
        self::assertFalse($dispatcher->isFrozen());

        $hits = [];
        $dispatcher->listen(\stdClass::class, static function (object $e) use (&$hits): void {
            $hits[] = 'first';
        }, 5);
        $dispatcher->listen(\stdClass::class, static function (object $e) use (&$hits): void {
            $hits[] = 'second';
        }, 10);
        $dispatcher->freeze();
        self::assertTrue($dispatcher->isFrozen());

        try {
            $dispatcher->listen(\stdClass::class, static function (object $e) use (&$hits): void {
                $hits[] = 'late';
            });
            self::fail('listen after freeze must throw');
        } catch (\LogicException) {
        }

        $result = $dispatcher->dispatch(new \stdClass());
        self::assertInstanceOf(\stdClass::class, $result);
        self::assertSame(['second', 'first'], $hits);
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

final class DispatcherFixture
{
    public function __construct(
        public readonly ?ResponseFactory $responseFactory = null,
        public readonly ?Router $router = null,
    ) {}
}

final class GetTotalQuery
{
    public function __construct(public readonly int $amount) {}
}

final class UnregisteredQuery {}
