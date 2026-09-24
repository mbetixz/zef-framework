<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.20.0 — Golden-output tests: full-document assertSame
 * baselines for the specification builder and the Postman exporter. These
 * pin the ENTIRE wire format, which makes them extremely effective at
 * killing serialization mutants (concat/ternary/array-item/coalesce...).
 *
 * The baselines were generated with scripts/dev/golden_openapi.php and
 * reviewed by hand against the OpenAPI 3.1 / Postman v2.1 specifications.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\OpenApi\Contact;
use Zef\Framework\OpenApi\Info;
use Zef\Framework\OpenApi\JsonSpecificationSerializer;
use Zef\Framework\OpenApi\License;
use Zef\Framework\OpenApi\MediaType;
use Zef\Framework\OpenApi\OpenApiVersion;
use Zef\Framework\OpenApi\Operation;
use Zef\Framework\OpenApi\Parameter;
use Zef\Framework\OpenApi\ParameterLocation;
use Zef\Framework\OpenApi\PostmanCollectionExporter;
use Zef\Framework\OpenApi\RequestBody;
use Zef\Framework\OpenApi\Response;
use Zef\Framework\OpenApi\Schema;
use Zef\Framework\OpenApi\SchemaType;
use Zef\Framework\OpenApi\SecurityRequirement;
use Zef\Framework\OpenApi\SecurityScheme;
use Zef\Framework\OpenApi\SecuritySchemeType;
use Zef\Framework\OpenApi\Server;
use Zef\Framework\OpenApi\SpecificationBuilder;
use Zef\Framework\OpenApi\Tag;
use Zef\Framework\OpenApi\YamlSpecificationSerializer;

/**
 * @internal
 */
final class OpenApiGoldenOutputTest extends TestCase
{
    public function testSpecificationMatchesGoldenDocument(): void
    {
        self::assertSame($this->goldenSpec(), $this->buildGolden());
    }

    public function testSpecificationIsByteStable(): void
    {
        $json = new JsonSpecificationSerializer();
        self::assertSame($json->serialize($this->goldenSpec()), $json->serialize($this->buildGolden()));
    }

    public function testYamlMatchesGoldenDocument(): void
    {
        $yaml = new YamlSpecificationSerializer();
        self::assertSame($yaml->serialize($this->goldenSpec()), $yaml->serialize($this->buildGolden()));
    }

    public function testPostmanCollectionMatchesGoldenDocument(): void
    {
        self::assertSame($this->goldenPostman(), new PostmanCollectionExporter()->export($this->buildGolden()));
    }

    public function testRefOnlySchemaProducesRefPlusNullableOnly(): void
    {
        $schema = new Schema(ref: '#/components/schemas/X');
        self::assertSame(['$ref' => '#/components/schemas/X'], $schema->toArray());
    }

    public function testSchemaWriteOnlyAndAllCombinators(): void
    {
        $schema = new Schema(
            type: SchemaType::Object,
            writeOnly: true,
            oneOf: [new Schema(type: SchemaType::String), new Schema(type: SchemaType::Integer)],
            anyOf: [new Schema(type: SchemaType::Boolean)],
            allOf: [new Schema(type: SchemaType::Object)],
            additionalProperties: new Schema(type: SchemaType::String),
            default: 'dflt',
        );
        self::assertSame([
            'type' => 'object',
            'writeOnly' => true,
            'oneOf' => [['type' => 'string'], ['type' => 'integer']],
            'anyOf' => [['type' => 'boolean']],
            'allOf' => [['type' => 'object']],
            'additionalProperties' => ['type' => 'string'],
            'default' => 'dflt',
        ], $schema->toArray());
    }

    public function testInformationObjectsCompleteSerialization(): void
    {
        self::assertSame(
            ['name' => 'Guild', 'email' => 'g@z.dev', 'url' => 'https://z.dev'],
            new Contact(name: 'Guild', email: 'g@z.dev', url: 'https://z.dev')->toArray(),
        );
        self::assertSame(
            ['name' => 'MIT', 'identifier' => 'MIT', 'url' => 'https://mit.edu'],
            new License(name: 'MIT', identifier: 'MIT', url: 'https://mit.edu')->toArray(),
        );
        self::assertSame(
            ['url' => 'https://api'],
            new Server('https://api')->toArray(),
        );
        self::assertSame(
            ['name' => 't'],
            new Tag('t')->toArray(),
        );
    }

    public function testBuilderVersionSwitch(): void
    {
        $builder = new SpecificationBuilder(new Info(title: 'x', version: '1'), OpenApiVersion::V3_0_3);
        self::assertSame('3.0.3', $builder->build()['openapi']);
    }

    /**
     * Golden specification: every section, every optional key, canonical
     * ordering (paths sorted, methods canonical, components sorted).
     *
     * @return array<string, mixed>
     */
    private function goldenSpec(): array
    {
        $json = (string) file_get_contents(__DIR__ . '/OpenApiGoldenSpec.json');

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** @return array<string, mixed> */
    private function goldenPostman(): array
    {
        $json = (string) file_get_contents(__DIR__ . '/OpenApiGoldenPostman.json');

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** @return array<string, mixed> */
    private function buildGolden(): array
    {
        $builder = new SpecificationBuilder(
            new Info(
                title: 'Golden API',
                version: '2.20.0',
                description: 'Golden document',
                termsOfService: 'https://zef.dev/tos',
                contact: new Contact(name: 'Guild', email: 'guild@zef.dev'),
                license: new License(name: 'MIT', identifier: 'MIT'),
            ),
        );
        $builder->addServer(new Server('https://api.zef.dev', 'prod', ['host' => 'api.zef.dev']));
        $builder->addTag(new Tag('users', 'User management'));
        $builder->addSecurityScheme('bearer', new SecurityScheme(SecuritySchemeType::Http, scheme: 'bearer', bearerFormat: 'JWT', description: 'JWT auth'));
        $builder->addSchema('User', new Schema(
            type: SchemaType::Object,
            description: 'A user',
            title: 'User',
            deprecated: true,
            minProperties: 1,
            maxProperties: 99,
            required: ['id', 'email'],
            properties: [
                'id' => new Schema(type: SchemaType::Integer, description: 'Identifier', readOnly: true, minimum: 1),
                'email' => new Schema(type: SchemaType::String, format: 'email', maxLength: 254, example: 'u@zef.dev'),
                'status' => new Schema(type: SchemaType::String, enum: ['active', 'off']),
                'tags' => new Schema(type: SchemaType::Array, minItems: 1, maxItems: 10, uniqueItems: true, items: new Schema(type: SchemaType::String)),
                'nickname' => new Schema(ref: '#/components/schemas/User', nullable: true),
            ],
            additionalPropertiesAllowed: false,
            example: ['id' => 1],
        ));
        $builder->addOperation(new Operation(
            operationId: 'getUser',
            method: 'GET',
            path: '/users/{id}',
            responses: ['200' => new Response('User found', ['application/json' => new Schema(ref: '#/components/schemas/User')]), '404' => new Response('Missing')],
            summary: 'Get one',
            description: 'Full detail',
            tags: ['users'],
            parameters: [
                new Parameter('id', ParameterLocation::Path, new Schema(type: SchemaType::Integer, minimum: 1), 'User id', example: 7),
                new Parameter('expand', ParameterLocation::Query, new Schema(type: SchemaType::String), '', required: true, deprecated: true),
            ],
            deprecated: true,
            security: [new SecurityRequirement(['bearer' => []])],
        ));
        $builder->addOperation(new Operation(
            operationId: 'createUser',
            method: 'POST',
            path: '/users',
            responses: ['201' => new Response('Created')],
            requestBody: new RequestBody([MediaType::Json->value => new Schema(ref: '#/components/schemas/User')], 'New user', true),
        ));

        return $builder->build();
    }
}
