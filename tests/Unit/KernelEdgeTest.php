<?php

declare(strict_types=1);

/*
 * ZEF Framework — Coverage push #5 (v2.13.1): kernel edges — middleware
 * definitions, module bootstrapping, dispatcher failure modes, response
 * emitting, global error handling, application lifecycle guards and the
 * module definition config matrix.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zef\Framework\Application;
use Zef\Framework\Config\AbstractModule;
use Zef\Framework\Config\ConfigProviderInterface;
use Zef\Framework\Config\ModuleDefinition;
use Zef\Framework\Container\Container;
use Zef\Framework\Container\ServiceDefinition;
use Zef\Framework\Dispatcher as AdaptersDispatcher;
use Zef\Framework\Exception\InvalidConfigurationException;
use Zef\Framework\Exception\MethodNotAllowedException;
use Zef\Framework\Http\RequestBodyPolicy;
use Zef\Framework\Http\Response;
use Zef\Framework\Http\ServerRequest;
use Zef\Framework\Http\Uri;
use Zef\Framework\MiddlewareDefinition;
use Zef\Framework\ModuleBootstrapper;
use Zef\Framework\Observability\Telemetry;
use Zef\Framework\ResponseEmitter as KernelResponseEmitter;
use Zef\Framework\Router\Router;
use Zef\Middleware\ErrorLogger;
use Zef\Middleware\ErrorResponseFactory;
use Zef\Middleware\GlobalErrorHandler;

/**
 * @internal
 */
final class KernelEdgeTest extends TestCase
{
    // ------------------------------------------------------------------
    // MiddlewareDefinition
    // ------------------------------------------------------------------

    public function testMiddlewareDefinitionRejectsEmptyServiceId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MiddlewareDefinition('');
    }

    public function testMiddlewareDefinitionRejectsEmptyGroupAndBadTags(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MiddlewareDefinition('svc', 0, '');
    }

    public function testMiddlewareDefinitionRejectsNonStringTags(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MiddlewareDefinition('svc', 0, null, ['tag', 7]);
    }

    public function testMiddlewareDefinitionFromArrayRequiresService(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        MiddlewareDefinition::fromArray(['priority' => 3]);
    }

    public function testMiddlewareDefinitionFromArrayAcceptsIdAlias(): void
    {
        $definition = MiddlewareDefinition::fromArray(['id' => 'svc', 'priority' => '4', 'group' => 'g', 'tags' => ['t']]);
        self::assertSame('svc', $definition->serviceId);
        self::assertSame(4, $definition->priority);
        self::assertSame('g', $definition->group);
    }

    public function testMiddlewareDefinitionFromArrayRejectsNonNumericPriority(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        MiddlewareDefinition::fromArray(['service' => 'svc', 'priority' => []]);
    }

    public function testMiddlewareDefinitionFromArrayRejectsNonArrayTags(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        MiddlewareDefinition::fromArray(['service' => 'svc', 'tags' => 'nope']);
    }

    public function testMiddlewareDefinitionFromArrayRejectsNonStringGroup(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        MiddlewareDefinition::fromArray(['service' => 'svc', 'group' => 12]);
    }

    public function testMiddlewareDefinitionFromArrayWrapsConstructorFailures(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        MiddlewareDefinition::fromArray(['service' => 'svc', 'tags' => ['']]);
    }

    public function testMiddlewareDefinitionFromLegacyVariants(): void
    {
        self::assertSame('legacy', MiddlewareDefinition::fromLegacy('legacy')->serviceId);
        $fromArray = MiddlewareDefinition::fromLegacy(['service' => 'svc']);
        self::assertSame('svc', $fromArray->serviceId);
    }

    public function testBootstrapperRegistersServicesAliasesAndRoutes(): void
    {
        $container = $this->telemetryContainer();
        $router = new Router();
        $bootstrapper = new ModuleBootstrapper($container, $router);
        $config = $this->fixtureConfig();
        $bootstrapper->registerModule('fixture', $config);

        self::assertInstanceOf(\stdClass::class, $container->get('fixture.svc'));
        self::assertInstanceOf(\stdClass::class, $container->get('fixture.alias'));
        $match = $router->match('GET', '/fixture');
        self::assertSame('fixture.svc', $match['handler']);
    }

    public function testBootstrapperRekeysDefinitionsFromOtherModules(): void
    {
        $definition = new ServiceDefinition('moved.svc', static fn (): \stdClass => new \stdClass(), [], 'other');
        $module = new ModuleDefinition('fixture', ['moved.svc' => $definition]);
        $container = $this->telemetryContainer();
        $bootstrapper = new ModuleBootstrapper($container, new Router());
        $bootstrapper->registerModule('fixture', $module);
        $resolved = $container->get('moved.svc');
        self::assertInstanceOf(\stdClass::class, $resolved);
    }

    public function testBootstrapperRejectsNameMismatch(): void
    {
        $bootstrapper = new ModuleBootstrapper($this->telemetryContainer(), new Router());
        $this->expectException(InvalidConfigurationException::class);
        $bootstrapper->registerModule('fixture', new ModuleDefinition('other', []));
    }

    public function testBootstrapperWrapsInvalidDefinitions(): void
    {
        $bootstrapper = new ModuleBootstrapper($this->telemetryContainer(), new Router());
        $this->expectException(InvalidConfigurationException::class);
        $bootstrapper->registerModule('fixture', ['name' => 'fixture', 'services' => 'nope']);
    }

    public function testModuleDefinitionCtorRejectsIdMismatchAndBadValues(): void
    {
        $definition = new ServiceDefinition('a.svc', static fn (): \stdClass => new \stdClass());
        $this->expectException(\InvalidArgumentException::class);
        new ModuleDefinition('m', ['b.svc' => $definition]);
    }

    public function testModuleDefinitionCtorRejectsBadAliasAndDependency(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ModuleDefinition('m', [], ['' => 'target']);
    }

    public function testModuleDefinitionCtorRejectsNonRouteEntries(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ModuleDefinition('m', [], [], ['not-a-route']);
    }

    public function testModuleDefinitionFromArrayRejectsNonArraySections(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ModuleDefinition::fromArray('m', ['services' => 'nope']);
    }

    public function testModuleDefinitionFromArrayRejectsNonArrayAliases(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ModuleDefinition::fromArray('m', ['aliases' => 'nope']);
    }

    public function testModuleDefinitionFromArrayRejectsNonArrayRoutes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ModuleDefinition::fromArray('m', ['routes' => 'nope']);
    }

    public function testModuleDefinitionFromArrayRejectsNonArrayDependencies(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ModuleDefinition::fromArray('m', ['requires' => 'nope']);
    }

    public function testModuleDefinitionFromArrayRejectsBadServiceIdAndShape(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ModuleDefinition::fromArray('m', ['services' => [7 => ['factory' => 'strlen']]]);
    }

    public function testModuleDefinitionFromArrayRejectsIdMismatchOnDefinitionInstance(): void
    {
        $definition = new ServiceDefinition('a.svc', static fn (): \stdClass => new \stdClass());
        $this->expectException(\InvalidArgumentException::class);
        ModuleDefinition::fromArray('m', ['services' => ['b.svc' => $definition]]);
    }

    public function testModuleDefinitionFromArrayRekeysForeignDefinitionInstance(): void
    {
        $definition = new ServiceDefinition('a.svc', static fn (): \stdClass => new \stdClass(), [], 'other');
        $module = ModuleDefinition::fromArray('m', ['services' => ['a.svc' => $definition]]);
        self::assertSame('m', $module->services['a.svc']->module);
    }

    public function testModuleDefinitionFromArrayRejectsNonArrayDefinition(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ModuleDefinition::fromArray('m', ['services' => ['a.svc' => 'nope']]);
    }

    public function testModuleDefinitionFromArrayRejectsBadAliasAndDependencyTypes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ModuleDefinition::fromArray('m', ['aliases' => ['a' => 7]]);
    }

    public function testModuleDefinitionFromArrayAcceptsRequiresAndNormalizesDependencies(): void
    {
        $module = ModuleDefinition::fromArray('m', ['requires' => ['OTHER', 'other'], 'extensions-keep' => 'yes']);
        self::assertSame(['other'], $module->dependencies);
        self::assertSame('yes', $module->extensions['extensions-keep']);
        self::assertArrayNotHasKey('requires', $module->extensions);
    }

    public function testModuleDefinitionToArrayRoundTrips(): void
    {
        $module = ModuleDefinition::fromArray('m', $this->fixtureConfig());
        $restored = ModuleDefinition::fromArray('m', $module->toArray());
        self::assertSame(array_keys($module->services), array_keys($restored->services));
        self::assertCount(1, $restored->routes);
        self::assertCount(1, $restored->aliases);
    }

    // ------------------------------------------------------------------
    // Dispatcher failure modes
    // ------------------------------------------------------------------

    public function testDispatcherReturns404ForUnknownRoute(): void
    {
        $container = $this->telemetryContainer();
        $router = new Router();
        $router->add('GET', '/known', 'svc');
        $router->freeze();
        $dispatcher = new AdaptersDispatcher($router, $container);
        $response = $dispatcher->handle($this->request('/unknown'));
        self::assertSame(404, $response->getStatusCode());
    }

    public function testDispatcherReturns405ForMethodMismatch(): void
    {
        $container = $this->telemetryContainer();
        $router = new Router();
        $router->add('GET', '/only-get', 'svc');
        $router->freeze();
        $dispatcher = new AdaptersDispatcher($router, $container);
        $response = $dispatcher->handle($this->request('/only-get', 'POST'));
        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET, HEAD', $response->getHeaderLine('Allow'));
    }

    public function testDispatcherRejectsNonHandlerServices(): void
    {
        $container = $this->telemetryContainer();
        $container->register('svc', static fn (): \stdClass => new \stdClass(), [], 'test');
        $router = new Router();
        $router->add('GET', '/no-handler', 'svc');
        $router->freeze();
        $dispatcher = new AdaptersDispatcher($router, $container);
        $this->expectException(InvalidConfigurationException::class);
        $dispatcher->handle($this->request('/no-handler'));
    }

    public function testDispatcherRecordsHandlerSpanFailure(): void
    {
        $container = $this->telemetryContainer();
        $container->register(
            'svc',
            static fn (): RequestHandlerInterface => new class implements RequestHandlerInterface {
                #[\Override]
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    throw new \RuntimeException('handler boom');
                }
            },
            [],
            'test',
        );
        $router = new Router();
        $router->add('GET', '/throws', 'svc');
        $router->freeze();
        $dispatcher = new AdaptersDispatcher($router, $container);
        $this->expectException(\RuntimeException::class);
        $dispatcher->handle($this->request('/throws'));
    }

    public function testDispatcherUsesRequestScopeAsResolver(): void
    {
        $container = $this->telemetryContainer();
        $container->register(
            'svc',
            static fn (): RequestHandlerInterface => new class implements RequestHandlerInterface {
                #[\Override]
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return new Response(201, [], 'scoped');
                }
            },
            [],
            'test',
        );
        $router = new Router();
        $router->add('GET', '/scoped/{id}', 'svc');
        $router->freeze();
        $dispatcher = new AdaptersDispatcher($router, $container);
        $response = $dispatcher->handle($this->request('/scoped/42'));
        self::assertSame(201, $response->getStatusCode());
        self::assertSame('scoped', (string) $response->getBody());
    }

    // ------------------------------------------------------------------
    // ResponseEmitter
    // ------------------------------------------------------------------

    public function testEmitterWritesBodyAndHonoursFlags(): void
    {
        $emitter = new KernelResponseEmitter();

        ob_start();
        $emitter->emit(new Response(200, ['X-Framework' => 'zef'], 'hello'), true);
        $out = (string) ob_get_clean();
        self::assertSame('hello', $out);

        ob_start();
        $emitter->emit(new Response(200, [], 'hello'), false, true);
        self::assertSame('', (string) ob_get_clean());

        ob_start();
        $emitter->emit(new Response(204, [], ''), false, false);
        self::assertSame('', (string) ob_get_clean());
    }

    public function testEmitterDropsLyingContentLength(): void
    {
        $emitter = new KernelResponseEmitter();
        $response = new Response(200, ['Content-Length' => '999'], 'abc');
        ob_start();
        $emitter->emit($response);
        self::assertSame('abc', (string) ob_get_clean());
        self::assertSame('999', $response->getHeaderLine('Content-Length'));
    }

    public function testGlobalErrorHandlerPreservesValidCorrelationId(): void
    {
        $handler = new GlobalErrorHandler($this->errorLogger(), new ErrorResponseFactory());
        $response = $handler->process($this->request('/', 'GET', ['X-Request-ID' => 'corr-1']), $this->passingHandler());
        self::assertSame('corr-1', $response->getHeaderLine('X-Request-ID'));
    }

    public function testGlobalErrorHandlerRegeneratesInvalidCorrelationId(): void
    {
        $handler = new GlobalErrorHandler($this->errorLogger(), new ErrorResponseFactory());
        $response = $handler->process($this->request('/', 'GET', ['X-Request-ID' => str_repeat('x', 130)]), $this->passingHandler());
        self::assertNotSame(str_repeat('x', 130), $response->getHeaderLine('X-Request-ID'));
        self::assertSame(32, strlen($response->getHeaderLine('X-Request-ID')));
    }

    public function testGlobalErrorHandlerMapsMethodNotAllowed(): void
    {
        $handler = new GlobalErrorHandler($this->errorLogger(), new ErrorResponseFactory());
        $throwing = new class implements RequestHandlerInterface {
            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new MethodNotAllowedException('POST', '/x', ['GET', 'PUT']);
            }
        };
        $response = $handler->process($this->request('/x'), $throwing);
        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET, PUT', $response->getHeaderLine('Allow'));
    }

    public function testGlobalErrorHandlerLogsAndReturns500(): void
    {
        $logger = $this->errorLogger();
        $handler = new GlobalErrorHandler($logger, new ErrorResponseFactory());
        $throwing = new class implements RequestHandlerInterface {
            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \RuntimeException('boom');
            }
        };
        $response = $handler->process($this->request('/boom'), $throwing);
        self::assertSame(500, $response->getStatusCode());
        self::assertCount(1, $logger->errors);
    }

    public function testGlobalErrorHandlerDevModeRedactsSecrets(): void
    {
        $handler = new GlobalErrorHandler($this->errorLogger(), new ErrorResponseFactory(true));
        $throwing = new class implements RequestHandlerInterface {
            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \RuntimeException('db connect failed with password=hunter2x');
            }
        };
        $response = $handler->process($this->request('/dev'), $throwing);
        $decoded = json_decode((string) $response->getBody(), true, 8, JSON_THROW_ON_ERROR);
        assert(is_array($decoded));
        self::assertIsString($decoded['message']);
        self::assertStringContainsString('[REDACTED]', $decoded['message']);
    }

    public function testGlobalErrorHandlerFallsBackWhenJsonFails(): void
    {
        $handler = new GlobalErrorHandler($this->errorLogger(), new ErrorResponseFactory(true));
        $throwing = new class implements RequestHandlerInterface {
            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \RuntimeException("invalid \xB1\x31 utf8 message");
            }
        };
        $response = $handler->process($this->request('/utf8'), $throwing);
        self::assertSame(500, $response->getStatusCode());
        self::assertSame('Internal Server Error', (string) $response->getBody());
    }

    public function testErrorLoggerWritesSanitizedEntry(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'zeferr');
        assert($logFile !== false);
        $previous = ini_set('error_log', $logFile);

        try {
            $logger = new ErrorLogger(true);
            $logger->log('corr-9', new \RuntimeException('token=abcdef1234'), $this->request('/leak'));
            $entry = (string) file_get_contents($logFile);
            self::assertStringContainsString('corr-9', $entry);
            self::assertStringContainsString('/leak', $entry);
            self::assertStringContainsString('[REDACTED]', $entry);
        } finally {
            ini_set('error_log', (string) $previous);
            @unlink($logFile);
        }
    }

    public function testErrorResponseFactoryHidesMessageOutsideDevMode(): void
    {
        $factory = new ErrorResponseFactory();
        self::assertFalse($factory->isDebug());
        $response = $factory->create(500, 'secret detail', 'corr');
        $decoded = json_decode((string) $response->getBody(), true, 8, JSON_THROW_ON_ERROR);
        assert(is_array($decoded));
        self::assertSame('An error occurred', $decoded['message']);
        self::assertSame('corr', $decoded['correlation_id']);
    }

    public function testApplicationGuardsAgainstLateProviderAddition(): void
    {
        $app = new Application();
        $app->addProvider($this->fixtureProvider());
        $app->setMaxCrossModuleRefs(4);
        $app->boot();
        self::assertTrue($app->isBooted());

        $this->expectException(\LogicException::class);
        $app->addProvider($this->fixtureProvider());
    }

    public function testApplicationGuardsAgainstLateModuleAddition(): void
    {
        $app = new Application();
        $app->addProvider($this->fixtureProvider());
        $app->boot();
        $module = new class(new ModuleDefinition('late', [])) extends AbstractModule {};
        $this->expectException(\LogicException::class);
        $app->addModule($module);
    }

    public function testApplicationGuardsAgainstLateTrustedHosts(): void
    {
        $app = new Application();
        $app->addProvider($this->fixtureProvider());
        $app->boot();
        $this->expectException(\LogicException::class);
        $app->setTrustedHosts(['example.test']);
    }

    public function testApplicationGuardsAgainstLateTrustedProxies(): void
    {
        $app = new Application();
        $app->addProvider($this->fixtureProvider());
        $app->boot();
        $this->expectException(\LogicException::class);
        $app->setTrustedProxies(['10.0.0.1']);
    }

    public function testApplicationBootIsIdempotent(): void
    {
        $app = new Application();
        $app->addProvider($this->fixtureProvider());
        $app->boot();
        $app->boot();
        self::assertTrue($app->isBooted());
    }

    public function testApplicationHandleStripsHeadBodyAndEmitsTraceparent(): void
    {
        $app = new Application();
        $app->addProvider($this->fixtureProvider());
        putenv('ZEF_OTEL_ENABLED=1');

        try {
            $response = $app->handle($this->request('/app-ok', 'HEAD', [
                'traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
            ]));
            self::assertSame(200, $response->getStatusCode());
            self::assertSame('', (string) $response->getBody());
            self::assertStringStartsWith('00-4bf92f3577b34da6a3ce929d0e0e4736-', $response->getHeaderLine('traceparent'));
        } finally {
            putenv('ZEF_OTEL_ENABLED');
        }
    }

    public function testApplicationHandleRethrowsPipelineFailure(): void
    {
        $app = new Application();
        $app->addProvider($this->fixtureProvider());
        $this->expectException(\RuntimeException::class);
        $app->handle($this->request('/app-fail'));
    }

    public function testApplicationHandleGlobalsReturns413ForOversizedBody(): void
    {
        $app = new Application(false, null, new RequestBodyPolicy(16));
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/upload';
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['CONTENT_LENGTH'] = '512';

        try {
            $response = $app->handleGlobals();
            self::assertSame(413, $response->getStatusCode());
        } finally {
            unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], $_SERVER['HTTP_HOST'], $_SERVER['CONTENT_LENGTH']);
        }
    }

    public function testApplicationHandleGlobalsReturns400ForInvalidForwardedProto(): void
    {
        $app = new Application();
        $app->setTrustedProxies(['10.0.0.9']);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/x';
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['REMOTE_ADDR'] = '10.0.0.9';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'ftp';

        try {
            $response = $app->handleGlobals();
            self::assertSame(400, $response->getStatusCode());
        } finally {
            unset(
                $_SERVER['REQUEST_METHOD'],
                $_SERVER['REQUEST_URI'],
                $_SERVER['HTTP_HOST'],
                $_SERVER['REMOTE_ADDR'],
                $_SERVER['HTTP_X_FORWARDED_PROTO'],
            );
        }
    }

    public function testApplicationShutdownPreventsFurtherHandling(): void
    {
        $app = new Application();
        $app->addProvider($this->fixtureProvider());
        $app->handle($this->request('/app-ok'));
        $app->shutdown();
        $this->expectException(\LogicException::class);
        $app->handle($this->request('/app-ok'));
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(string $path = '/', string $method = 'GET', array $headers = []): ServerRequestInterface
    {
        return new ServerRequest($method, new Uri('http://localhost' . $path, ['localhost']), [], [], [], [], null, $headers);
    }

    private function telemetryContainer(): Container
    {
        $container = new Container();
        $container->register(
            Telemetry::class,
            static fn (): Telemetry => Telemetry::fromEnvironment(null, false),
            [],
            'test',
        );

        return $container;
    }

    // ------------------------------------------------------------------
    // ModuleBootstrapper + ModuleDefinition matrix
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function fixtureConfig(): array
    {
        return [
            'services' => [
                'fixture.svc' => ['factory' => static fn (): \stdClass => new \stdClass(), 'deps' => []],
            ],
            'aliases' => ['fixture.alias' => 'fixture.svc'],
            'routes' => [
                ['method' => 'GET', 'path' => '/fixture', 'handler' => 'fixture.svc', 'priority' => 5],
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Error handling stack
    // ------------------------------------------------------------------

    private function errorLogger(): CollectingLogger
    {
        return new CollectingLogger();
    }

    private function passingHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, [], 'fine');
            }
        };
    }

    // ------------------------------------------------------------------
    // Application lifecycle guards
    // ------------------------------------------------------------------

    private function fixtureProvider(): ConfigProviderInterface
    {
        return new class implements ConfigProviderInterface {
            #[\Override]
            public function getModuleName(): string
            {
                return 'fixture-app';
            }

            /**
             * @return array<string, mixed>
             */
            #[\Override]
            public function getConfig(): array
            {
                return [
                    'services' => [
                        'app.ok' => [
                            'factory' => static fn (): RequestHandlerInterface => new class implements RequestHandlerInterface {
                                #[\Override]
                                public function handle(ServerRequestInterface $request): ResponseInterface
                                {
                                    return new Response(200, [], 'app-ok');
                                }
                            },
                            'deps' => [],
                        ],
                        'app.fail' => [
                            'factory' => static fn (): RequestHandlerInterface => new class implements RequestHandlerInterface {
                                #[\Override]
                                public function handle(ServerRequestInterface $request): ResponseInterface
                                {
                                    throw new \RuntimeException('pipeline failure');
                                }
                            },
                            'deps' => [],
                        ],
                    ],
                    'routes' => [
                        ['method' => 'GET', 'path' => '/app-ok', 'handler' => 'app.ok'],
                        ['method' => 'GET', 'path' => '/app-fail', 'handler' => 'app.fail'],
                    ],
                ];
            }
        };
    }
}
