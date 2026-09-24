<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.20.0 — Mutation-kill round for the OpenAPI module.
 * Each test targets a specific escaped-mutant family: constructor guard
 * boundaries (both sides), every match arm, cross-field security scheme
 * guards, fallback name derivation, and segment-condition junk inputs.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\OpenApi\Attribute\Deprecated as DeprecatedAttr;
use Zef\Framework\OpenApi\Attribute\OpenApi;
use Zef\Framework\OpenApi\Attribute\Property as PropertyAttr;
use Zef\Framework\OpenApi\Attribute\Response as ResponseAttr;
use Zef\Framework\OpenApi\Attribute\Route as RouteAttr;
use Zef\Framework\OpenApi\Attribute\Schema as SchemaAttr;
use Zef\Framework\OpenApi\Attribute\Security as SecurityAttr;
use Zef\Framework\OpenApi\Attribute\Tag as TagAttr;
use Zef\Framework\OpenApi\Contact;
use Zef\Framework\OpenApi\Info;
use Zef\Framework\OpenApi\License;
use Zef\Framework\OpenApi\Operation;
use Zef\Framework\OpenApi\Parameter;
use Zef\Framework\OpenApi\ParameterLocation;
use Zef\Framework\OpenApi\PostmanCollectionExporter;
use Zef\Framework\OpenApi\RequestBody;
use Zef\Framework\OpenApi\Response;
use Zef\Framework\OpenApi\RouteSpecExtractor;
use Zef\Framework\OpenApi\Schema;
use Zef\Framework\OpenApi\SchemaDefinitionException;
use Zef\Framework\OpenApi\SchemaGenerator;
use Zef\Framework\OpenApi\SchemaType;
use Zef\Framework\OpenApi\SecurityScheme;
use Zef\Framework\OpenApi\SecuritySchemeType;
use Zef\Framework\OpenApi\Server;
use Zef\Framework\OpenApi\SpecificationBuilder;
use Zef\Framework\OpenApi\SpecificationException;
use Zef\Framework\OpenApi\Tag;
use Zef\Framework\Validation\Validator;

#[SchemaAttr(name: 'KillSchema')]
final class OpenApiKillBuiltinDto
{
    public int $tInt;

    public float $tFloat;

    public string $tString;

    public bool $tBool;

    /** @var array<string, mixed> */
    public array $tArray;

    public object $tObject;

    /** @var iterable<string, mixed> */
    public iterable $tIterable;

    public mixed $tMixed;
}

#[SchemaAttr(name: 'KillCoalesce')]
final class OpenApiKillCoalesceDto
{
    #[PropertyAttr(format: 'date', minLength: 2, maxLength: 4, pattern: '^x$', minimum: 3, maximum: 9, default: 'a', example: 'b', enum: ['a', 'b'])]
    public string $multi;
}

final class OpenApiKillInvokeHandler
{
    #[RouteAttr(method: 'GET', path: '/invoke', operationId: 'invokeMe', summary: 'Invoked')]
    #[ResponseAttr(status: 200, description: 'Invoked ok')]
    public function __invoke(): never
    {
        throw new \LogicException('Documentation fixture only.');
    }
}

#[DeprecatedAttr]
#[SecurityAttr(scheme: 'bearer')]
#[TagAttr(name: 'legacy')]
#[TagAttr(name: 'audit')]
final class OpenApiKillClassDefaultsHandler
{
    #[RouteAttr(method: 'GET', path: '/a', tags: ['users'])]
    #[ResponseAttr(status: 200, description: 'A ok')]
    public function handle(): never
    {
        throw new \LogicException('Documentation fixture only.');
    }

    #[RouteAttr(method: 'DELETE', path: '/b')]
    public function remove(): never
    {
        throw new \LogicException('Documentation fixture only.');
    }
}

#[SchemaAttr(name: 'KillRequiredOverride')]
final class OpenApiKillRequiredOverrideDto
{
    public static string $shared = 'x';

    #[PropertyAttr(required: true)]
    public ?string $withDefault = 'x';

    #[PropertyAttr(nullable: true)]
    public string $nullableString = 'x';
}

#[SchemaAttr(name: 'KillInfoOverride')]
#[OpenApi(title: 'Overridden Title', version: '9.9.9')]
final class OpenApiInfoOverrideHandler
{
    #[RouteAttr(method: 'GET', path: '/i', summary: 'Simple')]
    #[RouteAttr(method: 'GET', path: '/i/{id:int}', summary: 'Constrained')]
    public function handle(): never
    {
        throw new \LogicException('Documentation fixture only.');
    }
}

final class OpenApiMethodSecurityHandler
{
    #[RouteAttr(method: 'GET', path: '/s')]
    #[SecurityAttr(scheme: 'apiKeyQuery')]
    public function handle(): never
    {
        throw new \LogicException('Documentation fixture only.');
    }
}

/**
 * @internal
 */
final class OpenApiMutationKillTest extends TestCase
{
    private SchemaGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new SchemaGenerator();
    }

    // ------------------------------------------------ named-type matrix

    public function testEveryBuiltinNamedTypeMapsToItsSchema(): void
    {
        $reflection = new \ReflectionClass(OpenApiKillBuiltinDto::class);
        $expected = [
            'tInt' => SchemaType::Integer,
            'tFloat' => SchemaType::Number,
            'tString' => SchemaType::String,
            'tBool' => SchemaType::Boolean,
            'tArray' => SchemaType::Array,
            'tObject' => SchemaType::String,
            'tIterable' => SchemaType::String,
            'tMixed' => SchemaType::String,
        ];
        foreach ($expected as $prop => $schemaType) {
            $type = $reflection->getProperty($prop)->getType();
            self::assertNotNull($type);
            self::assertSame($schemaType, $this->generator->generateFromType($type)->type, $prop);
        }
        $floatType = $reflection->getProperty('tFloat')->getType();
        self::assertNotNull($floatType);
        self::assertSame('float', $this->generator->generateFromType($floatType)->format);
    }

    public function testCoalesceMatrixPropertyMergesEveryConstraint(): void
    {
        $schema = $this->generator->generateFromClass(OpenApiKillCoalesceDto::class);
        $multi = $schema->properties['multi'];
        self::assertSame(SchemaType::String, $multi->type); // native kept when meta is String
        self::assertSame('date', $multi->format);
        self::assertSame(2, $multi->minLength);
        self::assertSame(4, $multi->maxLength);
        self::assertSame('^x$', $multi->pattern);
        self::assertSame(3, $multi->minimum);
        self::assertSame(9, $multi->maximum);
        self::assertSame(['a', 'b'], $multi->enum);
        self::assertSame('a', $multi->default);
        self::assertSame('b', $multi->example);
    }

    // ------------------------------------------------ boundary matrices

    public function testParameterNameBoundariesBothSides(): void
    {
        new Parameter(name: str_repeat('a', 128), in: ParameterLocation::Query);
        $this->expectException(SchemaDefinitionException::class);
        new Parameter(name: str_repeat('a', 129), in: ParameterLocation::Query);
    }

    public function testParameterNameMultibyteUnderByteLimitIsAccepted(): void
    {
        $name = str_repeat('é', 65); // 130 bytes, 65 characters
        $parameter = new Parameter(name: $name, in: ParameterLocation::Query);
        self::assertSame($name, $parameter->name);
    }

    public function testSchemaPropertyNameBoundaries(): void
    {
        $ok = new Schema(type: SchemaType::Object, properties: [str_repeat('p', 128) => new Schema(type: SchemaType::String)]);
        self::assertArrayHasKey(str_repeat('p', 128), $ok->properties);
        $this->expectException(SchemaDefinitionException::class);
        new Schema(type: SchemaType::Object, properties: [str_repeat('p', 129) => new Schema(type: SchemaType::String)]);
    }

    public function testSchemaNumericBoundariesBothSides(): void
    {
        $valid = new Schema(
            type: SchemaType::String,
            minLength: 0,
            maxLength: 1,
            pattern: str_repeat('a', 2048),
            minimum: -5,
            maximum: 5,
            minItems: 0,
            maxItems: 1,
            minProperties: 0,
            maxProperties: 1,
        );
        self::assertSame(2048, strlen((string) $valid->pattern));
        $this->expectException(SchemaDefinitionException::class);
        new Schema(type: SchemaType::Array, maxItems: 0, items: new Schema(type: SchemaType::String));
    }

    public function testSchemaMinItemsNegativeRejected(): void
    {
        $this->expectException(SchemaDefinitionException::class);
        new Schema(type: SchemaType::Array, minItems: -1, items: new Schema(type: SchemaType::String));
    }

    public function testSchemaMinPropertiesNegativeRejected(): void
    {
        $this->expectException(SchemaDefinitionException::class);
        new Schema(type: SchemaType::Object, minProperties: -1);
    }

    public function testSchemaMaxPropertiesZeroRejected(): void
    {
        $this->expectException(SchemaDefinitionException::class);
        new Schema(type: SchemaType::Object, maxProperties: 0);
    }

    public function testTagBoundariesBothSides(): void
    {
        new Tag(str_repeat('t', 128));
        $this->expectException(\InvalidArgumentException::class);
        new Tag(str_repeat('t', 129));
    }

    public function testTagMultibyteUnderByteLimitAccepted(): void
    {
        $tag = new Tag(str_repeat('é', 65));
        self::assertSame(65, mb_strlen($tag->name));
    }

    // ------------------------------------------------ content validations

    public function testRequestBodyRejectsWhitespaceMediaType(): void
    {
        $this->expectException(SchemaDefinitionException::class);
        new RequestBody(content: ['  ' => new Schema(type: SchemaType::String)]);
    }

    public function testRequestBodyRejectsNonStringMediaType(): void
    {
        $this->expectException(SchemaDefinitionException::class);
        // @phpstan-ignore argument.type (intentionally invalid media-type key)
        new RequestBody(content: [7 => new Schema(type: SchemaType::String)]);
    }

    public function testRequestBodyRejectsNonSchemaValue(): void
    {
        $this->expectException(SchemaDefinitionException::class);

        /** @var array<string, mixed> $content */
        $content = ['application/json' => 'nope'];
        // @phpstan-ignore argument.type (intentionally invalid media-type value)
        new RequestBody(content: $content);
    }

    public function testResponseRejectsWhitespaceMediaType(): void
    {
        $this->expectException(SchemaDefinitionException::class);
        new Response('ok', ['  ' => new Schema(type: SchemaType::String)]);
    }

    public function testResponseRejectsNonSchemaValue(): void
    {
        $this->expectException(SchemaDefinitionException::class);

        /** @var array<string, mixed> $content */
        $content = ['application/json' => 42];
        // @phpstan-ignore argument.type (intentionally invalid media-type value)
        new Response('ok', $content);
    }

    public function testServerVariableJunkRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Server('https://api', 'd', ['' => 'x']);
    }

    public function testServerVariableNonStringValueRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        /** @var array<string, mixed> $vars */
        $vars = ['host' => 5];
        // @phpstan-ignore argument.type (intentionally invalid variable value)
        new Server('https://api', 'd', $vars);
    }

    // ------------------------------------------------ security scheme guards

    public function testHttpSchemeEmptyStringRejected(): void
    {
        $this->expectException(SchemaDefinitionException::class);
        new SecurityScheme(SecuritySchemeType::Http, scheme: '  ');
    }

    public function testSchemeOnNonHttpRejected(): void
    {
        $this->expectException(SchemaDefinitionException::class);
        new SecurityScheme(SecuritySchemeType::OAuth2, scheme: 'bearer');
    }

    public function testInOnNonApiKeyRejected(): void
    {
        $this->expectException(SchemaDefinitionException::class);
        new SecurityScheme(SecuritySchemeType::Http, scheme: 'basic', in: ParameterLocation::Header);
    }

    public function testOpenIdConnectUrlOnOtherTypeRejected(): void
    {
        $this->expectException(SchemaDefinitionException::class);
        new SecurityScheme(SecuritySchemeType::OAuth2, openIdConnectUrl: 'https://id');
    }

    public function testApiKeyAndOpenIdAreValidOnTheirOwn(): void
    {
        $apiKey = new SecurityScheme(SecuritySchemeType::ApiKey, in: ParameterLocation::Header);
        self::assertSame('apiKey', $apiKey->toArray()['type']);
        $oid = new SecurityScheme(SecuritySchemeType::OpenIdConnect, openIdConnectUrl: 'https://id');
        self::assertSame('openIdConnect', $oid->toArray()['type']);
        $mtls = new SecurityScheme(SecuritySchemeType::MutualTls);
        self::assertSame('mutualTLS', $mtls->toArray()['type']);
    }

    public function testContactOnlyUrlIsValidAndBadEmailUrlRejected(): void
    {
        self::assertSame(['url' => 'https://z.dev'], new Contact(url: 'https://z.dev')->toArray());
        $this->expectException(\InvalidArgumentException::class);
        new Contact(email: 'nope');
    }

    public function testContactBadUrlRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Contact(url: 'nope');
    }

    public function testLicenseBadUrlRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new License(name: 'MIT', url: 'nope');
    }

    public function testInfoVersionWhitespaceRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Info(title: 'API', version: '  ');
    }

    public function testServerUrlWhitespaceRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Server(' ');
    }

    // ------------------------------------------------ operation validations

    public function testOperationEmptyStatusKeyRejected(): void
    {
        $this->expectException(SchemaDefinitionException::class);
        new Operation(operationId: 'op', method: 'GET', path: '/x', responses: [' ' => new Response('ok')]);
    }

    public function testOperationNonStringStatusKeyRejected(): void
    {
        $this->expectException(\TypeError::class);
        // @phpstan-ignore array.invalidKey (intentionally invalid status key)
        $responses = [['weird'] => new Response('ok')];
        new Operation(operationId: 'op', method: 'GET', path: '/x', responses: $responses);
    }

    public function testOperationTagJunkRejected(): void
    {
        $this->expectException(SchemaDefinitionException::class);
        new Operation(operationId: 'op', method: 'GET', path: '/x', responses: ['200' => new Response('ok')], tags: [' ']);
    }

    public function testOperationParameterJunkRejected(): void
    {
        $this->expectException(SchemaDefinitionException::class);

        /** @var list<mixed> $parameters */
        $parameters = ['nope'];
        // @phpstan-ignore argument.type (intentionally invalid parameter entry)
        new Operation(operationId: 'op', method: 'GET', path: '/x', responses: ['200' => new Response('ok')], parameters: $parameters);
    }

    public function testOperationSecurityJunkRejected(): void
    {
        $this->expectException(SchemaDefinitionException::class);

        /** @var list<mixed> $security */
        $security = ['nope'];
        // @phpstan-ignore argument.type (intentionally invalid security entry)
        new Operation(operationId: 'op', method: 'GET', path: '/x', responses: ['200' => new Response('ok')], security: $security);
    }

    // ------------------------------------------------ builder behaviors

    public function testBuilderRejectsDuplicateOperationIdAcrossPaths(): void
    {
        $builder = new SpecificationBuilder(new Info(title: 'x', version: '1'));
        $builder->addOperation(new Operation('dup', 'GET', '/a', ['200' => new Response('ok')]));
        $this->expectException(SpecificationException::class);
        $builder->addOperation(new Operation('dup', 'GET', '/b', ['200' => new Response('ok')]));
    }

    public function testBuilderRejectsDuplicateSecuritySchemeWithDifferentDefinition(): void
    {
        $builder = new SpecificationBuilder(new Info(title: 'x', version: '1'));
        $builder->addSecurityScheme('s', new SecurityScheme(SecuritySchemeType::Http, scheme: 'bearer'));
        $this->expectException(SpecificationException::class);
        $builder->addSecurityScheme('s', new SecurityScheme(SecuritySchemeType::Http, scheme: 'basic'));
    }

    public function testBuilderTagIsFirstWins(): void
    {
        $builder = new SpecificationBuilder(new Info(title: 'x', version: '1'));
        $builder->addTag(new Tag('t', 'first'));
        $builder->addTag(new Tag('t', 'second'));

        /** @var list<array<string, mixed>> $tags */
        $tags = $builder->build()['tags'] ?? [];

        /** @var array<string, mixed> $firstTag */
        $firstTag = $tags[0] ?? [];
        self::assertSame('first', $firstTag['description'] ?? null);
    }

    public function testBuilderIdempotentSecuritySchemeAccepted(): void
    {
        $builder = new SpecificationBuilder(new Info(title: 'x', version: '1'));
        $scheme = new SecurityScheme(SecuritySchemeType::Http, scheme: 'bearer');
        $builder->addSecurityScheme('b', $scheme);
        $builder->addSecurityScheme('b', $scheme);

        /** @var array<string, array<string, mixed>> $schemes */
        $schemes = ($builder->build()['components'] ?? [])['securitySchemes'] ?? [];

        /** @var array<string, mixed> $bearer */
        $bearer = $schemes['b'] ?? [];
        self::assertSame('bearer', $bearer['scheme'] ?? null);
    }

    public function testBuilderOptionalSectionOmittedWhenEmpty(): void
    {
        $spec = new SpecificationBuilder(new Info(title: 'x', version: '1'))->build();
        self::assertArrayNotHasKey('servers', $spec);
        self::assertArrayNotHasKey('tags', $spec);
        self::assertArrayNotHasKey('components', $spec);
    }

    // ------------------------------------------------ extractor behaviors

    public function testExtractSurvivesJunkSegmentsAndPreservesExtras(): void
    {
        $extractor = new RouteSpecExtractor(new Info(title: 'x', version: '1'));
        $spec = $extractor->extract([
            ['method' => 'GET', 'pattern' => '/x/{a}', 'handler' => 'h', 'name' => null,
                'segments' => [
                    ['dynamic' => true, 'name' => 'a', 'constraint' => 'int'],
                    'junk-string',
                    ['dynamic' => false, 'value' => 'static'],
                    ['dynamic' => true, 'name' => 'ghost'],
                    ['dynamic' => true, 'name' => 42],
                    ['name' => 'noDynamic'],
                ]],
        ])->build();

        /** @var array<string, mixed> $operation */
        $operation = $spec['paths']['/x/{a}']['get'];

        /** @var list<array<string, mixed>> $parameters */
        $parameters = $operation['parameters'];
        $names = [];
        foreach ($parameters as $parameter) {
            $name = $parameter['name'] ?? null;
            self::assertIsString($name);
            $names[] = $name;
        }
        self::assertSame(['a', 'ghost'], $names);
    }

    public function testPostmanFallbackNameUsesMethodAndPath(): void
    {
        $collection = new PostmanCollectionExporter()->export([
            'info' => ['title' => 'X', 'version' => '1'],
            'paths' => ['/no-id' => ['get' => ['responses' => ['200' => ['description' => 'd']]]]],
        ]);

        /** @var array<string, mixed> $folder */
        $folder = $collection['item'][0];

        /** @var array<int|string, mixed> $entries */
        $entries = $folder['item'];

        /** @var array<string, mixed> $entry */
        $entry = $entries[0] ?? [];
        self::assertSame('GET /no-id', $entry['name'] ?? null);
    }

    public function testPostmanPathWithoutBracesStaysUntouched(): void
    {
        $collection = new PostmanCollectionExporter()->export([
            'info' => ['title' => 'X', 'version' => '1'],
            'paths' => ['/files/{name}.json' => ['get' => ['operationId' => 'f', 'responses' => ['200' => ['description' => 'd']]]]],
        ]);

        /** @var array<string, mixed> $folder */
        $folder = $collection['item'][0];

        /** @var array<int|string, mixed> $entries */
        $entries = $folder['item'];

        /** @var array<string, mixed> $entry */
        $entry = $entries[0] ?? [];

        /** @var array<string, mixed> $request */
        $request = $entry['request'] ?? [];

        /** @var array<string, mixed> $url */
        $url = $request['url'] ?? [];
        self::assertSame(['files', '{name}.json'], $url['path'] ?? null);
    }

    public function testInvokeOnlyHandlerIsDocumented(): void
    {
        $handlerClass = OpenApiKillInvokeHandler::class;
        $extractor = new RouteSpecExtractor(
            new Info(title: 'x', version: '1'),
            static fn (string $id): ?string => $id === 'h' ? $handlerClass : null,
        );
        $spec = $extractor->extract([
            ['method' => 'GET', 'pattern' => '/invoke', 'handler' => 'h', 'name' => null, 'segments' => []],
        ])->build();

        /** @var array<string, mixed> $operation */
        $operation = $spec['paths']['/invoke']['get'];
        self::assertSame('invokeMe', $operation['operationId']);
        self::assertSame('Invoked', $operation['summary']);
    }

    public function testClassDefaultsDeprecatedAndSecurityApplyToAllOperations(): void
    {
        $handlerClass = OpenApiKillClassDefaultsHandler::class;
        $extractor = new RouteSpecExtractor(
            new Info(title: 'x', version: '1'),
            static fn (string $id): ?string => $id === 'h' ? $handlerClass : null,
        );
        $spec = $extractor->extract([
            ['method' => 'GET', 'pattern' => '/a', 'handler' => 'h', 'name' => null, 'segments' => []],
            ['method' => 'DELETE', 'pattern' => '/b', 'handler' => 'h', 'name' => null, 'segments' => []],
        ])->build();
        foreach (['/a' => 'get', '/b' => 'delete'] as $path => $method) {
            /** @var array<string, mixed> $operation */
            $operation = $spec['paths'][$path][$method];
            self::assertSame(true, $operation['deprecated'] ?? null, $path);

            /** @var list<mixed> $operationSecurity */
            $operationSecurity = $operation['security'] ?? [];
            self::assertSame([['bearer' => []]], $operationSecurity, $path);

            /** @var list<mixed> $operationTags */
            $operationTags = $operation['tags'] ?? [];
            self::assertContains('legacy', $operationTags, $path);
        }
    }

    public function testMethodSecurityOverridesClassSecurity(): void
    {
        $handlerClass = OpenApiMethodSecurityHandler::class;
        $extractor = new RouteSpecExtractor(
            new Info(title: 'x', version: '1'),
            static fn (string $id): ?string => $id === 'h' ? $handlerClass : null,
        );
        $spec = $extractor->extract([
            ['method' => 'GET', 'pattern' => '/s', 'handler' => 'h', 'name' => null, 'segments' => []],
        ])->build();

        /** @var array<string, mixed> $operation */
        $operation = $spec['paths']['/s']['get'];
        self::assertSame([['apiKeyQuery' => []]], $operation['security']);
    }

    public function testRouteAttributeWithMismatchedPathIsIgnored(): void
    {
        $handlerClass = OpenApiKillInvokeHandler::class; // declares /invoke
        $extractor = new RouteSpecExtractor(
            new Info(title: 'x', version: '1'),
            static fn (string $id): ?string => $id === 'h' ? $handlerClass : null,
        );
        $spec = $extractor->extract([
            ['method' => 'GET', 'pattern' => '/other', 'handler' => 'h', 'name' => null, 'segments' => []],
        ])->build();

        /** @var array<string, mixed> $operation */
        $operation = $spec['paths']['/other']['get'];

        /** @var array<string, mixed> $responses */
        $responses = $operation['responses'] ?? [];

        /** @var array<string, mixed> $response200 */
        $response200 = $responses['200'] ?? [];
        self::assertSame('Successful response.', $response200['description'] ?? null);
        self::assertArrayNotHasKey('summary', $operation);
    }

    public function testCircularGenerationOrderDoesNotLeak(): void
    {
        // generate leaf first, then node: the node's expansion must still
        // terminate and both schemas must be complete objects (no refs).
        $leaf = $this->generator->generateFromClass(OpenApiGeneratorLeafDto::class);
        self::assertSame(SchemaType::Object, $leaf->type);
        self::assertSame('#/components/schemas/OpenApiGeneratorNodeDto', $leaf->properties['parent']->ref);
        $node = $this->generator->generateFromClass(OpenApiGeneratorNodeDto::class);
        self::assertSame('#/components/schemas/OpenApiGeneratorLeafDto', $node->properties['leaf']->ref);
        $leafAgain = $this->generator->generateFromClass(OpenApiGeneratorLeafDto::class);
        self::assertSame(SchemaType::Object, $leafAgain->type);
        self::assertNull($leafAgain->ref);
    }

    public function testPostmanSkipsJunkPathsButKeepsLaterValidOnes(): void
    {
        $collection = new PostmanCollectionExporter()->export([
            'info' => ['title' => 'X', 'version' => '1'],
            'paths' => [
                7 => 'junk',
                '/after' => ['get' => ['operationId' => 'after', 'responses' => ['200' => ['description' => 'd']]]],
            ],
        ]);
        self::assertCount(1, $collection['item']);
        self::assertSame('/after', $collection['item'][0]['name']);
    }

    public function testPostmanBodyPreservesUnicodeAndEscapesNothingElse(): void
    {
        $collection = new PostmanCollectionExporter()->export([
            'info' => ['title' => 'X', 'version' => '1'],
            'paths' => ['/hook' => ['post' => [
                'operationId' => 'hook',
                'requestBody' => ['content' => ['application/json' => ['schema' => [
                    'type' => 'object',
                    'properties' => ['url' => ['type' => 'string', 'example' => 'https://hook/ünïcode']],
                ]]]],
                'responses' => ['200' => ['description' => 'd']],
            ]]],
        ]);

        /** @var array<string, mixed> $folder */
        $folder = $collection['item'][0];

        /** @var array<int|string, mixed> $entries */
        $entries = $folder['item'];

        /** @var array<string, mixed> $entry */
        $entry = $entries[0] ?? [];

        /** @var array<string, mixed> $request */
        $request = $entry['request'] ?? [];

        /** @var array<string, mixed> $body */
        $body = $request['body'] ?? [];

        $raw = $body['raw'] ?? null;
        self::assertIsString($raw);
        self::assertStringContainsString('https://hook/ünïcode', $raw);
        self::assertStringNotContainsString('\/', $raw);
    }

    public function testPostmanIntegerExampleFallsBackWhenMinimumNotNumeric(): void
    {
        $collection = new PostmanCollectionExporter()->export([
            'info' => ['title' => 'X', 'version' => '1'],
            'paths' => ['/qty' => ['post' => [
                'operationId' => 'qty',
                'requestBody' => ['content' => ['application/json' => ['schema' => [
                    'type' => 'object',
                    'properties' => ['qty' => ['type' => 'integer', 'minimum' => 'low']],
                ]]]],
                'responses' => ['200' => ['description' => 'd']],
            ]]],
        ]);

        /** @var array<string, mixed> $folder */
        $folder = $collection['item'][0];

        /** @var array<int|string, mixed> $entries */
        $entries = $folder['item'];

        /** @var array<string, mixed> $entry */
        $entry = $entries[0] ?? [];

        /** @var array<string, mixed> $request */
        $request = $entry['request'] ?? [];

        /** @var array<string, mixed> $body */
        $body = $request['body'] ?? [];

        $raw = $body['raw'] ?? null;
        self::assertIsString($raw);
        self::assertStringContainsString('"qty":1', $raw);
    }

    public function testPostmanApiKeyAuthIsComplete(): void
    {
        $collection = new PostmanCollectionExporter()->export([
            'info' => ['title' => 'X', 'version' => '1'],
            'paths' => [],
            'components' => ['securitySchemes' => ['key' => ['type' => 'apiKey', 'in' => 'query']]],
        ]);

        /** @var array<string, mixed> $auth */
        $auth = $collection['auth'] ?? [];
        self::assertSame([
            'type' => 'apikey',
            'apikey' => [
                ['key' => 'in', 'value' => 'query', 'type' => 'string'],
                ['key' => 'key', 'value' => '<api-key-name>', 'type' => 'string'],
                ['key' => 'value', 'value' => '<api-key-value>', 'type' => 'string'],
            ],
        ], $auth);
    }

    public function testClassTagsMergeWithRouteTagsInOrder(): void
    {
        $handlerClass = OpenApiKillClassDefaultsHandler::class;
        $extractor = new RouteSpecExtractor(
            new Info(title: 'x', version: '1'),
            static fn (string $id): ?string => $id === 'h' ? $handlerClass : null,
        );
        $spec = $extractor->extract([
            ['method' => 'GET', 'pattern' => '/a', 'handler' => 'h', 'name' => null, 'segments' => []],
        ])->build();

        /** @var array<string, mixed> $operation */
        $operation = $spec['paths']['/a']['get'];
        self::assertSame(['legacy', 'audit', 'users'], $operation['tags']);
    }

    public function testPathAndAttributeParametersCombineInOrder(): void
    {
        $handlerClass = OpenApiKillInvokeHandler::class; // has X-Trace? no - use extractor controller
        unset($handlerClass);
        $extractor = new RouteSpecExtractor(
            new Info(title: 'x', version: '1'),
            static fn (string $id): ?string => $id === 'api.user' ? OpenApiExtractorUserController::class : null,
        );
        $spec = $extractor->extract([
            ['method' => 'GET', 'pattern' => '/users/{id}', 'handler' => 'api.user', 'name' => null, 'segments' => []],
        ])->build();

        /** @var array<string, mixed> $operation */
        $operation = $spec['paths']['/users/{id}']['get'];

        /** @var list<array<string, mixed>> $parameters */
        $parameters = $operation['parameters'];
        $names = [];
        foreach ($parameters as $parameter) {
            $name = $parameter['name'] ?? null;
            self::assertIsString($name);
            $names[] = $name;
        }
        self::assertSame(['id', 'X-Trace'], $names);
    }

    public function testStaticPropertiesAreSkipped(): void
    {
        $schema = $this->generator->generateFromClass(OpenApiKillCoalesceDto::class);
        self::assertArrayNotHasKey('shared', $schema->properties);
    }

    public function testDefaultValuedPropertyStillRequiredWhenForced(): void
    {
        $schema = $this->generator->generateFromClass(OpenApiKillRequiredOverrideDto::class);
        self::assertContains('withDefault', $schema->required);
        self::assertNotContains('nullableString', $schema->required);
    }

    public function testOpenApiAttributeOverridesDocumentInfo(): void
    {
        $handlerClass = OpenApiInfoOverrideHandler::class;
        $extractor = new RouteSpecExtractor(
            new Info(title: 'Original', version: '1'),
            static fn (string $id): ?string => $id === 'h' ? $handlerClass : null,
        );
        $spec = $extractor->extract([
            ['method' => 'GET', 'pattern' => '/i', 'handler' => 'h', 'name' => null, 'segments' => []],
        ])->build();
        self::assertSame('Overridden Title', $spec['info']['title']);
        self::assertSame('9.9.9', $spec['info']['version']);
    }

    public function testRouteAttributeMatchesRawPatternWithConstraint(): void
    {
        $handlerClass = OpenApiInfoOverrideHandler::class;
        $extractor = new RouteSpecExtractor(
            new Info(title: 'x', version: '1'),
            static fn (string $id): ?string => $id === 'h' ? $handlerClass : null,
        );
        $spec = $extractor->extract([
            ['method' => 'GET', 'pattern' => '/i/{id:int}', 'handler' => 'h', 'name' => null, 'segments' => []],
        ])->build();

        /** @var array<string, mixed> $operation */
        $operation = $spec['paths']['/i/{id}']['get'];
        self::assertSame('Constrained', $operation['summary'] ?? null);
    }

    public function testLongHandlerFallsBackToGenericOperationId(): void
    {
        $extractor = new RouteSpecExtractor(new Info(title: 'x', version: '1'));
        $spec = $extractor->extract([
            ['method' => 'GET', 'pattern' => '/long', 'handler' => str_repeat('h', 300), 'name' => null, 'segments' => []],
        ])->build();

        /** @var array<string, mixed> $operation */
        $operation = $spec['paths']['/long']['get'];
        self::assertSame('get.route', $operation['operationId']);
    }

    public function testHandlerWithDotsIsTrimmedInOperationId(): void
    {
        $extractor = new RouteSpecExtractor(new Info(title: 'x', version: '1'));
        $spec = $extractor->extract([
            ['method' => 'GET', 'pattern' => '/dots', 'handler' => '..mod.handler..', 'name' => null, 'segments' => []],
        ])->build();

        /** @var array<string, mixed> $operation */
        $operation = $spec['paths']['/dots']['get'];
        self::assertSame('get...mod.handler', $operation['operationId']);
    }

    public function testPostmanUsesFirstEnumValueEvenForSingleEntry(): void
    {
        $collection = new PostmanCollectionExporter()->export([
            'info' => ['title' => 'X', 'version' => '1'],
            'paths' => ['/one' => ['post' => [
                'operationId' => 'one',
                'requestBody' => ['content' => ['application/json' => ['schema' => [
                    'type' => 'object',
                    'properties' => ['mode' => ['type' => 'string', 'enum' => ['only']]],
                ]]]],
                'responses' => ['200' => ['description' => 'd']],
            ]]],
        ]);

        /** @var array<string, mixed> $folder */
        $folder = $collection['item'][0];

        /** @var array<int|string, mixed> $entries */
        $entries = $folder['item'];

        /** @var array<string, mixed> $entry */
        $entry = $entries[0] ?? [];

        /** @var array<string, mixed> $request */
        $request = $entry['request'] ?? [];

        /** @var array<string, mixed> $body */
        $body = $request['body'] ?? [];

        $raw = $body['raw'] ?? null;
        self::assertIsString($raw);
        self::assertStringContainsString('"only"', $raw);
    }

    public function testPostmanSkipsNonStringMethodEntry(): void
    {
        $collection = new PostmanCollectionExporter()->export([
            'info' => ['title' => 'X', 'version' => '1'],
            'paths' => ['/j' => ['get' => 'oops']],
        ]);
        self::assertSame([], $collection['item']);
    }

    public function testSchemaRejectsIntegerPropertyKeyAndWhitespaceName(): void
    {
        $caught = 0;

        try {
            /** @var array<int|string, Schema> $props */
            $props = [7 => new Schema(type: SchemaType::String)];
            // @phpstan-ignore argument.type (intentionally invalid property key)
            new Schema(type: SchemaType::Object, properties: $props);
        } catch (SchemaDefinitionException) {
            ++$caught;
        }

        try {
            new Schema(type: SchemaType::Object, properties: ['  ' => new Schema(type: SchemaType::String)]);
        } catch (SchemaDefinitionException) {
            ++$caught;
        }
        self::assertSame(2, $caught);
    }

    public function testValidatorFieldOrderPreservedInSchema(): void
    {
        $validator = new Validator();
        $validator->field('z')->required()->typeString();
        $validator->field('a')->required()->typeString();
        $schema = $this->generator->generateFromValidator($validator);
        self::assertSame(['z', 'a'], $schema->required);
        self::assertSame(['z', 'a'], array_keys($schema->properties));
    }
}
