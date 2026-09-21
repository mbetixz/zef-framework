<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.9.0 — Application layer (autowiring compile pass).
 *
 * Runs BEFORE Container::validateAndFreeze(). For every requested class it
 * produces a ServiceDefinition whose $dependencies list is complete (direct
 * services, synthetic @value:* services, and — through the definitions of
 * those dependencies — the whole transitive graph), so the untouched
 * DependencyGraphValidator / ContainerCompiler can verify cycles, cross-module
 * references, and singleton-closure lifetime rules exactly as for
 * hand-written registrations.
 *
 * Reflection is used ONLY in this phase. Every registered factory is a pure
 * generated \Closure (see AutowireAotCompiler) — zero reflection at runtime.
 */

namespace Zef\Framework\Container\Autowiring;

use Zef\Framework\Autowiring\AutowireMetadata;
use Zef\Framework\Autowiring\AutowireParameterSpec;
use Zef\Framework\Autowiring\AutowireResult;
use Zef\Framework\Container\Container;
use Zef\Framework\Container\ServiceDefinition;
use Zef\Framework\Container\ServiceLifetime;
use Zef\Framework\Exception\InvalidConfigurationException;
use Zef\Framework\Exception\ServiceCircularDependencyException;
use Zef\Framework\Exception\ServiceNotFoundException;

final class AutowireCompilerPass
{
    public const string VALUE_SERVICE_PREFIX = '@value:';

    private readonly ReflectionMetadataExtractor $extractor;

    /**
     * @var array<string,AutowireMetadata>
     */
    private array $metadata = [];

    /**
     * @var array<string,string>
     */
    private array $factoryCode = [];

    /**
     * @var list<string>
     */
    private array $generatedIds = [];

    /**
     * @var list<string>
     */
    private array $reusedIds = [];

    /**
     * @var list<string>
     */
    private array $valueServiceIds = [];

    /**
     * @param array<string,mixed> $configValues source for #[Value('key')] lookups
     * @param null|string         $module       module attributed to every generated definition
     * @param string              $lifetime     lifetime for every generated definition
     */
    public function __construct(
        ?ReflectionMetadataExtractor $extractor = null,
        private readonly array $configValues = [],
        private readonly ?string $module = null,
        private readonly string $lifetime = ServiceLifetime::SINGLETON,
    ) {
        $this->extractor = $extractor ?? new ReflectionMetadataExtractor();
        ServiceLifetime::assert($lifetime);
    }

    /**
     * Autowire the given classes (and, recursively, their dependencies) into
     * the container. Must be called before validateAndFreeze().
     *
     * @param list<class-string> $classes
     */
    public function process(Container $container, array $classes): AutowireResult
    {
        if ($container->isFrozen()) {
            throw new \LogicException('Container is frozen.');
        }
        $this->metadata = [];
        $this->factoryCode = [];
        $this->generatedIds = [];
        $this->reusedIds = [];
        $this->valueServiceIds = [];

        foreach ($classes as $index => $class) {
            if (!is_string($class) || $class === '') {
                throw new InvalidConfigurationException("Cannot autowire classes[{$index}]: expected a class-name string.");
            }
            $this->autowireClass($container, $class, []);
        }

        return new AutowireResult(
            $this->metadata,
            $this->factoryCode,
            $this->generatedIds,
            $this->reusedIds,
            $this->valueServiceIds,
        );
    }

    /**
     * Ensure a service exists for $class and return its service ID.
     * Existing registrations/aliases are reused untouched.
     *
     * @param list<string> $classStack in-progress chain for cycle diagnostics
     */
    private function autowireClass(Container $container, string $class, array $classStack): string
    {
        if (!class_exists($class)) {
            throw new InvalidConfigurationException("Cannot autowire '{$class}': class does not exist.");
        }
        $ref = new \ReflectionClass($class);
        if (!$ref->isInstantiable()) {
            throw new InvalidConfigurationException("Cannot autowire '{$class}': interface, abstract class, or otherwise non-instantiable.");
        }
        if ($container->has($class)) {
            if (!in_array($class, $this->reusedIds, true) && !in_array($class, $this->generatedIds, true)) {
                $this->reusedIds[] = $class;
            }

            return $class;
        }
        if (in_array($class, $classStack, true)) {
            $chain = array_slice($classStack, (int) array_search($class, $classStack, true));
            $chain[] = $class;

            throw new ServiceCircularDependencyException($chain);
        }

        $spec = $this->extractor->extract($class);
        $deps = [];
        $args = [];
        foreach ($spec->parameters as $parameter) {
            $this->planParameter($container, $class, $parameter, $deps, $args, [...$classStack, $class]);
        }

        $metadata = new AutowireMetadata($class, $class, $deps, $args, $this->module, $this->lifetime);
        $code = AutowireAotCompiler::generateFactory($metadata);
        $factory = AutowireAotCompiler::evalFactory($code);

        $container->registerDefinition(new ServiceDefinition(
            id: $class,
            factory: $factory,
            dependencies: $deps,
            module: $this->module,
            lifetime: $this->lifetime,
            shared: $this->lifetime === ServiceLifetime::SINGLETON,
        ));

        $this->metadata[$class] = $metadata;
        $this->factoryCode[$class] = $code;
        $this->generatedIds[] = $class;

        return $class;
    }

    /**
     * Resolve one constructor parameter into dependency entries + argument plan.
     *
     * @param list<string>                        $deps       out: ordered dependency IDs
     * @param list<array{0:'dep'|'literal',1:int|string}> $args out: argument plan
     * @param list<string>                        $classStack
     */
    private function planParameter(
        Container $container,
        string $ownerClass,
        AutowireParameterSpec $p,
        array &$deps,
        array &$args,
        array $classStack,
    ): void {
        if ($p->unsupportedTypeReason !== null) {
            if ($p->isOptional) {
                return; // rely on the declared default
            }

            throw new InvalidConfigurationException("Cannot autowire {$ownerClass}::\${$p->name}: {$p->unsupportedTypeReason}.");
        }

        // ---- variadic service collection ------------------------------------
        if ($p->isVariadic && $p->className !== null) {
            foreach ($this->collectImplementations($container, $p->className) as $serviceId) {
                $args[] = ['dep', $this->appendDependency($deps, $serviceId)];
            }

            return;
        }

        // ---- variadic scalar: optional #[Value] list baked as literals ------
        if ($p->isVariadic) {
            if ($p->valueKey !== null) {
                $raw = $this->configLookup($ownerClass, $p, $p->valueKey);
                if (!is_array($raw)) {
                    throw new InvalidConfigurationException("Cannot autowire {$ownerClass}::\${$p->name}: config '{$p->valueKey}' must be an array for a variadic parameter.");
                }
                foreach ($raw as $element) {
                    $args[] = ['literal', $this->renderLiteral($element, "config '{$p->valueKey}' element")];
                }
            }

            return;
        }

        // ---- explicit service reference --------------------------------------
        if ($p->injectId !== null) {
            $args[] = ['dep', $this->appendDependency($deps, $this->ensureService($container, $p->injectId, $classStack))];

            return;
        }

        // ---- explicit concrete target for interface/abstract types ----------
        if ($p->targetClass !== null) {
            $ref = new \ReflectionClass($p->targetClass);
            if (!$ref->isInstantiable()) {
                throw new InvalidConfigurationException("Cannot autowire {$ownerClass}::\${$p->name}: #[Target({$p->targetClass}::class)] is not an instantiable class.");
            }
            $serviceId = $this->autowireClass($container, $p->targetClass, $classStack);
            $args[] = ['dep', $this->appendDependency($deps, $serviceId)];

            return;
        }

        // ---- class/interface/enum type: layered binding resolution ----------
        if ($p->className !== null) {
            $type = $p->className;
            if ($container->has($type)) {
                // Exact FQCN factory or a registered alias for the FQCN.
                $args[] = ['dep', $this->appendDependency($deps, $type)];

                return;
            }
            if (class_exists($type) && new \ReflectionClass($type)->isInstantiable()) {
                // Concrete class: autowire it under its own FQCN id.
                $serviceId = $this->autowireClass($container, $type, $classStack);
                $args[] = ['dep', $this->appendDependency($deps, $serviceId)];

                return;
            }
            if ($p->hasDefaultValue) {
                // Unbound optional dependency: fall back to the constructor default.
                $args[] = ['literal', $this->renderDefault($ownerClass, $p)];

                return;
            }
            if ($p->allowsNull && $p->isOptional) {
                $args[] = ['literal', 'null'];

                return;
            }

            throw new InvalidConfigurationException("Cannot autowire {$ownerClass}::\${$p->name}: no binding for '{$type}'. " . 'Register the service, add an alias with that FQCN, use #[Target] / #[Inject], or give the parameter a default value.');
        }

        // ---- scalar/primitive parameter --------------------------------------
        if ($p->valueKey !== null) {
            $this->configLookup($ownerClass, $p, $p->valueKey); // validates presence
            $valueId = $this->ensureValueService($container, $p->valueKey);
            $args[] = ['dep', $this->appendDependency($deps, $valueId)];

            return;
        }
        if ($p->hasDefaultValue) {
            $args[] = ['literal', $this->renderDefault($ownerClass, $p)];

            return;
        }
        if ($p->allowsNull) {
            // Nullable without default (e.g. `mixed $x`): bake a safe null.
            $args[] = ['literal', 'null'];

            return;
        }

        throw new InvalidConfigurationException("Cannot autowire {$ownerClass}::\${$p->name}: required scalar parameter without " . '#[Value] and without a default value.');
    }

    /**
     * Explicit ID reference: must be an existing service/alias or an
     * instantiable, autowireable class.
     */
    private function ensureService(
        Container $container,
        string $id,
        array $classStack,
    ): string {
        if ($container->has($id)) {
            return $id;
        }
        if (class_exists($id) && new \ReflectionClass($id)->isInstantiable()) {
            return $this->autowireClass($container, $id, $classStack);
        }

        throw new ServiceNotFoundException($id, $this->module);
    }

    /**
     * Collect every registered service that satisfies the given type.
     * Sources: FQCN service IDs (is_a match) and factory closures with a
     * declared, compatible return type. Registration order is preserved.
     *
     * @return list<string>
     */
    private function collectImplementations(Container $container, string $type): array
    {
        $ids = [];
        foreach ($container->getRegistry()->definitions() as $id => $definition) {
            if (str_starts_with($id, self::VALUE_SERVICE_PREFIX)) {
                continue;
            }
            if ((class_exists($id) || interface_exists($id)) && is_a($id, $type, true)) {
                $ids[] = $id;

                continue;
            }
            $returnType = $this->factoryReturnType($definition->factory);
            if ($returnType !== null && is_a($returnType, $type, true)) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function factoryReturnType(mixed $factory): ?string
    {
        try {
            $fn = $factory instanceof \Closure ? $factory : \Closure::fromCallable($factory);
            $returnType = new \ReflectionFunction($fn)->getReturnType();
        } catch (\Throwable) {
            return null;
        }
        if ($returnType instanceof \ReflectionNamedType && !$returnType->isBuiltin()) {
            return $returnType->getName();
        }

        return null; // no declared return type / builtin / union: not inferable
    }

    /**
     * Synthetic singleton service holding one configuration value.
     */
    private function ensureValueService(Container $container, string $key): string
    {
        $id = self::VALUE_SERVICE_PREFIX . $key;
        if ($container->has($id)) {
            return $id;
        }
        $raw = $this->configLookup('(value)', null, $key);
        if ($raw === null) {
            // The resolver rejects null instances at runtime — fail fast here,
            // pointing at the parameter default as the correct mechanism.
            throw new InvalidConfigurationException("Config value '{$key}' is null: services can never resolve to null. " . 'Give the parameter a default value instead of #[Value].');
        }
        $literal = $this->renderLiteral($raw, "config value '{$key}'");
        $code = "static fn (\$ctx) => {$literal}";
        $factory = AutowireAotCompiler::evalFactory($code);

        $container->registerDefinition(new ServiceDefinition(
            id: $id,
            factory: $factory,
            dependencies: [],
            module: $this->module,
            lifetime: ServiceLifetime::SINGLETON,
        ));

        $this->metadata[$id] = new AutowireMetadata(
            serviceId: $id,
            className: get_debug_type($raw),
            dependencies: [],
            argumentPlan: [],
            module: $this->module,
            lifetime: ServiceLifetime::SINGLETON,
        );
        $this->factoryCode[$id] = $code;
        $this->generatedIds[] = $id;
        $this->valueServiceIds[] = $id;

        return $id;
    }

    private function configLookup(string $ownerClass, ?AutowireParameterSpec $p, string $key): mixed
    {
        if (!array_key_exists($key, $this->configValues)) {
            $param = $p instanceof AutowireParameterSpec ? "::\${$p->name}" : '';

            throw new InvalidConfigurationException("Cannot autowire {$ownerClass}{$param}: #[Value('{$key}')] has no value in the " . 'AutowireCompilerPass configuration.');
        }

        return $this->configValues[$key];
    }

    /** @param list<string> $deps */
    private function appendDependency(array &$deps, string $id): int
    {
        $index = array_search($id, $deps, true);
        if ($index !== false) {
            return (int) $index;
        }
        $deps[] = $id;

        return count($deps) - 1;
    }

    private function renderDefault(string $ownerClass, AutowireParameterSpec $p): string
    {
        if ($p->defaultValueConstant !== null) {
            return $p->defaultValueConstant;
        }

        return $this->renderLiteral($p->defaultValue, "default of {$ownerClass}::\${$p->name}");
    }

    private function renderLiteral(mixed $value, string $context): string
    {
        if ($value === null) {
            return 'null'; // var_export emits 'NULL'; prefer idiomatic lowercase
        }
        if (is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
            return var_export($value, true);
        }
        if (is_array($value)) {
            array_walk_recursive($value, static function ($v) use ($context): void {
                if (!is_scalar($v) && $v !== null) {
                    throw new InvalidConfigurationException("{$context} contains a non-exportable element (" . get_debug_type($v) . ').');
                }
            });

            return var_export($value, true);
        }

        throw new InvalidConfigurationException("{$context} is not exportable (" . get_debug_type($value) . ').');
    }
}
