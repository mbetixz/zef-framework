<?php

declare(strict_types=1);

/*
 * ZEF Framework — Coverage push #3: environment-driven security policy,
 * module registry lifecycle, container/router guards, request factory body
 * handling and remaining validation branches.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Config\ModuleContext;
use Zef\Framework\Config\ModuleDefinition;
use Zef\Framework\Config\ModuleInterface;
use Zef\Framework\Config\ModuleRegistrar;
use Zef\Framework\Config\ModuleRegistry;
use Zef\Framework\Container\Container;
use Zef\Framework\Container\ServiceLifetime;
use Zef\Framework\Exception\InvalidFactoryException;
use Zef\Framework\Exception\ServiceNotFoundException;
use Zef\Framework\Http\Stream;
use Zef\Framework\Job\CronExpression;
use Zef\Framework\Resource\FilterSpec;
use Zef\Framework\Router\Router;
use Zef\Framework\Security\SecurityPolicy;

/**
 * @internal
 */
final class GuardsTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('ZEF_SECURITY_CSRF');
        putenv('ZEF_SECURITY_CSRF_SECRET');
        putenv('ZEF_SECURITY_RATE_LIMIT');
        putenv('ZEF_SECURITY_RATE_LIMIT_MAX');
        putenv('ZEF_SECURITY_RATE_LIMIT_WINDOW');
        putenv('ZEF_SECURITY_RATE_LIMIT_MAX_KEYS');
        putenv('ZEF_CORS_ORIGIN_ANY');
        putenv('ZEF_CORS_ORIGIN');
    }

    // ------------------------------------------------------------------
    // SecurityPolicy::fromEnvironment
    // ------------------------------------------------------------------

    public function testSecurityPolicyFromEnvironmentEnabled(): void
    {
        putenv('ZEF_SECURITY_RATE_LIMIT=1');
        putenv('ZEF_SECURITY_RATE_LIMIT_MAX=5');
        putenv('ZEF_SECURITY_RATE_LIMIT_WINDOW=30');
        putenv('ZEF_SECURITY_CSRF=1');
        putenv('ZEF_SECURITY_CSRF_SECRET=' . str_repeat('s', 32));

        $policy = SecurityPolicy::fromEnvironment();
        self::assertTrue($policy->rateLimitEnabled);
        self::assertSame(5, $policy->rateLimitMaxRequests);
        self::assertSame(30, $policy->rateLimitWindowSeconds);
        self::assertSame(str_repeat('s', 32), $policy->csrfSecret);
    }

    public function testSecurityPolicyFromEnvironmentRejectsSecretlessCsrf(): void
    {
        putenv('ZEF_SECURITY_CSRF=1');
        putenv('ZEF_SECURITY_CSRF_SECRET=');

        $this->expectException(\RuntimeException::class);
        SecurityPolicy::fromEnvironment();
    }

    // ------------------------------------------------------------------
    // ModuleRegistry lifecycle with a fixture module
    // ------------------------------------------------------------------

    public function testModuleRegistryFullLifecycle(): void
    {
        $definition = new ModuleDefinition('fixture', [], [], [], [], []);
        $module = new class($definition) implements ModuleInterface {
            public bool $registered = false;
            public bool $booted = false;
            public bool $started = false;
            public bool $shut = false;

            public function __construct(private readonly ModuleDefinition $def) {}

            public function getName(): string
            {
                return $this->def->name;
            }

            public function getDefinition(): ModuleDefinition
            {
                return $this->def;
            }

            public function register(ModuleContext $context): void
            {
                $this->registered = true;
            }

            public function boot(ModuleContext $context): void
            {
                $this->booted = true;
            }

            public function start(ModuleContext $context): void
            {
                $this->started = true;
            }

            public function shutdown(ModuleContext $context): void
            {
                $this->shut = true;
            }
        };

        $registry = new ModuleRegistry();
        $registry->add($module);
        self::assertSame([$module], $registry->modules());
        self::assertSame([$module], $registry->resolveOrder());

        $registrar = new class implements ModuleRegistrar {
            /**
             * @var list<string>
             */
            public array $seen = [];

            public function registerModule(string $module, array|ModuleDefinition $config): void
            {
                $this->seen[] = $module;
            }
        };

        $container = new Container();
        $registry->registerAll($registrar, $container);
        self::assertTrue($registry->isRegistered());
        self::assertTrue($module->registered);

        $registry->bootAll($container);
        $registry->startAll($container);
        $registry->shutdownAll($container);
        self::assertTrue($module->booted);
        self::assertTrue($module->started);
        self::assertTrue($module->shut);
    }

    // ------------------------------------------------------------------
    // ModuleDefinition validation errors
    // ------------------------------------------------------------------

    public function testModuleDefinitionValidationBranches(): void
    {
        try {
            ModuleDefinition::fromArray('bad aliases', ['aliases' => ['a' => '']]);
            self::fail('empty alias target must throw');
        } catch (\InvalidArgumentException) {
        }

        try {
            ModuleDefinition::fromArray('bad deps', ['requires' => [42]]);
            self::fail('non-string dependency must throw');
        } catch (\InvalidArgumentException) {
        }

        $this->expectException(\InvalidArgumentException::class);
        ModuleDefinition::fromArray('bad routes', ['routes' => 'nope']);
    }

    // ------------------------------------------------------------------
    // Container branches
    // ------------------------------------------------------------------

    public function testContainerUnknownServiceAndTransientSemantics(): void
    {
        $container = new Container();
        $container->register('transient', static fn (): \stdClass => new \stdClass(), [], 'core', ServiceLifetime::TRANSIENT);
        $container->validateAndFreeze();

        self::assertNotSame($container->get('transient'), $container->get('transient'));

        $this->expectException(ServiceNotFoundException::class);
        $container->get('ghost');
    }

    public function testContainerRejectsDuplicateRegistration(): void
    {
        $container = new Container();
        $container->register('dup', static fn (): \stdClass => new \stdClass(), [], 'core');

        $this->expectException(InvalidFactoryException::class);
        $container->register('dup', static fn (): \stdClass => new \stdClass(), [], 'core');
    }

    // ------------------------------------------------------------------
    // Router: fresh instance add/match/freeze cycle
    // ------------------------------------------------------------------

    public function testRouterFreshAddMatchAndFreeze(): void
    {
        $router = new Router();
        $router->add('GET', '/hello/{id}', 'h_hello', 'core', 0, 'hello');
        self::assertTrue($router->hasRouteName('hello'));
        self::assertSame('/hello/{id}', $router->patternFor('hello'));
        $router->freeze();

        try {
            $router->add('GET', '/late', 'h_late');
            self::fail('add after freeze must throw');
        } catch (\LogicException) {
        }

        $match = $router->match('GET', '/hello/7');
        self::assertIsArray($match);
        self::assertNotEmpty($match);
    }

    // ------------------------------------------------------------------
    // Stream seek/detach guards + CronExpression extras
    // ------------------------------------------------------------------

    public function testStreamSeekInvalidWhenceThrows(): void
    {
        $stream = Stream::fromString('xyz');
        $this->expectException(\RuntimeException::class);
        $stream->seek(0, 999);
    }

    public function testCronExpressionStepAndStarPatterns(): void
    {
        $everyTwoHours = CronExpression::parse('0 */2 * * *');
        $base = strtotime('2026-03-01T00:10:00+00:00');
        $next = $everyTwoHours->nextRunAfter($base * 1_000_000_000);
        self::assertSame(strtotime('2026-03-01T02:00:00+00:00') * 1_000_000_000, $next);
        self::assertTrue($everyTwoHours->matchesUtc(strtotime('2026-03-01T04:00:00+00:00')));

        $this->expectException(\InvalidArgumentException::class);
        CronExpression::parse('0 0 0 * *');
    }

    // ------------------------------------------------------------------
    // FilterSpec: ranges and caps
    // ------------------------------------------------------------------

    public function testFilterSpecRangeConditionsAndRowFiltering(): void
    {
        $spec = FilterSpec::fromQuery(
            ['filter' => ['price_gte' => 10, 'status' => 'open', 'ghost' => 'x', 'junk' => [1]]],
            ['price_gte', 'price_lte', 'status'],
        );

        $rows = $spec->applyTo([
            ['price' => 20, 'price_gte' => 20, 'status' => 'open'],
            ['price' => 5, 'price_gte' => 5, 'status' => 'closed'],
            ['price' => 12, 'price_gte' => 12, 'status' => 'open'],
        ]);
        self::assertIsArray($rows);
        self::assertCount(2, $rows, 'gte=20/open and gte=12/open survive; closed row is filtered');
        foreach ($rows as $row) {
            self::assertSame('open', $row['status']);
            self::assertGreaterThanOrEqual(10, $row['price_gte']);
        }
    }
}
