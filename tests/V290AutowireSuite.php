<?php

declare(strict_types=1);

/*
 * ZEF Framework — v2.9.0 autowiring suite (dedicated test file).
 *
 * Covers the Advanced Autowiring Engine end to end:
 *   - #[Inject] / #[Value] / #[Target] attribute resolution
 *   - interface binding layers + variadic service collection
 *   - complete dependency graphs handed to validateAndFreeze()
 *   - AOT code generation, export/load cold start (zero reflection)
 *   - frozen/immutability invariants untouched
 *
 * Run standalone:  bin/zef --self-test=v290
 * Run everything:  bin/zef --self-test
 */

namespace Zef\Test;

use Zef\Framework\Autowiring\AutowireResult;
use Zef\Framework\Container\Autowiring\AutowireAotCompiler;
use Zef\Framework\Container\Autowiring\AutowireCompilerPass;
use Zef\Framework\Container\Container;
use Zef\Framework\Container\ServiceDefinition;
use Zef\Framework\Container\ServiceLifetime;
use Zef\Framework\Exception\InvalidConfigurationException;
use Zef\Framework\Exception\ModuleDependencyViolationException;
use Zef\Framework\Exception\ServiceCircularDependencyException;
use Zef\Framework\Exception\ServiceNotFoundException;
use Zef\Framework\Exception\ServiceResolutionException;

final class V290AutowireSuite
{
    public function __construct(private readonly CliRunner $runner) {}

    /** Entry point invoked by CliRunner::run(). */
    public function run(): void
    {
        $this->v290Attributes();
        $this->v290InterfaceBinding();
        $this->v290Variadic();
        $this->v290DependencyCompleteness();
        $this->v290GraphIntegration();
        $this->v290FrozenGuard();
        $this->v290AotCodegen();
        $this->v290AotColdStart();
        $this->v290RuntimeErrors();
        $this->v290Lifetimes();
        $this->v290ReuseAndImmutability();
    }

    private function v290Attributes(): void
    {
        $config = [
            'v290.http.host' => 'db.internal',
            'v290.http.port' => 5432,
            'v290.http.rate' => 0.75,
            'v290.http.debug' => true,
            'v290.http.allowed' => ['a', 'b', ['nested' => true]],
            'v290.tags' => ['red', 'blue'],
        ];
        $c = new Container();
        $result = new AutowireCompilerPass(configValues: $config, module: 'core')
            ->process($c, [V290HttpConfig::class, V290Vault::class])
        ;
        $c->validateAndFreeze();

        $http = $c->get(V290HttpConfig::class);
        $this->ok($http instanceof V290HttpConfig, 'v290: #[Value] class resolves to instance');
        $this->ok($http->host === 'db.internal' && $http->port === 5432, 'v290: #[Value] string + int injected');
        $this->ok($http->rate === 0.75 && $http->debug === true, 'v290: #[Value] float + bool injected');
        $this->ok($http->allowed === ['a', 'b', ['nested' => true]], 'v290: #[Value] nested array injected');
        $this->ok($http->timeout === 30 && $http->label === null, 'v290: constructor defaults used as fallback');
        $this->ok($c->has('@value:v290.http.host'), 'v290: config value materialised as @value service');

        $vault = $c->get(V290Vault::class);
        $this->ok($vault->key instanceof V290SecretKey && $vault->key->material() === 'secret-material', 'v290: #[Inject(Class::class)] autowires recursively');

        $this->ok(in_array(V290SecretKey::class, $result->transitiveDependenciesOf(V290Vault::class), true), 'v290: transitive closure includes nested class');
        $this->ok(in_array('@value:v290.http.host', $result->transitiveDependenciesOf(V290HttpConfig::class), true), 'v290: transitive closure includes @value services');
    }

    private function v290InterfaceBinding(): void
    {
        // Layer 1: alias registered under the interface FQCN.
        $c = new Container();
        $c->register(V290DbRepo::class, static fn (): V290DbRepo => new V290DbRepo(), []);
        $c->alias(V290RepoInterface::class, V290DbRepo::class);
        new AutowireCompilerPass()->process($c, [V290RepoConsumer::class]);
        $c->validateAndFreeze();
        $consumer = $c->get(V290RepoConsumer::class);
        $this->ok($consumer->repo instanceof V290DbRepo, 'v290: alias binding satisfies interface parameter');

        // Layer 2: explicit #[Target] — no global alias needed.
        $c2 = new Container();
        new AutowireCompilerPass()->process($c2, [V290CacheUser::class]);
        $c2->validateAndFreeze();
        $user = $c2->get(V290CacheUser::class);
        $this->ok($user->cache instanceof V290ArrayCache, 'v290: #[Target] binds interface to concrete');

        // Layer 3: unbound required interface fails with guidance.
        $c3 = new Container();
        $threw = false;

        try {
            new AutowireCompilerPass()->process($c3, [V290RepoConsumer::class]);
        } catch (InvalidConfigurationException $e) {
            $threw = str_contains($e->getMessage(), 'no binding for') && str_contains($e->getMessage(), 'V290RepoInterface');
        }
        $this->ok($threw, 'v290: unbound interface rejected with actionable message');

        // #[Inject] pointing at a non-service, non-class ID.
        $c4 = new Container();
        $missing = false;

        try {
            new AutowireCompilerPass()->process($c4, [V290BoomService::class]);
        } catch (ServiceNotFoundException $e) {
            $missing = $e->serviceId === 'v290.boom';
        }
        $this->ok($missing, 'v290: #[Inject] unknown id fails as ServiceNotFoundException');
    }

    private function v290Variadic(): void
    {
        $c = new Container();
        $c->register('v290.manual.queue', static fn (): V290HandlerInterface => new V290QueueHandler(), []);
        $c->register(V290CronHandler::class, static fn (): V290CronHandler => new V290CronHandler(), []);
        new AutowireCompilerPass()->process($c, [V290MailHandler::class, V290AuditHandler::class, V290Pipeline::class]);
        $c->validateAndFreeze();

        $pipeline = $c->get(V290Pipeline::class);
        $this->ok(count($pipeline->handlers) === 4, 'v290: variadic collects every implementation');
        $names = array_map(static fn ($h) => $h->name(), $pipeline->handlers);
        $this->ok($names === ['queue', 'cron', 'mail', 'audit'], 'v290: collection follows registration order');
        $this->ok($pipeline->handlers[0] instanceof V290QueueHandler && $pipeline->handlers[2] instanceof V290MailHandler, 'v290: manual closure-return-type + generated services both collected');

        $deps = $c->getRegistry()->definitions()[V290Pipeline::class]->dependencies;
        $this->ok(count($deps) === 4 && in_array(V290MailHandler::class, $deps, true), 'v290: variadic deps listed in ServiceDefinition');

        $c2 = new Container();
        $pass = new AutowireCompilerPass(configValues: ['v290.tags' => ['red', 'blue']]);
        $pass->process($c2, [V290ScalarPipeline::class]);
        $c2->validateAndFreeze();
        $scalar = $c2->get(V290ScalarPipeline::class);
        $this->ok($scalar->tags === ['red', 'blue'], 'v290: scalar variadic baked from #[Value] list');
    }

    private function v290DependencyCompleteness(): void
    {
        $c = new Container();
        $result = new AutowireCompilerPass()->process($c, [V290Root::class, V290OptionalMiddle::class, V290UnboundOptional::class]);

        $rootDeps = $result->metadata[V290Root::class]->dependencies;
        $this->ok($rootDeps === [V290Middle::class], 'v290: root deps list exact and ordered');
        $middleDeps = $result->metadata[V290Middle::class]->dependencies;
        $this->ok($middleDeps === [V290Leaf::class], 'v290: nested definitions carry their own deps');
        $this->ok($result->transitiveDependenciesOf(V290Root::class) === [V290Middle::class, V290Leaf::class], 'v290: transitive closure spans the whole chain');

        $optionalDeps = $result->metadata[V290OptionalMiddle::class]->dependencies;
        $this->ok($optionalDeps === [V290SecretKey::class, V290Leaf::class], 'v290: middle scalar default keeps positional integrity');
        $code = $result->factoryCode[V290OptionalMiddle::class];
        $this->ok(str_contains($code, '5') && str_contains($code, 'new \Zef\Test\V290OptionalMiddle($d0, 5, $d1)'), 'v290: generated code bakes middle default');

        $unbound = $c->getRegistry()->definitions()[V290UnboundOptional::class]->dependencies;
        $this->ok($unbound === [], 'v290: unbound optional dependency adds no dep');
        $c->validateAndFreeze();
        $obj = $c->get(V290UnboundOptional::class);
        $this->ok($obj->cache === null, 'v290: unbound optional falls back to null default');
    }

    private function v290GraphIntegration(): void
    {
        // Complete graph passes the untouched validator.
        $c = new Container();
        new AutowireCompilerPass()->process($c, [V290Root::class]);
        $c->validateAndFreeze();
        $root = $c->get(V290Root::class);
        $this->ok($root->middle->leaf->seed() === 'leaf', 'v290: transitive wiring produces correct instances');

        // Compile-time class cycle detection with a readable chain.
        $c2 = new Container();
        $chain = null;

        try {
            new AutowireCompilerPass()->process($c2, [V290CycleA::class]);
        } catch (ServiceCircularDependencyException $e) {
            $chain = $e->getChain();
        }
        $this->ok($chain === [V290CycleA::class, V290CycleB::class, V290CycleA::class], 'v290: class cycle detected at compile time with chain');

        // Cross-module references still enforced by validateAndFreeze().
        $c3 = new Container();
        $c3->configurePolicies(1);
        $passBeta = new AutowireCompilerPass(module: 'beta');
        $passBeta->process($c3, [V290BetaOne::class, V290BetaTwo::class]);
        $passAlpha = new AutowireCompilerPass(module: 'alpha');
        $passAlpha->process($c3, [V290AlphaConsumer::class]);
        $crossBlocked = false;

        try {
            $c3->validateAndFreeze();
        } catch (ModuleDependencyViolationException $e) {
            $crossBlocked = str_contains($e->getMessage(), "'alpha' exceeds cross-module reference limit (1) towards 'beta'");
        }
        $this->ok($crossBlocked, 'v290: cross-module policy enforced on autowired graph');

        $c4 = new Container();
        $c4->configurePolicies(5);
        $passBeta4 = new AutowireCompilerPass(module: 'beta');
        $passBeta4->process($c4, [V290BetaOne::class, V290BetaTwo::class]);
        $passAlpha4 = new AutowireCompilerPass(module: 'alpha');
        $passAlpha4->process($c4, [V290AlphaConsumer::class]);
        $c4->validateAndFreeze();
        $alpha = $c4->get(V290AlphaConsumer::class);
        $this->ok($alpha->one->tag() === 'beta-one' && $alpha->two->tag() === 'beta-two', 'v290: cross-module wiring works within budget');
    }

    private function v290FrozenGuard(): void
    {
        $c = new Container();
        new AutowireCompilerPass()->process($c, [V290Leaf::class]);
        $c->validateAndFreeze();
        $this->throws(\LogicException::class, fn (): AutowireResult => new AutowireCompilerPass()->process($c, [V290Middle::class]), 'v290: process() on frozen container refused');
        $this->throws(ServiceNotFoundException::class, fn (): mixed => $c->get(V290Middle::class), 'v290: unregistered service after freeze still ServiceNotFoundException');
    }

    private function v290AotCodegen(): void
    {
        $c = new Container();
        $result = new AutowireCompilerPass(configValues: [
            'v290.http.host' => 'h',
            'v290.http.port' => 1,
            'v290.http.rate' => 0.5,
            'v290.http.debug' => false,
            'v290.http.allowed' => [],
        ])->process(
            $c,
            [V290SecretKey::class, V290HttpConfig::class],
        );
        $this->ok(count($result->generatedIds) >= 3, 'v290: result reports generated services');
        foreach ($result->factoryCode as $serviceId => $code) {
            if (!str_starts_with($serviceId, '@value:') && str_contains($code, 'Reflection')) {
                $this->ok(false, "v290: generated code for {$serviceId} must not use Reflection");

                return;
            }
        }
        $this->ok(true, 'v290: generated factory code is reflection-free');

        $sample = AutowireAotCompiler::generateFactory($result->metadata[V290HttpConfig::class]);
        $this->ok(str_starts_with($sample, 'static fn ($ctx'), 'v290: factory code has resolver-compatible signature');
        $this->ok(
            str_contains($sample, 'new \Zef\Test\V290HttpConfig($d0, $d1, $d2, $d3, $d4, 30, null)'),
            'v290: generated args map deps positionally and bake defaults',
        );
        $this->ok(
            $result->factoryCode['@value:v290.http.host'] === "static fn (\$ctx) => 'h'",
            'v290: value-service factory is a pure constant closure',
        );
    }

    private function v290AotColdStart(): void
    {
        // Warm path: compile pass, then export.
        $warm = new Container();
        $result = new AutowireCompilerPass(configValues: [
            'v290.http.host' => 'cold.internal',
            'v290.http.port' => 9090,
            'v290.http.rate' => 1.5,
            'v290.http.debug' => false,
            'v290.http.allowed' => ['x'],
        ])->process($warm, [V290HttpConfig::class, V290Root::class, V290Vault::class]);

        $path = tempnam(sys_get_temp_dir(), 'zef290aot');
        AutowireAotCompiler::export($result, $path, 'v290 test export');
        $this->ok(is_file($path), 'v290: AOT export writes a file');

        $raw = (string) file_get_contents($path);
        $this->ok(!str_contains($raw, 'Reflection'), 'v290: exported AOT file contains no Reflection');
        $this->ok(str_contains($raw, 'static fn ($ctx'), 'v290: exported file embeds pure closures');

        // Cold path: brand-new container built ONLY from the exported file.
        $cold = new Container();
        AutowireAotCompiler::bootContainer($cold, $path);
        $cold->validateAndFreeze();
        $http = $cold->get(V290HttpConfig::class);
        $this->ok($http->host === 'cold.internal' && $http->port === 9090 && $http->allowed === ['x'], 'v290: cold-start container resolves exported services');
        $this->ok($cold->get(V290Root::class)->middle->leaf->seed() === 'leaf', 'v290: cold-start transitive graph intact');
        $this->ok($cold->get(V290Vault::class)->key instanceof V290SecretKey, 'v290: cold-start recursive wiring intact');

        // Lifecycle parity: warm and cold singletons are distinct containers.
        $this->ok($warm->get(V290Root::class)->middle instanceof V290Middle, 'v290: warm container still functional after export');

        // Malformed export rejected.
        $badPath = tempnam(sys_get_temp_dir(), 'zef290bad');
        file_put_contents($badPath, "<?php return ['oops' => ['factory' => 'not-callable']];\n");
        $this->throws(InvalidConfigurationException::class, fn (): array => AutowireAotCompiler::loadDefinitions($badPath), 'v290: malformed AOT file rejected');
        unlink($badPath); // nosemgrep: php.lang.security.unlink-use
        unlink($path); // nosemgrep: php.lang.security.unlink-use
    }

    private function v290RuntimeErrors(): void
    {
        $c = new Container();
        $this->throws(ServiceNotFoundException::class, fn (): mixed => $c->get('Zef\Test\Nope\Nothing'), 'v290: unknown id throws ServiceNotFoundException (NotFoundExceptionInterface)');

        $c2 = new Container();
        $c2->register('v290.nullish', static fn (): null => null, []);
        $c2->validateAndFreeze();
        $this->throws(ServiceResolutionException::class, fn (): mixed => $c2->get('v290.nullish'), 'v290: null factory result surfaces as ServiceResolutionException');

        // The compile pass must not invoke user factories (lazy semantics preserved).
        $c3 = new Container();
        $c3->register('v290.connection', static fn (): \stdClass => throw new \RuntimeException('kaput'), []);
        new AutowireCompilerPass()->process($c3, [V290NamedDep::class]);
        $this->ok($c3->has(V290NamedDep::class), 'v290: compile pass does not invoke user factories');
        $c3->validateAndFreeze();
        $this->throws(\RuntimeException::class, fn (): mixed => $c3->get(V290NamedDep::class), 'v290: dep factory failure propagates at get() (documented)');

        // Null config value for #[Value] rejected at compile time (fail fast,
        // because the resolver can never return a null instance at runtime).
        $c4 = new Container();
        $this->throws(
            InvalidConfigurationException::class,
            fn (): AutowireResult => new AutowireCompilerPass(configValues: ['v290.http.host' => null])->process($c4, [V290HttpConfig::class]),
            'v290: null #[Value] config rejected at compile time',
        );
    }

    private function v290Lifetimes(): void
    {
        // Default: singletons.
        $c = new Container();
        new AutowireCompilerPass()->process($c, [V290Leaf::class]);
        $c->validateAndFreeze();
        $this->ok($c->get(V290Leaf::class) === $c->get(V290Leaf::class), 'v290: generated default lifetime is singleton');

        // REQUEST lifetime via pass option, with scope isolation.
        $c2 = new Container();
        new AutowireCompilerPass(lifetime: ServiceLifetime::REQUEST)->process($c2, [V290Leaf::class]);
        $c2->validateAndFreeze();
        $s1 = $c2->createRequestScope();
        $a1 = $s1->get(V290Leaf::class);
        $this->ok($s1->get(V290Leaf::class) === $a1, 'v290: request-scoped autowired service stable within scope');
        $s2 = $c2->createRequestScope();
        $this->ok($s2->get(V290Leaf::class) !== $a1, 'v290: request-scoped autowired service isolated between scopes');
        $s1->close();
        $s2->close();

        // Singleton depending on request-scoped autowired service: rejected.
        $c3 = new Container();
        new AutowireCompilerPass(lifetime: ServiceLifetime::REQUEST)->process($c3, [V290Leaf::class]);
        new AutowireCompilerPass(lifetime: ServiceLifetime::SINGLETON)->process($c3, [V290Middle::class]);
        $this->throws(InvalidConfigurationException::class, fn () => $c3->validateAndFreeze(), 'v290: singleton-over-request violation still enforced by validator');
    }

    private function v290ReuseAndImmutability(): void
    {
        // Existing registrations are reused, never replaced.
        $c = new Container();
        $custom = new V290SecretKey();
        $c->register(V290SecretKey::class, static fn (): V290SecretKey => $custom, []);
        $result = new AutowireCompilerPass()->process($c, [V290Vault::class, V290SecretKey::class]);
        $this->ok(in_array(V290SecretKey::class, $result->reusedIds, true), 'v290: existing service reused');
        $this->ok(!in_array(V290SecretKey::class, $result->generatedIds, true), 'v290: existing service not regenerated');
        $c->validateAndFreeze();
        $this->ok($c->get(V290Vault::class)->key === $custom, 'v290: reused factory identity preserved');

        // ServiceDefinition stays readonly — the engine never weakens immutability.
        $definition = $c->getRegistry()->definitions()[V290SecretKey::class];
        $this->throws(\Error::class, fn (): string => $definition->id = 'hacked', 'v290: ServiceDefinition mutation still fatal');
        $this->ok($definition instanceof ServiceDefinition, 'v290: generated definitions are real ServiceDefinitions');

        // Tags untouched by the engine.
        $c2 = new Container();
        $c2->registerDefinition(new ServiceDefinition(
            'v290.tagged',
            static fn (): \stdClass => new \stdClass(),
            [],
            'm',
            ServiceLifetime::SINGLETON,
            tags: ['group.x'],
        ));
        new AutowireCompilerPass()->process($c2, [V290Leaf::class]);
        $c2->validateAndFreeze();
        $this->ok($c2->getRegistry()->definitions()['v290.tagged']->tags === ['group.x'], 'v290: pre-existing tags untouched');
    }

    private function ok(bool $cond, string $label): void
    {
        $this->runner->ok($cond, $label);
    }

    private function throws(string $class, callable $fn, string $label): void
    {
        $this->runner->throws($class, $fn, $label);
    }
}
