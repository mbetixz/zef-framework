<?php

declare(strict_types=1);

/*
 * ZEF Framework — v2.11.0 "RadixTree Namespace Container" (Domain layer).
 *
 * Immutable namespace-scope policy consumed by RadixTreeCompilerPass.
 * Complements ArchitecturePolicy: instead of per-registration module tags,
 * whole namespace subtrees are governed by position in the radix tree.
 */

namespace Zef\Framework\Policy;

use Zef\Framework\Container\NamespaceRadixTree;

/**
 * Namespace scope policy.
 *
 * Scope semantics (applied to the annotated subtree):
 *  - public   : default; any service may depend on it.
 *  - internal : only services INSIDE the same subtree may depend on it
 *               (violation = hard deny, ModuleDependencyViolationException).
 *  - module   : services outside the subtree may depend on distinct targets
 *               within it, up to maxCrossScopeRefs per (consumer-prefix =>
 *               target-prefix) pair — mirroring the cross-module budget
 *               semantics of DependencyGraphValidator.
 */
final readonly class NamespaceScopePolicy
{
    /**
     * @var array<string,string> normalized prefix => scope kind
     */
    public array $scopes;

    /**
     * @param array<string,string> $scopes namespace prefix => one of the NamespaceRadixTree::SCOPE_* kinds
     * @param int $maxCrossScopeRefs >= 0; 0 denies ALL outbound references into 'module' subtrees
     */
    public function __construct(
        array $scopes = [],
        public int $maxCrossScopeRefs = 0,
    ) {
        $normalized = [];
        foreach ($scopes as $prefix => $scope) {
            if (!is_string($prefix) || $prefix === '') {
                throw new \InvalidArgumentException('Namespace scope prefix must be a non-empty string.');
            }
            if (!in_array($scope, NamespaceRadixTree::SCOPES, true)) {
                throw new \InvalidArgumentException("Unknown namespace scope '{$scope}' for prefix '{$prefix}'.");
            }
            $normalized[self::normalize($prefix)] = $scope;
        }
        if ($this->maxCrossScopeRefs < 0) {
            throw new \InvalidArgumentException('maxCrossScopeRefs must be >= 0.');
        }
        ksort($normalized, SORT_STRING);
        $this->scopes = $normalized;
    }

    /** @return array<string,string> normalized prefix => scope kind */
    public function normalizedScopes(): array
    {
        return $this->scopes;
    }

    /** Ensures a single trailing separator, e.g. "Zef\Framework" => "Zef\Framework\". */
    public static function normalize(string $prefix): string
    {
        $trimmed = rtrim($prefix, '\\');

        return $trimmed === '' ? '\\' : $trimmed . '\\';
    }
}
