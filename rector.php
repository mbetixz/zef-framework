<?php

/**
 * ZEF Framework — Rector configuration (composer rector / rector:check).
 *
 * v2.13.0 hardening: beyond the PHP 8.4 language sets, the aggressive
 * quality sets are enabled (CODE_QUALITY, DEAD_CODE, TYPE_DECLARATION,
 * EARLY_RETURN) so the baseline cannot drift away from modern, typed,
 * dead-free code.
 *
 * src/Compat is skipped on purpose: those PSR shims are byte-stable
 * artifacts of the v2.7.0 extraction and must not be rewritten.
 */

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\SetList;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/modules',
        __DIR__ . '/plugins',
        __DIR__ . '/tests',
    ])
    ->withSets([
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::TYPE_DECLARATION,
        SetList::EARLY_RETURN,
    ])
    ->withSkip([
        __DIR__ . '/src/Compat',
        // `readonly class` would harden extensible framework classes
        // (Container, ServiceDefinition, suites) and break post-construction
        // freezing patterns; deliberately out of scope for this codebase.
        \Rector\Php82\Rector\Class_\ReadOnlyClassRector::class,
        // RequestFactory::normalizeUploads(): the recursive $build closure
        // receives $size as an ARRAY in tree nodes but a SCALAR at the
        // leaves (leaf branch does `(int) $size`). The untyped parameter is
        // intentional; this rule's array inference is provably wrong there.
        \Rector\TypeDeclaration\Rector\ClassMethod\StrictArrayParamDimFetchRector::class => [
            __DIR__ . '/src/Adapters/Http/RequestFactory.php',
        ],
        // Application::runtimeAfterRequest() is an intentional no-op
        // lifecycle hook required by RoadRunnerRuntime's per-request
        // finally block; "empty" does not mean "dead" here.
        \Rector\DeadCode\Rector\ClassMethod\RemoveEmptyClassMethodRector::class => [
            __DIR__ . '/src/Adapters/Kernel/Application.php',
        ],
        // withHeader() helpers in legacy test suites keep the
        // assert($result instanceof ServerRequest) narrowing because the PSR
        // interface return type alone fails PHPStan; collapsing the temp
        // variable (as this rule wants) re-breaks static analysis.
        \Rector\CodeQuality\Rector\FunctionLike\SimplifyUselessVariableRector::class => [
            __DIR__ . '/tests/Unit/MutationDeepRuntimeTest.php',
            __DIR__ . '/tests/Unit/MutationDeepSecurityTest.php',
        ],
        // assert() narrowing again: RemoveDeadInstanceOfAssertRector deletes
        // the assert() that PHPStan relies on to narrow the PSR
        // MessageInterface return type back to ServerRequest.
        \Rector\DeadCode\Rector\StmtsAwareInterface\RemoveDeadInstanceOfAssertRector::class => [
            __DIR__ . '/tests/Unit/MutationDeepRuntimeTest.php',
            __DIR__ . '/tests/Unit/MutationDeepSecurityTest.php',
        ],
    ])
    ->withPhpVersion(PhpVersion::PHP_84)
    ->withPhpSets(php84: true)
    ->withoutParallel();
