<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Validation;

use Zef\Framework\Container\ServiceLifetime;
use Zef\Framework\Exception\CircularAliasException;
use Zef\Framework\Exception\InvalidConfigurationException;
use Zef\Framework\Exception\ModuleDependencyViolationException;
use Zef\Framework\Exception\ServiceCircularDependencyException;
use Zef\Framework\Exception\ServiceNotFoundException;

final class DependencyGraphValidator
{
    public function validate(
        array $factories,
        array $aliases,
        array $depsOf,
        array $moduleOf,
        array $lifetimeOf = [],
        int $maxCrossModuleRefs = 0,
    ): void {
        $counts = [];
        $edgeSeen = [];
        foreach ($factories as $id => $_factory) {
            foreach ($depsOf[$id] ?? [] as $dep) {
                $canonical = $this->resolveAlias($dep, $aliases);
                if (!isset($factories[$canonical])) {
                    throw new ServiceNotFoundException((string) $dep, $moduleOf[$id] ?? null);
                }
                if (($lifetimeOf[$id] ?? ServiceLifetime::SINGLETON) === ServiceLifetime::SINGLETON) {
                    $this->assertSingletonClosure(
                        (string) $id,
                        $canonical,
                        $depsOf,
                        $aliases,
                        $lifetimeOf,
                        [],
                    );
                }
                if ($maxCrossModuleRefs > 0) {
                    $from = $moduleOf[$id] ?? null;
                    $to = $moduleOf[$canonical] ?? null;
                    if ($from !== null && $to !== null && $from !== $to) {
                        $key = $from . '->' . $to;
                        $edgeKey = $key . '|' . $canonical;
                        if (isset($edgeSeen[$edgeKey])) {
                            continue;
                        }
                        $edgeSeen[$edgeKey] = true;
                        $counts[$key] = ($counts[$key] ?? 0) + 1;
                        if ($counts[$key] > $maxCrossModuleRefs) {
                            throw new ModuleDependencyViolationException("Module '{$from}' exceeds cross-module reference limit ({$maxCrossModuleRefs}) towards '{$to}'.");
                        }
                    }
                }
            }
        }
        $state = [];
        $stack = [];
        foreach (array_keys($factories) as $id) {
            if (($state[$id] ?? 0) === 0) {
                $this->dfs($id, $depsOf, $aliases, $state, $stack);
            }
        }
        foreach (array_keys($aliases) as $alias) {
            $canonical = $this->resolveAlias($alias, $aliases);
            if (!isset($factories[$canonical])) {
                throw new ServiceNotFoundException((string) $alias, $moduleOf[$alias] ?? null);
            }
        }
    }

    public function resolveAlias(string $id, array $aliases): string
    {
        $seen = [];
        $current = $id;
        while (isset($aliases[$current])) {
            if (isset($seen[$current])) {
                $chain = array_keys($seen);
                $chain[] = $current;

                throw new CircularAliasException($chain);
            }
            $seen[$current] = true;
            $next = $aliases[$current];
            if (!is_string($next) || $next === '') {
                throw new InvalidConfigurationException("Alias '{$current}' must target a non-empty service ID.");
            }
            $current = $next;
        }

        return $current;
    }

    private function assertSingletonClosure(
        string $owner,
        string $current,
        array $depsOf,
        array $aliases,
        array $lifetimeOf,
        array $seen,
    ): void {
        if (isset($seen[$current])) {
            return;
        }
        $seen[$current] = true;
        $life = (string) ($lifetimeOf[$current] ?? ServiceLifetime::SINGLETON);
        if ($life !== ServiceLifetime::SINGLETON) {
            throw new InvalidConfigurationException("Singleton service '{$owner}' transitively depends on {$life} service '{$current}'.");
        }
        foreach ($depsOf[$current] ?? [] as $dep) {
            $canonical = $this->resolveAlias($dep, $aliases);
            $this->assertSingletonClosure($owner, $canonical, $depsOf, $aliases, $lifetimeOf, $seen);
        }
    }

    private function dfs(
        string $id,
        array $depsOf,
        array $aliases,
        array &$state,
        array &$stack,
    ): void {
        $state[$id] = 1;
        $stack[] = $id;
        foreach ($depsOf[$id] ?? [] as $dep) {
            $canonical = $this->resolveAlias($dep, $aliases);
            if (($state[$canonical] ?? 0) === 1) {
                // state === 1 implies the node is currently on $stack.
                $pos = (int) array_search($canonical, $stack, true);
                $cycle = array_slice($stack, $pos);
                $cycle[] = $canonical;

                throw new ServiceCircularDependencyException($cycle);
            }
            if (($state[$canonical] ?? 0) === 0) {
                $this->dfs($canonical, $depsOf, $aliases, $state, $stack);
            }
        }
        array_pop($stack);
        $state[$id] = 2;
    }
}
