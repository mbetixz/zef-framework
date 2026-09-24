<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.20.0 — OpenAPI SchemaGenerator: PHP types, DTO
 * attributes, enums, SchemaDefinitionInterface, validator bridge, and
 * circular-reference handling.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\OpenApi\Attribute\Property as PropertyAttr;
use Zef\Framework\OpenApi\Attribute\Schema as SchemaAttr;
use Zef\Framework\OpenApi\Schema;
use Zef\Framework\OpenApi\SchemaDefinitionException;
use Zef\Framework\OpenApi\SchemaDefinitionInterface;
use Zef\Framework\OpenApi\SchemaGenerator;
use Zef\Framework\OpenApi\SchemaType;
use Zef\Framework\Validation\Validator;

#[SchemaAttr(name: 'UserDoc', description: 'A user DTO')]
final class OpenApiGeneratorUserDto
{
    #[PropertyAttr(description: 'Identifier', readOnly: true)]
    public int $id;

    #[PropertyAttr(minLength: 1, maxLength: 255)]
    public string $name;

    #[PropertyAttr(format: 'email')]
    public string $email;

    #[PropertyAttr(nullable: true)]
    public ?string $nickname = null;

    #[PropertyAttr(required: true)]
    public ?string $alwaysRequired = null;

    public ?string $optionalWithDefault = 'anon';

    /** @var null|list<string> tag list */
    public ?array $tags = null;
}

enum OpenApiGeneratorStatusEnum: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Pending = 'pending';
}

enum OpenApiGeneratorLevelEnum: int
{
    case Low = 1;
    case High = 5;
}

enum OpenApiGeneratorUnitEnum
{
    case On;
    case Off;
}

final class OpenApiGeneratorPlainDto implements SchemaDefinitionInterface
{
    public static function openApiSchemaName(): string
    {
        return 'Plain';
    }

    public static function openApiSchema(): Schema
    {
        return new Schema(type: SchemaType::String, title: 'Plain');
    }
}

final class OpenApiGeneratorNodeDto
{
    public string $label;

    public ?OpenApiGeneratorLeafDto $leaf = null;
}

final class OpenApiGeneratorLeafDto
{
    public string $name;

    public ?OpenApiGeneratorNodeDto $parent = null;
}

final class OpenApiGeneratorUnionDto
{
    public int|string $flexible;

    public ?int $maybeInt = null;
}

/**
 * @internal
 */
final class OpenApiSchemaGeneratorTest extends TestCase
{
    private SchemaGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new SchemaGenerator();
    }

    public function testGeneratesSchemaFromAttributedDto(): void
    {
        $schema = $this->generator->generateFromClass(OpenApiGeneratorUserDto::class);
        self::assertSame(SchemaType::Object, $schema->type);
        self::assertSame('UserDoc', $schema->title);
        self::assertSame('A user DTO', $schema->description);
        $properties = $schema->properties;
        self::assertSame(SchemaType::Integer, $properties['id']->type);
        self::assertTrue($properties['id']->readOnly);
        self::assertSame(255, $properties['name']->maxLength);
        self::assertSame('email', $properties['email']->format);
    }

    public function testRequiredDerivation(): void
    {
        $schema = $this->generator->generateFromClass(OpenApiGeneratorUserDto::class);
        $required = $schema->required;
        self::assertContains('id', $required);
        self::assertContains('name', $required);
        self::assertContains('alwaysRequired', $required); // forced via attribute
        self::assertNotContains('nickname', $required); // nullable native type
        self::assertNotContains('optionalWithDefault', $required); // default value
    }

    public function testStringBackedEnumSchema(): void
    {
        $schema = $this->generator->generateFromClass(OpenApiGeneratorStatusEnum::class);
        self::assertSame(SchemaType::String, $schema->type);
        self::assertSame(['active', 'inactive', 'pending'], $schema->enum);
        self::assertSame('OpenApiGeneratorStatusEnum', $schema->title);
    }

    public function testIntBackedEnumSchema(): void
    {
        $schema = $this->generator->generateFromClass(OpenApiGeneratorLevelEnum::class);
        self::assertSame(SchemaType::Integer, $schema->type);
        self::assertSame([1, 5], $schema->enum);
    }

    public function testUnitEnumUsesCaseNames(): void
    {
        $schema = $this->generator->generateFromClass(OpenApiGeneratorUnitEnum::class);
        self::assertSame(['On', 'Off'], $schema->enum);
    }

    public function testSchemaDefinitionInterfaceWins(): void
    {
        $schema = $this->generator->generateFromClass(OpenApiGeneratorPlainDto::class);
        self::assertSame(SchemaType::String, $schema->type);
        self::assertSame('Plain', $schema->title);
        self::assertSame('Plain', $this->generator->schemaNameFor(OpenApiGeneratorPlainDto::class));
    }

    public function testUnknownClassThrows(): void
    {
        $this->expectException(SchemaDefinitionException::class);
        $this->generator->generateFromClass('Zef\Test\Unit\NoSuchClassGhp');
    }

    public function testClassTypePropertyBecomesReference(): void
    {
        $schema = $this->generator->generateFromClass(OpenApiGeneratorNodeDto::class);
        $leaf = $schema->properties['leaf'];
        self::assertSame('#/components/schemas/OpenApiGeneratorLeafDto', $leaf->ref);
    }

    public function testCircularReferenceTerminates(): void
    {
        $schema = $this->generator->generateFromClass(OpenApiGeneratorNodeDto::class);
        $leaf = $this->generator->generateFromClass(OpenApiGeneratorLeafDto::class);
        self::assertSame('#/components/schemas/OpenApiGeneratorNodeDto', $leaf->properties['parent']->ref);
        self::assertSame('#/components/schemas/OpenApiGeneratorLeafDto', $schema->properties['leaf']->ref);
        // both classes registered exactly once
        $registered = $this->generator->registeredSchemas();
        self::assertArrayHasKey('OpenApiGeneratorNodeDto', $registered);
        self::assertArrayHasKey('OpenApiGeneratorLeafDto', $registered);
    }

    public function testSelfReferenceBecomesRef(): void
    {
        $schema = $this->generator->generateFromClass(OpenApiGeneratorNodeDto::class);
        self::assertNotNull($schema->properties['leaf']);
        $registered = $this->generator->registeredSchemas();
        self::assertCount(2, $registered);
    }

    public function testUnionTypes(): void
    {
        $schema = $this->generator->generateFromClass(OpenApiGeneratorUnionDto::class);
        self::assertNotNull($schema->properties['flexible']->oneOf);
        self::assertCount(2, $schema->properties['flexible']->oneOf);
        self::assertTrue($schema->properties['maybeInt']->nullable);
        self::assertSame(SchemaType::Integer, $schema->properties['maybeInt']->type);
    }

    public function testNamedTypeMapping(): void
    {
        $property = new \ReflectionProperty(OpenApiGeneratorUserDto::class, 'id');
        $idType = $property->getType();
        self::assertNotNull($idType);
        $schema = $this->generator->generateFromType($idType);
        self::assertSame(SchemaType::Integer, $schema->type);

        $tags = new \ReflectionProperty(OpenApiGeneratorUserDto::class, 'tags');
        $tagsType = $tags->getType();
        self::assertNotNull($tagsType);
        $tagSchema = $this->generator->generateFromType($tagsType);
        self::assertTrue($tagSchema->nullable);
        self::assertSame(SchemaType::Array, $tagSchema->type);
    }

    public function testArrayItemsAreUnconstrainedMaps(): void
    {
        $schema = new Schema(type: SchemaType::Array, items: new Schema(type: SchemaType::Object, additionalPropertiesAllowed: true));
        self::assertSame(['type' => 'object', 'additionalProperties' => true], $schema->toArray()['items'] ?? null);
    }

    public function testFromValidatorBuildsObjectSchema(): void
    {
        $validator = new Validator();
        $validator->field('email')->required()->email()->maxLength(254);
        $validator->field('age')->typeInt()->min(18)->max(120);
        $validator->field('role')->in(['admin', 'viewer'])->nullable();
        $validator->field('code')->pattern('/^[A-Z]{3}$/');

        $schema = $this->generator->generateFromValidator($validator);
        self::assertSame(SchemaType::Object, $schema->type);
        self::assertSame(['email'], $schema->required);
        $properties = $schema->properties;
        self::assertSame('email', $properties['email']->format);
        self::assertSame(254, $properties['email']->maxLength);
        self::assertSame(SchemaType::Integer, $properties['age']->type);
        self::assertSame(18, $properties['age']->minimum);
        self::assertSame(120, $properties['age']->maximum);
        self::assertSame(['admin', 'viewer'], $properties['role']->enum);
        self::assertTrue($properties['role']->nullable);
        self::assertSame('^[A-Z]{3}$', $properties['code']->pattern);
    }

    public function testValidatorNumericBoundsKeepIntegers(): void
    {
        $validator = new Validator();
        $validator->field('price')->typeNumeric()->min(5)->max(9);
        $schema = $this->generator->generateFromValidator($validator);
        self::assertSame(SchemaType::Number, $schema->properties['price']->type);
        self::assertSame(5, $schema->properties['price']->minimum);
        self::assertSame(9, $schema->properties['price']->maximum);
    }

    public function testUuidFormatExtraction(): void
    {
        $validator = new Validator();
        $validator->field('id')->required()->uuid();
        $schema = $this->generator->generateFromValidator($validator);
        self::assertSame('uuid', $schema->properties['id']->format);
    }

    public function testCacheReturnsSameInstance(): void
    {
        $first = $this->generator->generateFromClass(OpenApiGeneratorUserDto::class);
        $second = $this->generator->generateFromClass(OpenApiGeneratorUserDto::class);
        self::assertSame($first, $second);
    }

    public function testClassTypedPropertyAlwaysReferencesComponent(): void
    {
        $schema = $this->generator->generateFromClass(OpenApiGeneratorNodeDto::class);
        self::assertSame('#/components/schemas/OpenApiGeneratorLeafDto', $schema->properties['leaf']->ref);
        self::assertArrayHasKey('OpenApiGeneratorLeafDto', $this->generator->registeredSchemas());
    }

    public function testAsNullablePreservesConstraints(): void
    {
        $schema = new Schema(type: SchemaType::String, minLength: 2);
        $nullable = $schema->asNullable();
        self::assertNotSame($schema, $nullable);
        self::assertTrue($nullable->nullable);
        self::assertSame(2, $nullable->minLength);
        self::assertNull($schema->nullable);
        self::assertSame($nullable, $nullable->asNullable()); // idempotent
    }

    public function testRegisteredSchemasAreSorted(): void
    {
        $this->generator->generateFromClass(OpenApiGeneratorNodeDto::class);
        $names = array_keys($this->generator->registeredSchemas());
        $sorted = $names;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $names);
    }
}
