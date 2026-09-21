<?php

declare(strict_types=1);

/*
 * ZEF Framework — Coverage push #5 (v2.13.1): autowiring reflection edge
 * cases — union/intersection/untyped/variadic parameters and default-value
 * extraction, plus router and resolver corner branches.
 */

namespace Zef\Test\Unit;

use NoSuch\MissingType;
use PHPUnit\Framework\TestCase;
use Zef\Framework\Container\Autowiring\ReflectionMetadataExtractor;

/**
 * @internal
 */
final class AutowireEdgeTest extends TestCase
{
    public function testExtractorMarksUnionTypesUnsupported(): void
    {
        $spec = new ReflectionMetadataExtractor()->extract(UnionFixture::class);
        $reason = $spec->parameters[0]->unsupportedTypeReason;
        self::assertIsString($reason);
        self::assertStringContainsString('union type', $reason);
    }

    public function testExtractorMarksIntersectionTypesUnsupported(): void
    {
        $spec = new ReflectionMetadataExtractor()->extract(IntersectionFixture::class);
        $reason = $spec->parameters[0]->unsupportedTypeReason;
        self::assertIsString($reason);
        self::assertStringContainsString('intersection', $reason);
    }

    public function testExtractorTreatsUntypedParametersAsScalars(): void
    {
        $spec = new ReflectionMetadataExtractor()->extract(UntypedFixture::class);
        self::assertTrue($spec->parameters[0]->isBuiltinScalar);
        self::assertNull($spec->parameters[0]->unsupportedTypeReason);
    }

    public function testExtractorCapturesVariadicAndDefaults(): void
    {
        $spec = new ReflectionMetadataExtractor()->extract(VariadicFixture::class);
        self::assertTrue($spec->parameters[0]->isVariadic);
        self::assertFalse($spec->parameters[0]->hasDefaultValue);

        $defaults = new ReflectionMetadataExtractor()->extract(DefaultsFixture::class);
        self::assertTrue($defaults->parameters[0]->hasDefaultValue);
        self::assertSame('fallback', $defaults->parameters[0]->defaultValue);
        self::assertNotNull($defaults->parameters[1]->className);
    }

    public function testExtractorReportsNonExistentTypes(): void
    {
        $spec = new ReflectionMetadataExtractor()->extract(GhostTypeFixture::class);
        $reason = $spec->parameters[0]->unsupportedTypeReason;
        self::assertIsString($reason);
        self::assertStringContainsString('does not exist', $reason);
    }
}

/**
 * @internal
 */
final class UnionFixture
{
    public function __construct(public readonly int|string $value) {}
}

/**
 * @internal
 */
final class IntersectionFixture
{
    public function __construct(public readonly \Countable&\Iterator $both) {}
}

/**
 * @internal
 */
final class UntypedFixture
{
    // The MISSING native type is the scenario: `mixed` would show up as a
    // ReflectionNamedType and change what the extractor observes.
    // phpcs:disable SlevomatCodingStandard.TypeHints.ParameterTypeHint -- the absent type hint IS the scenario under test
    // @phpstan-ignore-next-line
    public function __construct(
        public $raw,
    ) {}
    // phpcs:enable SlevomatCodingStandard.TypeHints.ParameterTypeHint
}

/**
 * @internal
 */
final class VariadicFixture
{
    /** @var list<string> */
    public array $parts;

    public function __construct(string ...$parts)
    {
        $this->parts = array_values($parts);
    }
}

/**
 * @internal
 */
final class DefaultsFixture
{
    public function __construct(
        public readonly string $mode = 'fallback',
        public readonly \stdClass $service = new \stdClass(),
    ) {}
}

/**
 * @internal
 */
final class GhostTypeFixture
{
    // Deliberately non-existent class name: the reflection layer must
    // report it as unsupported rather than failing elsewhere.
    // @phpstan-ignore-next-line
    public function __construct(public readonly MissingType $ghost) {}
}
