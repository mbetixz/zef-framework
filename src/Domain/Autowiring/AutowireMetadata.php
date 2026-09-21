<?php

declare(strict_types=1);

// ZEF Framework v2.9.0 — Domain layer (Autowiring value objects).

namespace Zef\Framework\Autowiring;

use Zef\Framework\Container\ServiceLifetime;

/**
 * Complete instantiation plan for one autowired service.
 *
 * `$dependencies` is the authoritative, ordered list of service IDs (real
 * services and synthetic `@value:*` services alike) that lands in
 * ServiceDefinition::$dependencies — giving DependencyGraphValidator and
 * ContainerCompiler the full graph before validateAndFreeze() runs.
 *
 * `$argumentPlan` describes how to build the constructor call from the
 * resolved dependency values, one entry per emitted argument:
 *   - ['dep', int $index]            positional dependency value ($d{index})
 *   - ['literal', string $phpCode]   baked literal (var_export'ed value or constant name)
 * A variadic service parameter emits one 'dep' entry per collected service.
 */
final readonly class AutowireMetadata
{
    /**
     * @param list<string> $dependencies
     * @param list<array{0:'dep'|'literal', 1:int|string}> $argumentPlan
     */
    public function __construct(
        public string $serviceId,
        public string $className,
        public array $dependencies,
        public array $argumentPlan,
        public ?string $module = null,
        public string $lifetime = ServiceLifetime::SINGLETON,
    ) {
        foreach ($dependencies as $dep) {
            if (!is_string($dep) || $dep === '') {
                throw new \InvalidArgumentException("Autowire metadata for '{$serviceId}': dependencies must be non-empty strings.");
            }
        }
        foreach ($argumentPlan as $entry) {
            if (
                !is_array($entry)
                || !isset($entry[0], $entry[1])
                || ($entry[0] !== 'dep' && $entry[0] !== 'literal')
            ) {
                throw new \InvalidArgumentException("Autowire metadata for '{$serviceId}': malformed argument plan entry.");
            }
            if ($entry[0] === 'dep' && (!is_int($entry[1]) || $entry[1] < 0 || !isset($dependencies[$entry[1]]))) {
                throw new \InvalidArgumentException("Autowire metadata for '{$serviceId}': argument plan references unknown dependency index.");
            }
            if ($entry[0] === 'literal' && !is_string($entry[1])) {
                throw new \InvalidArgumentException("Autowire metadata for '{$serviceId}': literal argument must be PHP code.");
            }
        }
    }
}
