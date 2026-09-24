<?php

declare(strict_types=1);

/*
 * ZEF Framework — Configuration System v2 (Domain layer: ports, contracts,
 * value objects). Added in v2.21.1 (radix index node for the configuration
 * pattern-query engine).
 */

namespace Zef\Framework\Config;

/**
 * Single node of the {@see ConfigRadixTree} index.
 *
 * A node represents one dotted-path segment: `$has` marks whether the path
 * exists in the merged configuration (every node built from data has it,
 * only the synthetic root does not), `$value` is the value stored AT the
 * node's path (a subtree array, a list, a scalar, null or an empty array)
 * and `$children` maps the next literal segment to its node.
 *
 * @internal data structure; see ConfigRadixTree for the query semantics
 */
final readonly class ConfigRadixNode
{
    /**
     * @param array<string,ConfigRadixNode> $children
     */
    public function __construct(
        public bool $has,
        public mixed $value,
        public array $children,
    ) {}
}
