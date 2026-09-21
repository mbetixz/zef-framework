<?php

declare(strict_types=1);

// ZEF Framework v2.9.0 — Domain layer (Autowiring value objects).

namespace Zef\Framework\Autowiring;

/**
 * Immutable outcome of one AutowireCompilerPass::process() run.
 */
final readonly class AutowireResult
{
    /**
     * @param array<string,AutowireMetadata> $metadata         service id => metadata (insertion order)
     * @param array<string,string>           $factoryCode      service id => generated factory PHP code
     * @param list<string>                   $generatedIds     ids of definitions created by the pass
     * @param list<string>                   $reusedIds        ids that already existed and were left untouched
     * @param list<string>                   $valueServiceIds  synthetic `@value:*` services created
     */
    public function __construct(
        public array $metadata,
        public array $factoryCode,
        public array $generatedIds,
        public array $reusedIds = [],
        public array $valueServiceIds = [],
    ) {}

    /** @return list<string> */
    public function transitiveDependenciesOf(string $serviceId): array
    {
        $seen = [];
        $stack = [$serviceId];
        while ($stack !== []) {
            $current = array_pop($stack);
            $deps = $this->metadata[$current]->dependencies ?? [];
            foreach ($deps as $dep) {
                if (!isset($seen[$dep])) {
                    $seen[$dep] = true;
                    $stack[] = $dep;
                }
            }
        }
        unset($seen[$serviceId]);

        return array_keys($seen);
    }
}
