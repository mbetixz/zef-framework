<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — OpenAPI Value Objects: invariants + serialization.

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\OpenApi\Contact;
use Zef\Framework\OpenApi\Info;
use Zef\Framework\OpenApi\License;
use Zef\Framework\OpenApi\MediaType;
use Zef\Framework\OpenApi\OpenApiVersion;
use Zef\Framework\OpenApi\Operation;
use Zef\Framework\OpenApi\Parameter;
use Zef\Framework\OpenApi\ParameterLocation;
use Zef\Framework\OpenApi\RequestBody;
use Zef\Framework\OpenApi\Response;
use Zef\Framework\OpenApi\Schema;
use Zef\Framework\OpenApi\SchemaDefinitionException;
use Zef\Framework\OpenApi\SchemaType;
use Zef\Framework\OpenApi\SecurityRequirement;
use Zef\Framework\OpenApi\SecurityScheme;
use Zef\Framework\OpenApi\SecuritySchemeType;
use Zef\Framework\OpenApi\Server;
use Zef\Framework\OpenApi\Tag;

/**
 * @internal
 */
final class OpenApiValueObjectsTest extends TestCase
{
    public function testSchemaEmitsMinimalArray(): void
    {
        $schema = new Schema(type: SchemaType::Integer, minimum: 1, maximum: 100);
        self::assertSame([
            'type' => 'integer',
            'minimum' => 1,
            'maximum' => 100,
        ], $schema->toArray());
    }

    public function testSchemaRefOnlyOutput(): void
    {
        $schema = new Schema(description: 'ignored', ref: '#/components/schemas/User', nullable: true);
        self::assertSame(['$ref' => '#/components/schemas/User', 'nullable' => true], $schema->toArray());
    }

    public function testArraySchemaRequiresItems(): void
    {
        $this->expectException(SchemaDefinitionException::class);
        new Schema(type: SchemaType::Array);
    }

    public function testSchemaBoundViolations(): void
    {
        $this->expectException(SchemaDefinitionException::class);
        new Schema(type: SchemaType::String, minLength: -1);
    }

    public function testSchemaMaxLengthMustBePositive(): void
    {
        $this->expectException(SchemaDefinitionException::class);
        new Schema(type: SchemaType::String, maxLength: 0);
    }

    public function testSchemaPatternBounded(): void
    {
        $this->expectException(SchemaDefinitionException::class);
        new Schema(type: SchemaType::String, pattern: str_repeat('a', 2049));
    }

    public function testSchemaRequiredMustBeUnique(): void
    {
        $this->expectException(SchemaDefinitionException::class);
        new Schema(type: SchemaType::Object, required: ['a', 'a']);
    }

    public function testSchemaEnumMustBeNonEmptyScalars(): void
    {
        $this->expectException(SchemaDefinitionException::class);
        new Schema(type: SchemaType::String, enum: []);
    }

    public function testSchemaEnumRejectsNestedArrays(): void
    {
        $this->expectException(SchemaDefinitionException::class);
        // @phpstan-ignore argument.type (a non-scalar enum entry IS the scenario)
        new Schema(type: SchemaType::String, enum: [['nested']]);
    }

    public function testSchemaPropertyNamesBounded(): void
    {
        $this->expectException(SchemaDefinitionException::class);
        new Schema(type: SchemaType::Object, properties: [str_repeat('x', 129) => new Schema(type: SchemaType::String)]);
    }

    public function testSchemaNestedStructureSerialization(): void
    {
        $schema = new Schema(
            type: SchemaType::Object,
            required: ['tags'],
            properties: [
                'tags' => new Schema(type: SchemaType::Array, uniqueItems: true, items: new Schema(type: SchemaType::String)),
                'meta' => new Schema(type: SchemaType::Object, additionalPropertiesAllowed: false),
            ],
        );
        $array = $schema->toArray();
        self::assertSame('array', ($array['properties'] ?? [])['tags']['type'] ?? null);
        self::assertSame(['type' => 'string'], ($array['properties'] ?? [])['tags']['items'] ?? null);
        self::assertSame(['type' => 'object', 'additionalProperties' => false], ($array['properties'] ?? [])['meta'] ?? null);
        self::assertSame(['tags'], $array['required'] ?? null);
    }

    public function testInfoInvariants(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Info(title: ' ', version: '1.0.0');
    }

    public function testInfoTermsMustBeUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Info(title: 'API', version: '1.0.0', termsOfService: 'not-a-url');
    }

    public function testInfoFullSerialization(): void
    {
        $info = new Info(
            title: 'Zef API',
            version: '2.20.0',
            description: 'Test API',
            contact: new Contact(name: 'Guild', email: 'guild@zef.dev'),
            license: new License(name: 'MIT', identifier: 'MIT'),
        );
        $array = $info->toArray();
        self::assertSame('guild@zef.dev', ($array['contact'] ?? [])['email'] ?? null);
        self::assertSame('MIT', ($array['license'] ?? [])['name'] ?? null);
        self::assertArrayNotHasKey('termsOfService', $array);
    }

    public function testContactNeedsAtLeastOneField(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Contact();
    }

    public function testServerVariables(): void
    {
        $server = new Server('https://{host}/api', 'prod', ['host' => 'api.zef.dev']);
        self::assertSame(['default' => 'api.zef.dev'], ($server->toArray()['variables'] ?? [])['host'] ?? null);
    }

    public function testServerRejectsEmptyUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Server('  ');
    }

    public function testParameterPathMustBeRequired(): void
    {
        $this->expectException(SchemaDefinitionException::class);
        new Parameter(name: 'id', in: ParameterLocation::Path, required: false);
    }

    public function testParameterIsRequiredImplicitlyForPath(): void
    {
        $parameter = new Parameter(name: 'id', in: ParameterLocation::Path);
        self::assertTrue($parameter->isRequired());
        self::assertArrayHasKey('required', $parameter->toArray());
    }

    public function testParameterOptionalQuery(): void
    {
        $parameter = new Parameter(name: 'page', in: ParameterLocation::Query, example: 1);
        self::assertFalse($parameter->isRequired());
        self::assertArrayNotHasKey('required', $parameter->toArray());
        self::assertSame(1, $parameter->toArray()['example'] ?? null);
    }

    public function testRequestBodyNeedsContent(): void
    {
        $this->expectException(SchemaDefinitionException::class);
        new RequestBody(content: []);
    }

    public function testRequestBodySerialization(): void
    {
        $body = new RequestBody(
            content: [MediaType::Json->value => new Schema(type: SchemaType::Object)],
            description: 'payload',
            required: true,
        );
        $array = $body->toArray();
        self::assertArrayHasKey('required', $array);
        self::assertSame('object', $array['content']['application/json']['schema']['type']);
    }

    public function testResponseDescriptionMandatory(): void
    {
        $this->expectException(SchemaDefinitionException::class);
        new Response(description: ' ');
    }

    public function testSecurityRequirementInvariants(): void
    {
        $this->expectException(SchemaDefinitionException::class);
        new SecurityRequirement([]);
    }

    public function testSecurityRequirementSerialization(): void
    {
        $requirement = new SecurityRequirement(['oauth2' => ['read:users']]);
        self::assertSame(['oauth2' => ['read:users']], $requirement->toArray());
    }

    public function testSecuritySchemeTypeInvariants(): void
    {
        $this->expectException(SchemaDefinitionException::class);
        new SecurityScheme(type: SecuritySchemeType::Http);
    }

    public function testSecuritySchemeApiKeyNeedsLocation(): void
    {
        $this->expectException(SchemaDefinitionException::class);
        new SecurityScheme(type: SecuritySchemeType::ApiKey);
    }

    public function testSecuritySchemeOpenIdNeedsUrl(): void
    {
        $this->expectException(SchemaDefinitionException::class);
        new SecurityScheme(type: SecuritySchemeType::OpenIdConnect, openIdConnectUrl: 'nope');
    }

    public function testSecuritySchemeCrossFieldGuards(): void
    {
        $this->expectException(SchemaDefinitionException::class);
        new SecurityScheme(type: SecuritySchemeType::ApiKey, scheme: 'bearer');
    }

    public function testSecuritySchemeBearerFormatRequiresBearerScheme(): void
    {
        $this->expectException(SchemaDefinitionException::class);
        new SecurityScheme(type: SecuritySchemeType::Http, scheme: 'basic', bearerFormat: 'JWT');
    }

    public function testSecuritySchemeHttpValid(): void
    {
        $scheme = new SecurityScheme(type: SecuritySchemeType::Http, scheme: 'bearer', bearerFormat: 'JWT');
        self::assertSame([
            'type' => 'http',
            'scheme' => 'bearer',
            'bearerFormat' => 'JWT',
        ], $scheme->toArray());
    }

    public function testOperationInvariants(): void
    {
        $this->expectException(SchemaDefinitionException::class);
        new Operation(operationId: 'op', method: 'BREW', path: '/x', responses: ['200' => new Response('ok')]);
    }

    public function testOperationPathMustStartWithSlash(): void
    {
        $this->expectException(SchemaDefinitionException::class);
        new Operation(operationId: 'op', method: 'GET', path: 'users', responses: ['200' => new Response('ok')]);
    }

    public function testOperationNeedsResponse(): void
    {
        $this->expectException(SchemaDefinitionException::class);
        new Operation(operationId: 'op', method: 'GET', path: '/users', responses: []);
    }

    public function testOperationIdCharset(): void
    {
        $this->expectException(SchemaDefinitionException::class);
        new Operation(operationId: 'bad id!', method: 'GET', path: '/users', responses: ['200' => new Response('ok')]);
    }

    public function testOperationNumericStatusKeysAreNormalized(): void
    {
        // PHP coerces '200' keys to int; the VO must accept and normalize.
        $operation = new Operation(operationId: 'op', method: 'GET', path: '/users', responses: [200 => new Response('ok')]);
        self::assertArrayHasKey('200', $operation->toArray()['responses']);
    }

    public function testOperationFullSerialization(): void
    {
        $operation = new Operation(
            operationId: 'getUser',
            method: 'GET',
            path: '/users/{id}',
            responses: ['200' => new Response('ok', ['application/json' => new Schema(type: SchemaType::Object)])],
            summary: 'Get user',
            tags: ['users'],
            parameters: [new Parameter(name: 'id', in: ParameterLocation::Path, schema: new Schema(type: SchemaType::Integer))],
            deprecated: true,
            security: [new SecurityRequirement(['bearer' => []])],
        );
        $array = $operation->toArray();
        self::assertSame('getUser', $array['operationId']);
        self::assertSame(['users'], $array['tags'] ?? null);
        self::assertSame('path', ($array['parameters'] ?? [])[0]['in'] ?? null);
        self::assertTrue($array['deprecated'] ?? false);
        self::assertSame([['bearer' => []]], $array['security'] ?? null);
    }

    public function testTagBoundedName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Tag(str_repeat('t', 129));
    }

    public function testVersionEnumValues(): void
    {
        self::assertSame('3.1.0', OpenApiVersion::V3_1_0->value);
        self::assertSame('3.0.3', OpenApiVersion::V3_0_3->value);
    }
}
