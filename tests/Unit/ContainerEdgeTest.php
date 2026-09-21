<?php

declare(strict_types=1);

/*
 * ZEF Framework — Coverage push #5 (v2.13.1): container guards and budgets,
 * module registry lifecycle matrix, configuration governance, router
 * budgets/groups/constraints and resolver context helpers.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Config\ConfigurationGovernance;
use Zef\Framework\Config\ConfigurationSnapshot;
use Zef\Framework\Config\ModuleContext;
use Zef\Framework\Config\ModuleDefinition;
use Zef\Framework\Config\ModuleInterface;
use Zef\Framework\Config\ModuleRegistrar;
use Zef\Framework\Config\ModuleRegistry;
use Zef\Framework\Container\Container;
use Zef\Framework\Container\ServiceDefinition;
use Zef\Framework\Exception\InvalidConfigurationException;
use Zef\Framework\Exception\InvalidFactoryException;
use Zef\Framework\Exception\RouteConstraintException;
use Zef\Framework\Policy\ArchitecturePolicy;
use Zef\Framework\Router\Router;

/**
 * @internal
 */
final class ContainerEdgeTest extends TestCase
{
    // ------------------------------------------------------------------
    // Container guards & budgets
    // ------------------------------------------------------------------

    public function testContainerGuardsAgainstPostFreezeMutation(): void
    {
        $container = new Container();
        $container->register('svc', static fn (): \stdClass => new \stdClass(), [], 'test');
        $container->validateAndFreeze();

        $this->expectException(\LogicException::class);
        $container->configurePolicies(3);
    }

    public function testContainerRegisterAfterFreezeFails(): void
    {
        $container = new Container();
        $container->validateAndFreeze();
        $this->expectException(\LogicException::class);
        $container->register('late', static fn (): \stdClass => new \stdClass(), [], 'test');
    }

    public function testContainerEnforcesRegistrationBudget(): void
    {
        $container = new Container(false, new ArchitecturePolicy(0, 2));
        $container->register('a', static fn (): \stdClass => new \stdClass(), [], 'test');
        $container->register('b', static fn (): \stdClass => new \stdClass(), [], 'test');
        $this->expectException(InvalidConfigurationException::class);
        $container->register('c', static fn (): \stdClass => new \stdClass(), [], 'test');
    }

    public function testContainerRegisterDefinitionRejectsDuplicatesAndFreeze(): void
    {
        $container = new Container();
        $definition = new ServiceDefinition('dup.svc', static fn (): \stdClass => new \stdClass());
        $container->registerDefinition($definition);
        $this->expectException(InvalidFactoryException::class);
        $container->registerDefinition($definition);
    }

    public function testContainerRegisterDefinitionAfterFreezeFails(): void
    {
        $container = new Container();
        $container->validateAndFreeze();
        $this->expectException(\LogicException::class);
        $container->registerDefinition(new ServiceDefinition('late.svc', static fn (): \stdClass => new \stdClass()));
    }

    public function testContainerAliasAfterFreezeFails(): void
    {
        $container = new Container();
        $container->validateAndFreeze();
        $this->expectException(\LogicException::class);
        $container->alias('late.alias', 'target');
    }

    public function testContainerWarmSingletonsRequiresFreeze(): void
    {
        $container = new Container();
        $this->expectException(\LogicException::class);
        $container->warmSingletons();
    }

    public function testContainerResetAndViews(): void
    {
        $container = new Container(true);
        $container->register('svc', static fn (): \stdClass => new \stdClass(), [], 'test');
        $container->alias('svc.alias', 'svc', 'test');
        $container->validateAndFreeze();
        self::assertTrue($container->isDebug());
        self::assertContains('svc', $container->getRegisteredIds());
        self::assertContains('svc.alias', $container->getRegisteredIds());
        self::assertSame(['svc.alias' => 'svc'], $container->getAliasMap());
        $container->get('svc');
        $container->reset(true);
        self::assertInstanceOf(\stdClass::class, $container->get('svc'));
    }

    public function testContainerExposesRegistryView(): void
    {
        $container = new Container();
        $container->register('svc', static fn (): \stdClass => new \stdClass(), [], 'test');
        $definitions = $container->getRegistry()->definitions();
        self::assertSame('svc', $definitions['svc']->id);
    }

    public function testModuleRegistryRejectsInvalidAndDuplicateNames(): void
    {
        $registry = new ModuleRegistry();

        try {
            $registry->add($this->module('bad name!'));
            self::fail('Expected invalid module name rejection.');
        } catch (InvalidConfigurationException) {
            self::addToAssertionCount(1);
        }
        $registry->add($this->module('dup'));
        $this->expectException(InvalidConfigurationException::class);
        $registry->add($this->module('DUP'));
    }

    public function testModuleRegistryRejectsDefinitionNameMismatch(): void
    {
        $registry = new ModuleRegistry();
        $module = new class implements ModuleInterface {
            #[\Override]
            public function getName(): string
            {
                return 'outer';
            }

            #[\Override]
            public function getDefinition(): ModuleDefinition
            {
                return new ModuleDefinition('inner');
            }

            #[\Override]
            public function register(ModuleContext $context): void {}

            #[\Override]
            public function boot(ModuleContext $context): void {}

            #[\Override]
            public function start(ModuleContext $context): void {}

            #[\Override]
            public function shutdown(ModuleContext $context): void {}
        };
        $this->expectException(InvalidConfigurationException::class);
        $registry->add($module);
    }

    public function testModuleRegistryDetectsCircularDependencies(): void
    {
        $registry = new ModuleRegistry();
        $a = $this->moduleWithDependency('mod-a', 'mod-b');
        $b = $this->moduleWithDependency('mod-b', 'mod-a');
        $registry->add($a);
        $registry->add($b);
        $this->expectException(InvalidConfigurationException::class);
        $registry->resolveOrder();
    }

    public function testModuleRegistryDetectsMissingDependency(): void
    {
        $registry = new ModuleRegistry();
        $registry->add($this->moduleWithDependency('lonely', 'ghost'));
        $this->expectException(InvalidConfigurationException::class);
        $registry->resolveOrder();
    }

    public function testModuleRegistryLifecyclePhasesAreOrdered(): void
    {
        $registry = new ModuleRegistry();
        $registry->add($this->module('alpha'));
        $this->expectException(\LogicException::class);
        $registry->bootAll(new Container());
    }

    public function testModuleRegistryStartRequiresBoot(): void
    {
        $registry = new ModuleRegistry();
        $registry->add($this->module('alpha'));
        $container = new Container();
        $registry->registerAll($this->noopRegistrar(), $container);
        $this->expectException(\LogicException::class);
        $registry->startAll($container);
    }

    public function testModuleRegistryShutdownBeforeRegisterIsNoop(): void
    {
        $registry = new ModuleRegistry();
        $registry->shutdownAll(new Container());
        self::assertFalse($registry->isRegistered());
        self::assertFalse($registry->isBooted());
        self::assertFalse($registry->isStarted());
    }

    public function testModuleRegistryAddAfterRegistrationFails(): void
    {
        $registry = new ModuleRegistry();
        $registry->add($this->module('alpha'));
        $registry->registerAll($this->noopRegistrar(), new Container());
        $this->expectException(\LogicException::class);
        $registry->add($this->module('beta'));
    }

    // ------------------------------------------------------------------
    // ConfigurationGovernance
    // ------------------------------------------------------------------

    public function testGovernancePublishesValidatedSnapshots(): void
    {
        $governance = new ConfigurationGovernance();
        self::assertNull($governance->current());
        $seen = [];
        $governance->addValidator('limits', static function (array $values) use (&$seen): void {
            $seen = $values;
        });
        $snapshot = $governance->publish(['max' => 5], 2);
        self::assertSame(['max' => 5], $seen);
        self::assertSame(['max' => 5], $snapshot->values);
        self::assertSame(2, $snapshot->version);
        self::assertSame($snapshot, $governance->current());
    }

    public function testGovernanceWrapsValidatorFailures(): void
    {
        $governance = new ConfigurationGovernance();
        $governance->addValidator('strict', static function (array $values): never {
            throw new \RuntimeException('max must be positive');
        });

        try {
            $governance->publish(['max' => -1], 1);
            self::fail('Expected validation failure.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('strict', $e->getMessage());
        }
    }

    public function testGovernanceRejectsBadValidatorNamesAndLateAdditions(): void
    {
        $governance = new ConfigurationGovernance();
        $this->expectException(\InvalidArgumentException::class);
        $governance->addValidator('bad name!', static function (array $values): void {});
    }

    public function testGovernanceFreezesValidatorsAfterPublish(): void
    {
        $governance = new ConfigurationGovernance();
        $governance->publish([], 1);
        $this->expectException(\LogicException::class);
        $governance->addValidator('late', static function (array $values): void {});
    }

    public function testGovernanceSnapshotRejectsNonPositiveVersion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ConfigurationSnapshot([], 0);
    }

    // ------------------------------------------------------------------
    // Router budgets, groups, constraints
    // ------------------------------------------------------------------

    public function testRouterBudgetGuards(): void
    {
        $router = new Router();
        $router->setMaxRoutesBudget(1);
        $router->add('GET', '/one', 'svc');
        $this->expectException(InvalidConfigurationException::class);
        $router->add('GET', '/two', 'svc');
    }

    public function testRouterBudgetCannotGoBelowCurrentCount(): void
    {
        $router = new Router();
        $router->add('GET', '/one', 'svc');
        $this->expectException(\InvalidArgumentException::class);
        $router->setMaxRoutesBudget(0);
    }

    public function testRouterBudgetFrozenGuard(): void
    {
        $router = new Router();
        $router->freeze();
        $this->expectException(\LogicException::class);
        $router->setMaxRoutesBudget(5);
    }

    public function testRouterRejectsBadPathsAndMethodsAndDuplicateParams(): void
    {
        $router = new Router();

        try {
            $router->add('GET', 'no-slash', 'svc');
            self::fail('Expected path rejection.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        $this->expectException(\InvalidArgumentException::class);
        $router->add('GET', '/dup/{id}/{id}', 'svc');
    }

    public function testRouterGroupValidations(): void
    {
        $router = new Router();

        try {
            $router->group(['prefix' => 'api'], static function (): void {});
            self::fail('Expected group prefix rejection.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        $badGroup = ['name' => 42];
        $this->expectException(\InvalidArgumentException::class);
        // @phpstan-ignore argument.type
        $router->group($badGroup, static function (): void {});
    }

    public function testRouterGroupMergesPrefixesAndNames(): void
    {
        $router = new Router();
        $router->group(['prefix' => '/api', 'name' => 'api.'], static function () use ($router): void {
            $router->group(['prefix' => '/v1'], static function () use ($router): void {
                $router->add('GET', '/users', 'svc', null, 0, 'users');
            });
        });
        $router->freeze();
        $match = $router->match('GET', '/api/v1/users');
        self::assertSame('svc', $match['handler']);
        self::assertSame('/api/v1/users', $match['pattern']);
    }

    public function testRouterFallbackServesUnknownPaths(): void
    {
        $router = new Router();
        $router->fallback('fallback.svc');
        $router->freeze();
        $match = $router->matchOrFallback('GET', '/nowhere');
        self::assertSame('fallback.svc', $match['handler']);
    }

    public function testRouterExportAndImportRoundTrip(): void
    {
        $router = new Router();
        $router->add('GET', '/items/{id}', 'svc', 'mod', 5, 'items.show');
        $exported = $router->exportRoutes();
        $restored = Router::fromCompiledArray($exported);
        $match = $restored->match('GET', '/items/42');
        self::assertSame('svc', $match['handler']);
        self::assertSame(['id' => '42'], $match['params']);
    }

    public function testRouterParsesConstrainedDynamicSegments(): void
    {
        $router = new Router();
        $router->add('GET', '/files/{id:int}', 'svc');
        $router->freeze();
        $match = $router->match('GET', '/files/77');
        self::assertSame('svc', $match['handler']);
        self::assertSame(['id' => '77'], $match['params']);
        $this->expectException(RouteConstraintException::class);
        $router->match('GET', '/files/abc');
    }

    public function testRouterCustomConstraints(): void
    {
        $router = new Router();
        $router->addConstraint('slug', '/[a-z-]+/');
        $router->add('GET', '/posts/{slug:slug}', 'svc');
        $router->freeze();
        $match = $router->match('GET', '/posts/hello-world');
        self::assertSame(['slug' => 'hello-world'], $match['params']);
    }

    // ------------------------------------------------------------------
    // ModuleRegistry lifecycle matrix
    // ------------------------------------------------------------------

    /**
     * @param list<string> $dependencies
     */
    private function module(string $name, array $dependencies = []): ModuleInterface
    {
        return new readonly class($name, $dependencies) implements ModuleInterface {
            /**
             * @param list<string> $dependencies
             */
            public function __construct(
                private string $name,
                private array $dependencies,
            ) {}

            #[\Override]
            public function getName(): string
            {
                return $this->name;
            }

            #[\Override]
            public function getDefinition(): ModuleDefinition
            {
                return new ModuleDefinition($this->name, [], [], [], [], $this->dependencies);
            }

            #[\Override]
            public function register(ModuleContext $context): void {}

            #[\Override]
            public function boot(ModuleContext $context): void {}

            #[\Override]
            public function start(ModuleContext $context): void {}

            #[\Override]
            public function shutdown(ModuleContext $context): void {}
        };
    }

    private function moduleWithDependency(string $name, string $dependency): ModuleInterface
    {
        return new readonly class($name, $dependency) implements ModuleInterface {
            public function __construct(
                private string $name,
                private string $dependency,
            ) {}

            #[\Override]
            public function getName(): string
            {
                return $this->name;
            }

            #[\Override]
            public function getDefinition(): ModuleDefinition
            {
                return new ModuleDefinition($this->name, [], [], [], [], [$this->dependency]);
            }

            #[\Override]
            public function register(ModuleContext $context): void {}

            #[\Override]
            public function boot(ModuleContext $context): void {}

            #[\Override]
            public function start(ModuleContext $context): void {}

            #[\Override]
            public function shutdown(ModuleContext $context): void {}
        };
    }

    private function noopRegistrar(): ModuleRegistrar
    {
        return new class implements ModuleRegistrar {
            #[\Override]
            public function registerModule(string $module, array|ModuleDefinition $config): void {}
        };
    }
}
