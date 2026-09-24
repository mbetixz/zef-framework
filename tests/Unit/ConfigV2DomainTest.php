<?php

declare(strict_types=1);

// ZEF Framework v2.21.0 — Configuration System v2 (Domain): value-type grammar,
// schema keys, dotted paths, collect-all validation and the typed bag.

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Config\Config;
use Zef\Framework\Config\ConfigKey;
use Zef\Framework\Config\ConfigSchema;
use Zef\Framework\Config\ConfigSchemaValidator;
use Zef\Framework\Config\ConfigValidationException;
use Zef\Framework\Config\ConfigValueType;
use Zef\Framework\Config\ConfigViolation;
use Zef\Framework\Config\DottedPaths;
use Zef\Framework\Exception\InvalidConfigurationException;

/**
 * @internal
 */
final class ConfigV2DomainTest extends TestCase
{
    // ---- ConfigValueType::accepts -------------------------------------------

    public function testStringTypeAcceptsStringsOnly(): void
    {
        $type = ConfigValueType::String;
        self::assertTrue($type->accepts('hello'));
        self::assertTrue($type->accepts(''));
        self::assertFalse($type->accepts(5));
        self::assertFalse($type->accepts(true));
        self::assertFalse($type->accepts(null));
        self::assertFalse($type->accepts(['a']));
    }

    public function testIntTypeGrammarIsFullNumericString(): void
    {
        $type = ConfigValueType::Int;
        self::assertTrue($type->accepts(5));
        self::assertTrue($type->accepts(0));
        self::assertTrue($type->accepts(-12));
        self::assertTrue($type->accepts('5'));
        self::assertTrue($type->accepts('-12'));
        self::assertTrue($type->accepts('007'));
        // Rejects: decimals, partial numbers, exponent forms, whitespace,
        // empty strings, floats and other scalars.
        self::assertFalse($type->accepts('5.5'));
        self::assertFalse($type->accepts('12abc'));
        self::assertFalse($type->accepts('1e3'));
        self::assertFalse($type->accepts(' 5'));
        self::assertFalse($type->accepts(''));
        self::assertFalse($type->accepts(5.0));
        self::assertFalse($type->accepts(true));
        self::assertFalse($type->accepts(null));
    }

    public function testFloatTypeGrammarIsFullDecimalString(): void
    {
        $type = ConfigValueType::Float;
        self::assertTrue($type->accepts(1.5));
        self::assertTrue($type->accepts(5));
        self::assertTrue($type->accepts(-0.5));
        self::assertTrue($type->accepts('3.14'));
        self::assertTrue($type->accepts('-2.75'));
        self::assertTrue($type->accepts('5'));
        self::assertFalse($type->accepts('1.2.3'));
        self::assertFalse($type->accepts('1e3'));
        self::assertFalse($type->accepts('abc'));
        self::assertFalse($type->accepts(''));
        self::assertFalse($type->accepts(true));
        self::assertFalse($type->accepts(null));
    }

    public function testBoolTypeGrammarIsCanonicalWords(): void
    {
        $type = ConfigValueType::Bool;
        self::assertTrue($type->accepts(true));
        self::assertTrue($type->accepts(false));
        foreach (ConfigValueType::BOOL_TRUE as $word) {
            self::assertTrue($type->accepts($word), $word);
            self::assertTrue($type->accepts(strtoupper($word)), $word);
        }
        foreach (ConfigValueType::BOOL_FALSE as $word) {
            self::assertTrue($type->accepts($word), $word);
        }
        self::assertFalse($type->accepts('yeah'));
        self::assertFalse($type->accepts('2'));
        self::assertFalse($type->accepts(''));
        self::assertFalse($type->accepts(1));
        self::assertFalse($type->accepts(null));
    }

    public function testEnumTypeGrammarChecksBackingValues(): void
    {
        $type = ConfigValueType::Enum;
        self::assertTrue($type->accepts('read', ConfigV2Mode::class));
        self::assertTrue($type->accepts('write', ConfigV2Mode::class));
        self::assertFalse($type->accepts('erase', ConfigV2Mode::class));
        self::assertFalse($type->accepts(1, ConfigV2Mode::class));
        self::assertTrue($type->accepts(1, ConfigV2Level::class));
        self::assertTrue($type->accepts(9, ConfigV2Level::class));
        // String backing of an int-backed enum is NOT accepted.
        self::assertFalse($type->accepts('1', ConfigV2Level::class));
        self::assertFalse($type->accepts(null, ConfigV2Level::class));
        // Non-enum classes and missing enum classes are rejected outright.
        self::assertFalse($type->accepts('read', \DateTimeImmutable::class));
        self::assertFalse($type->accepts('read'));
    }

    public function testArrayTypeAcceptsArraysOnly(): void
    {
        $type = ConfigValueType::Array;
        self::assertTrue($type->accepts([]));
        self::assertTrue($type->accepts(['a' => 1]));
        self::assertTrue($type->accepts([1, 2]));
        self::assertFalse($type->accepts('[]'));
        self::assertFalse($type->accepts(null));
    }

    public function testCoerceCanonicalisesValues(): void
    {
        self::assertSame(5, ConfigValueType::Int->coerce('5'));
        self::assertSame(-12, ConfigValueType::Int->coerce(-12));
        self::assertSame(3.14, ConfigValueType::Float->coerce('3.14'));
        self::assertSame(5.0, ConfigValueType::Float->coerce(5));
        self::assertTrue(ConfigValueType::Bool->coerce('ON'));
        self::assertTrue(ConfigValueType::Bool->coerce('yes'));
        self::assertFalse(ConfigValueType::Bool->coerce('0'));
        self::assertFalse(ConfigValueType::Bool->coerce('OFF'));
        self::assertSame('keep', ConfigValueType::String->coerce('keep'));
        self::assertSame(['a' => 1], ConfigValueType::Array->coerce(['a' => 1]));
        self::assertSame(ConfigV2Mode::Read, ConfigValueType::Enum->coerce('read', ConfigV2Mode::class));
        self::assertSame(ConfigV2Level::High, ConfigValueType::Enum->coerce(9, ConfigV2Level::class));
    }

    public function testDescribeRendersDeterministicShapes(): void
    {
        self::assertSame('null', ConfigValueType::describe(null));
        self::assertSame('bool(true)', ConfigValueType::describe(true));
        self::assertSame('bool(false)', ConfigValueType::describe(false));
        self::assertSame('int(5)', ConfigValueType::describe(5));
        self::assertSame('float(1.5)', ConfigValueType::describe(1.5));
        self::assertSame("string('abc')", ConfigValueType::describe('abc'));
        self::assertSame("string('a\\'b')", ConfigValueType::describe("a'b"));
        self::assertSame('array(2)', ConfigValueType::describe([1, 2]));
        self::assertSame('array(0)', ConfigValueType::describe([]));
        self::assertSame('object(stdClass)', ConfigValueType::describe(new \stdClass()));
    }

    public function testDescribeTruncatesLongStrings(): void
    {
        $long = str_repeat('x', 70);
        $expected = "string('" . str_repeat('x', 61) . "...')";
        self::assertSame($expected, ConfigValueType::describe($long));
        $exact64 = str_repeat('y', 64);
        self::assertSame("string('" . $exact64 . "')", ConfigValueType::describe($exact64));
    }

    // ---- ConfigKey -----------------------------------------------------------

    public function testKeyGrammarRejectsMalformedKeys(): void
    {
        foreach (['', '.a', 'a.', 'a..b', 'a b', '-a', '.a.b', 'a.', 'a.b.'] as $bad) {
            try {
                new ConfigKey($bad, ConfigValueType::String);
                self::fail("Key '{$bad}' should be invalid.");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('Invalid configuration key', $e->getMessage());
            }
        }
        new ConfigKey('a', ConfigValueType::String);
        new ConfigKey('a-b_c9', ConfigValueType::String);
        new ConfigKey('database.pool.max_size', ConfigValueType::Int);
        new ConfigKey(str_repeat('a', 256), ConfigValueType::String);
    }

    public function testKeyLongerThan256IsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid configuration key');
        new ConfigKey(str_repeat('a', 257), ConfigValueType::String);
    }

    public function testEnumKeysRequireBackedEnumClass(): void
    {
        try {
            new ConfigKey('app.mode', ConfigValueType::Enum);
            self::fail('Missing enum class must fail.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('requires a backed enum class', $e->getMessage());
        }

        try {
            new ConfigKey('app.mode', ConfigValueType::Enum, enumClass: \DateTimeImmutable::class);
            self::fail('Non-enum class must fail.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('requires a backed enum class', $e->getMessage());
        }

        try {
            new ConfigKey('app.mode', ConfigValueType::Enum, enumClass: ConfigV2Unit::class);
            self::fail('Unit enum (non-backed) must fail.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('requires a backed enum class', $e->getMessage());
        }
        new ConfigKey('app.mode', ConfigValueType::Enum, enumClass: ConfigV2Mode::class);
    }

    public function testEnumClassOnlyAppliesToEnumType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be of type enum');
        new ConfigKey('app.mode', ConfigValueType::String, enumClass: ConfigV2Mode::class);
    }

    public function testMinMaxOnlyAppliesToNumericTypes(): void
    {
        foreach ([ConfigValueType::String, ConfigValueType::Bool, ConfigValueType::Enum, ConfigValueType::Array] as $type) {
            $enumClass = $type === ConfigValueType::Enum ? ConfigV2Mode::class : null;

            try {
                new ConfigKey('k', $type, enumClass: $enumClass, min: 1);
                self::fail("min must be rejected for {$type->value}.");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('min/max constraints', $e->getMessage());
            }
        }
        new ConfigKey('k', ConfigValueType::Int, min: 1);
        new ConfigKey('k', ConfigValueType::Float, max: 2.5);
    }

    public function testMinGreaterThanMaxIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('min greater than max');
        new ConfigKey('k', ConfigValueType::Int, min: 10, max: 5);
    }

    public function testPatternOnlyAppliesToStringTypeAndMustCompile(): void
    {
        try {
            new ConfigKey('k', ConfigValueType::Int, pattern: '#[a-z]+#');
            self::fail('Pattern must be rejected for int.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('cannot declare a pattern', $e->getMessage());
        }

        try {
            new ConfigKey('k', ConfigValueType::String, pattern: 'no-delimiters');
            self::fail('Non-compiling pattern must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('does not compile', $e->getMessage());
        }
        new ConfigKey('k', ConfigValueType::String, pattern: '#^[a-z]+$#');
    }

    public function testRequiredKeysCannotDeclareDefaults(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('required and cannot declare a default');
        new ConfigKey('k', ConfigValueType::String, required: true, default: 'x');
    }

    public function testDefaultsMustMatchDeclaredTypeAndConstraints(): void
    {
        try {
            new ConfigKey('k', ConfigValueType::Int, default: 'abc');
            self::fail('Non-numeric default must fail.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('does not match its type', $e->getMessage());
        }

        try {
            new ConfigKey('k', ConfigValueType::Int, default: 3, min: 5);
            self::fail('Default below min must fail.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('outside its declared constraints', $e->getMessage());
        }

        try {
            new ConfigKey('k', ConfigValueType::Int, default: 11, max: 10);
            self::fail('Default above max must fail.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('outside its declared constraints', $e->getMessage());
        }

        try {
            new ConfigKey('k', ConfigValueType::String, default: 'ABC', pattern: '#^[a-z]+$#');
            self::fail('Default violating pattern must fail.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('outside its declared constraints', $e->getMessage());
        }
        new ConfigKey('k', ConfigValueType::Int, default: '42');
        new ConfigKey('k', ConfigValueType::Int, default: 5, min: 1);
        new ConfigKey('k', ConfigValueType::Enum, default: 'read', enumClass: ConfigV2Mode::class);
        new ConfigKey('k', ConfigValueType::String, required: true);
        new ConfigKey('k', ConfigValueType::String);
    }

    // ---- ConfigSchema --------------------------------------------------------

    public function testSchemaRejectsDuplicateKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Duplicate schema key 'a'");
        new ConfigSchema([
            new ConfigKey('a', ConfigValueType::String),
            new ConfigKey('a', ConfigValueType::Int),
        ]);
    }

    public function testSchemaLookup(): void
    {
        $schema = ConfigSchema::of(
            new ConfigKey('a.b', ConfigValueType::String),
            new ConfigKey('c', ConfigValueType::Int),
        );
        self::assertSame('a.b', $schema->key('a.b')?->key);
        self::assertNull($schema->key('missing'));
        self::assertCount(2, $schema->keys);
        self::assertTrue($schema->allowUnknownKeys);
        self::assertFalse(new ConfigSchema($schema->keys, false)->allowUnknownKeys);
    }

    // ---- DottedPaths ---------------------------------------------------------

    public function testDottedPathHasAndValueAt(): void
    {
        $tree = ['a' => ['b' => ['c' => 1]], 'd' => 'x'];
        self::assertTrue(DottedPaths::has($tree, 'a.b.c'));
        self::assertTrue(DottedPaths::has($tree, 'a'));
        self::assertTrue(DottedPaths::has($tree, 'd'));
        self::assertFalse(DottedPaths::has($tree, 'a.b.x'));
        self::assertFalse(DottedPaths::has($tree, 'x.y'));
        self::assertFalse(DottedPaths::has($tree, 'a.b.c.d'));
        self::assertSame(1, DottedPaths::valueAt($tree, 'a.b.c'));
        self::assertSame('x', DottedPaths::valueAt($tree, 'd'));
        $this->expectException(\LogicException::class);
        DottedPaths::valueAt($tree, 'a.b.x');
    }

    public function testDottedPathSetCreatesAndReplaces(): void
    {
        self::assertSame(['a' => ['b' => 1]], DottedPaths::set([], 'a.b', 1));
        $tree = DottedPaths::set(['a' => ['b' => 1]], 'a.c', 2);
        $branch = $tree['a'];
        self::assertIsArray($branch);
        self::assertSame(['b' => 1, 'c' => 2], $branch);
        // A scalar standing in the path is replaced by the nested value.
        $replaced = DottedPaths::set(['a' => 'scalar'], 'a.b', 1);
        self::assertSame(['a' => ['b' => 1]], $replaced);
        self::assertSame(['a' => ['b' => ['c' => 3]]], DottedPaths::set([], 'a.b.c', 3));
    }

    public function testLeafPathsAreSortedAndCountEmptyArraysAsLeaves(): void
    {
        $tree = [
            'b' => ['z' => 1, 'a' => 2],
            'a' => 'leaf',
            'c' => [],
            'd' => ['e' => ['g' => 3, 'f' => 4]],
            'list' => [10, 20],
        ];
        self::assertSame(
            ['a', 'b.a', 'b.z', 'c', 'd.e.f', 'd.e.g', 'list.0', 'list.1'],
            DottedPaths::leafPaths($tree),
        );
    }

    // ---- ConfigSchemaValidator -----------------------------------------------

    public function testValidatorReportsMissingRequiredOnly(): void
    {
        $validator = new ConfigSchemaValidator();
        $schema = ConfigSchema::of(
            new ConfigKey('need', ConfigValueType::String, required: true),
            new ConfigKey('opt', ConfigValueType::Int, default: 7),
        );
        $missingRequired = $validator->validate(['opt' => 1], $schema);
        self::assertCount(1, $missingRequired);
        self::assertSame('need', $missingRequired[0]->key);
        self::assertSame('is required', $missingRequired[0]->message);
        $violations = $validator->validate([], $schema);
        self::assertCount(1, $violations);
        self::assertSame('need', $violations[0]->key);
        self::assertSame('is required', $violations[0]->message);
    }

    public function testValidatorTypeMismatchMessage(): void
    {
        $validator = new ConfigSchemaValidator();
        $schema = ConfigSchema::of(new ConfigKey('port', ConfigValueType::Int));
        $violations = $validator->validate(['port' => 'abc'], $schema);
        self::assertCount(1, $violations);
        self::assertSame('port', $violations[0]->key);
        self::assertSame("expects int, got string('abc')", $violations[0]->message);
        self::assertSame('port: expects int, got string(\'abc\')', (string) $violations[0]);
    }

    public function testValidatorEnforcesBoundsAndPattern(): void
    {
        $validator = new ConfigSchemaValidator();
        $schema = ConfigSchema::of(
            new ConfigKey('port', ConfigValueType::Int, min: 1, max: 65535),
            new ConfigKey('name', ConfigValueType::String, pattern: '#^[a-z]+$#'),
            new ConfigKey('ratio', ConfigValueType::Float, min: 0.5, max: 1.5),
        );
        $violations = $validator->validate(['port' => 0, 'name' => 'ABC', 'ratio' => 2.0], $schema);
        self::assertCount(3, $violations);
        self::assertSame('port', $violations[0]->key);
        self::assertSame('must be >= 1', $violations[0]->message);
        self::assertSame('name', $violations[1]->key);
        self::assertSame('does not match pattern', $violations[1]->message);
        self::assertSame('ratio', $violations[2]->key);
        self::assertSame('must be <= 1.5', $violations[2]->message);
        self::assertSame([], $validator->validate(['port' => 5432, 'name' => 'ok', 'ratio' => 0.75], $schema));
    }

    public function testValidatorEnforcesEnumMembership(): void
    {
        $validator = new ConfigSchemaValidator();
        $schema = ConfigSchema::of(new ConfigKey('mode', ConfigValueType::Enum, enumClass: ConfigV2Mode::class));
        self::assertSame([], $validator->validate(['mode' => 'read'], $schema));
        $violations = $validator->validate(['mode' => 'erase'], $schema);
        self::assertCount(1, $violations);
        self::assertSame("expects enum, got string('erase')", $violations[0]->message);
    }

    public function testValidatorCollectsAllViolationsInDeclaredOrder(): void
    {
        $validator = new ConfigSchemaValidator();
        $schema = ConfigSchema::of(
            new ConfigKey('alpha', ConfigValueType::Int),
            new ConfigKey('charlie', ConfigValueType::Int),
            new ConfigKey('bravo', ConfigValueType::String, required: true),
        );
        $violations = $validator->validate(['alpha' => 'x', 'charlie' => 'y'], $schema);
        self::assertCount(3, $violations);
        self::assertSame(['alpha', 'charlie', 'bravo'], array_map(
            static fn (ConfigViolation $v): string => $v->key,
            $violations,
        ));
    }

    public function testValidatorStrictUnknownKeys(): void
    {
        $validator = new ConfigSchemaValidator();
        $schema = new ConfigSchema([new ConfigKey('db.host', ConfigValueType::String)], false);
        self::assertSame([], $validator->validate(['db' => ['host' => 'x']], $schema));
        $violations = $validator->validate(['db' => ['host' => 'x', 'typo' => 1], 'stale' => []], $schema);
        self::assertCount(2, $violations);
        self::assertSame('db.typo', $violations[0]->key);
        self::assertSame('unknown configuration key', $violations[0]->message);
        self::assertSame('stale', $violations[1]->key);
        // Unknown nested under a declared-but-array value is still reported.
        $violations = $validator->validate(['db' => ['host' => 'x', 'deep' => ['nope' => 1]]], $schema);
        self::assertCount(1, $violations);
        self::assertSame('db.deep.nope', $violations[0]->key);
    }

    public function testValidatorAppliesDefaultsOnlyForMissingKeys(): void
    {
        $validator = new ConfigSchemaValidator();
        $schema = ConfigSchema::of(
            new ConfigKey('a', ConfigValueType::Int, default: 1),
            new ConfigKey('b.c', ConfigValueType::String, default: 'x'),
            new ConfigKey('d', ConfigValueType::String),
        );
        $values = $validator->applyDefaults(['d' => 'raw', 'a' => 99], $schema);
        self::assertSame(99, $values['a']);
        $b = $values['b'];
        self::assertIsArray($b);
        self::assertSame('x', $b['c']);
        self::assertSame('raw', $values['d']);
        self::assertSame([], $validator->validate($values, $schema));
    }

    // ---- Config bag ----------------------------------------------------------

    public function testConfigTypedAccessorsCoerceCanonicalStrings(): void
    {
        $config = new Config([
            'str' => 'hello',
            'int' => '5432',
            'int2' => -7,
            'float' => '2.5',
            'float2' => 1,
            'bool' => 'on',
            'bool2' => false,
            'list' => [1, 2],
            'mode' => 'write',
        ]);
        self::assertSame('hello', $config->string('str'));
        self::assertSame(5432, $config->int('int'));
        self::assertSame(-7, $config->int('int2'));
        self::assertSame(2.5, $config->float('float'));
        self::assertSame(1.0, $config->float('float2'));
        self::assertTrue($config->bool('bool'));
        self::assertFalse($config->bool('bool2'));
        self::assertSame([1, 2], $config->array('list'));
        self::assertSame(ConfigV2Mode::Write, $config->enum('mode', ConfigV2Mode::class));
    }

    public function testConfigMissingKeyThrowsConfiguredMessage(): void
    {
        $config = new Config(['a' => 1]);
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage("Configuration key 'missing.key' is not configured.");
        $config->string('missing.key');
    }

    public function testConfigWrongTypeThrowsSameMessageAsValidator(): void
    {
        $config = new Config(['port' => 'abc', 'flag' => 1]);

        try {
            $config->int('port');
            self::fail('Mistyped value must fail.');
        } catch (InvalidConfigurationException $e) {
            self::assertSame("Configuration key 'port' expects int, got string('abc')", $e->getMessage());
        }

        try {
            $config->string('flag');
            self::fail('Int value must fail string accessor.');
        } catch (InvalidConfigurationException $e) {
            self::assertSame("Configuration key 'flag' expects string, got int(1)", $e->getMessage());
        }
    }

    public function testConfigEnumAccessorGuardsAndResolves(): void
    {
        $config = new Config(['mode' => 'read']);
        self::assertSame(ConfigV2Mode::Read, $config->enum('mode', ConfigV2Mode::class));

        try {
            // @phpstan-ignore argument.type (deliberately non-enum class)
            $config->enum('mode', \DateTimeImmutable::class);
            self::fail('Non-enum class must fail.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('requires a backed enum class', $e->getMessage());
        }
        $config2 = new Config(['mode' => 'erase']);

        try {
            $config2->enum('mode', ConfigV2Mode::class);
            self::fail('Invalid backing value must fail.');
        } catch (InvalidConfigurationException $e) {
            self::assertSame("Configuration key 'mode' expects enum, got string('erase')", $e->getMessage());
        }
    }

    public function testConfigHasGetAllKeys(): void
    {
        $config = new Config(['a' => ['b' => 1], 'c' => [], 'z' => 2]);
        self::assertTrue($config->has('a.b'));
        self::assertFalse($config->has('a.x'));
        self::assertSame(1, $config->get('a.b'));
        self::assertNull($config->get('a.x'));
        self::assertSame('def', $config->get('a.x', 'def'));
        self::assertSame(['a' => ['b' => 1], 'c' => [], 'z' => 2], $config->all());
        self::assertSame(['a.b', 'c', 'z'], $config->keys());
    }

    // ---- ConfigValidationException ------------------------------------------

    public function testValidationExceptionCarriesFullReport(): void
    {
        $violations = [
            new ConfigViolation('a', 'is required'),
            new ConfigViolation('b.c', "expects int, got string('x')"),
        ];
        $exception = new ConfigValidationException($violations);
        self::assertInstanceOf(InvalidConfigurationException::class, $exception);
        self::assertSame($violations, $exception->violations());
        self::assertSame(
            "Configuration validation failed with 2 violation(s):\n"
            . " - a: is required\n"
            . " - b.c: expects int, got string('x')",
            $exception->getMessage(),
        );
    }
}

enum ConfigV2Mode: string
{
    case Read = 'read';
    case Write = 'write';
}

enum ConfigV2Level: int
{
    case Low = 1;
    case High = 9;
}

enum ConfigV2Unit
{
    case Only;
}
