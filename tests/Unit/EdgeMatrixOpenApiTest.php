<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.20.0 — Edge-matrix adversarial round for the OpenAPI
 * module: hostile inputs, pathological structures, and generator abuse
 * that the happy paths above never exercise.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Zef\Framework\OpenApi\Attribute\Property as PropertyAttr;
use Zef\Framework\OpenApi\Attribute\RequestBody as RequestBodyAttr;
use Zef\Framework\OpenApi\Attribute\Route as RouteAttr;
use Zef\Framework\OpenApi\Attribute\SecurityScheme as SecuritySchemeAttr;
use Zef\Framework\OpenApi\Info;
use Zef\Framework\OpenApi\JsonSpecificationSerializer;
use Zef\Framework\OpenApi\OpenApiSpecValidator;
use Zef\Framework\OpenApi\Operation;
use Zef\Framework\OpenApi\PostmanCollectionExporter;
use Zef\Framework\OpenApi\Response;
use Zef\Framework\OpenApi\RouteSpecExtractor;
use Zef\Framework\OpenApi\Schema;
use Zef\Framework\OpenApi\SchemaDefinitionException;
use Zef\Framework\OpenApi\SchemaGenerator;
use Zef\Framework\OpenApi\SchemaType;
use Zef\Framework\OpenApi\SecuritySchemeType;
use Zef\Framework\OpenApi\SpecificationBuilder;
use Zef\Framework\OpenApi\SpecificationException;
use Zef\Framework\OpenApi\YamlSpecificationSerializer;
use Zef\Framework\Validation\Validator;

final class OpenApiGeneratorDeepNestDto
{
    public ?OpenApiGeneratorDeepNestDto $self = null;

    public string $leafValue = '';
}

final class OpenApiGeneratorWeirdNamesDto
{
    public function __construct(
        #[PropertyAttr(pattern: '(a+)')]
        public string $unDelimitedPattern = '',
        #[PropertyAttr(pattern: 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx')]
        public string $tooLongPattern = ''
    ) {}
}

final class EdgeMatrixDoubleBodyHandler
{
    #[RouteAttr(method: 'POST', path: '/double')]
    #[RequestBodyAttr(schema: OpenApiExtractorCreateDto::class)]
    #[RequestBodyAttr(schema: OpenApiExtractorUserDto::class)]
    public function handle(): never
    {
        throw new \LogicException('Documentation fixture only.');
    }
}

#[SecuritySchemeAttr(name: 'jwt', type: SecuritySchemeType::Http, scheme: 'bearer', bearerFormat: 'JWT')]
final class EdgeMatrixJwtHandler {}

/**
 * @internal
 */
final class EdgeMatrixOpenApiTest extends TestCase
{
    public function testSelfReferentialDtoTerminatesWithRef(): void
    {
        $generator = new SchemaGenerator();
        $schema = $generator->generateFromClass(OpenApiGeneratorDeepNestDto::class);
        self::assertSame('#/components/schemas/OpenApiGeneratorDeepNestDto', $schema->properties['self']->ref);
    }

    public function testInvalidAttributePatternRejectedDuringGeneration(): void
    {
        $generator = new SchemaGenerator();
        $this->expectException(SchemaDefinitionException::class);
        $generator->generateFromClass(OpenApiGeneratorWeirdNamesDto::class);
    }

    public function testUndelimitedPatternAccepted(): void
    {
        $validator = new Validator();
        $validator->field('slug')->pattern('^[a-z]+$');
        $schema = new SchemaGenerator()->generateFromValidator($validator);
        self::assertSame('^[a-z]+$', $schema->properties['slug']->pattern);
    }

    public function testPatternWithEscapedDelimiterSurvivesHeuristic(): void
    {
        $validator = new Validator();
        $validator->field('quoted')->pattern('/^["\']+$/');
        $schema = new SchemaGenerator()->generateFromValidator($validator);
        // The naive heuristic splits at the LAST unescaped quote delimiter;
        // either way the result must be delimiter-free.
        $pattern = $schema->properties['quoted']->pattern ?? '';
        self::assertStringNotContainsString('//', $pattern);
    }

    public function testBuilderRejectsDuplicateOperationIdsAcrossRoutes(): void
    {
        $info = new Info(title: 'X', version: '1');
        $builder = new SpecificationBuilder($info);
        $op = static fn (string $id): Operation => new Operation(
            operationId: $id,
            method: 'GET',
            path: '/a',
            responses: ['200' => new Response('ok')],
        );
        $builder->addOperation($op('same'));
        $this->expectException(SpecificationException::class);
        $builder->addOperation($op('same'));
    }

    public function testBuilderRejectsSameNameDifferentSchema(): void
    {
        $builder = new SpecificationBuilder(new Info(title: 'X', version: '1'));
        $builder->addSchema('Thing', new Schema(type: SchemaType::String));
        $this->expectException(SpecificationException::class);
        $builder->addSchema('Thing', new Schema(type: SchemaType::Integer));
    }

    public function testBuilderAcceptsIdempotentSchemaRegistration(): void
    {
        $builder = new SpecificationBuilder(new Info(title: 'X', version: '1'));
        $schema = new Schema(type: SchemaType::String);
        $builder->addSchema('Thing', $schema);
        $builder->addSchema('Thing', $schema);
        $components = $builder->build()['components'] ?? [];
        self::assertIsArray($components);
        $schemas = $components['schemas'] ?? [];
        self::assertIsArray($schemas);
        self::assertSame(['type' => 'string'], $schemas['Thing'] ?? null);
    }

    public function testMethodOrderIsCanonicalRegardlessOfInsertion(): void
    {
        $builder = new SpecificationBuilder(new Info(title: 'X', version: '1'));
        foreach (['delete', 'post', 'put', 'get', 'patch'] as $index => $httpMethod) {
            $builder->addOperation(new Operation(
                operationId: 'op' . $index,
                method: $httpMethod,
                path: '/multi',
                responses: ['200' => new Response('ok')],
            ));
        }
        $paths = $builder->build()['paths']['/multi'];
        self::assertSame(['get', 'post', 'put', 'patch', 'delete'], array_keys($paths));
    }

    public function testPathsAreSortedRegardlessOfInsertion(): void
    {
        $builder = new SpecificationBuilder(new Info(title: 'X', version: '1'));
        foreach (['/zulu', '/alpha', '/mike'] as $index => $path) {
            $builder->addOperation(new Operation(
                operationId: 'op' . $index,
                method: 'GET',
                path: $path,
                responses: ['200' => new Response('ok')],
            ));
        }
        self::assertSame(['/alpha', '/mike', '/zulu'], array_keys($builder->build()['paths']));
    }

    public function testExtractorSurvivesHostileHandlerStrings(): void
    {
        $extractor = new RouteSpecExtractor(new Info(title: 'X', version: '1'));
        $spec = $extractor->extract([
            ['method' => 'GET', 'pattern' => '/weird/{seg}', 'handler' => 'App\Controller\User::show@v2 👾', 'name' => null, 'segments' => []],
        ])->build();

        /** @var array<string, mixed> $operation */
        $operation = $spec['paths']['/weird/{seg}']['get'];
        self::assertIsString($operation['operationId']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9._-]{1,128}$/', $operation['operationId']);
    }

    public function testExtractorHandlesMaximalConstraintPattern(): void
    {
        $extractor = new RouteSpecExtractor(new Info(title: 'X', version: '1'));
        $pattern = '/' . implode('/', array_fill(0, 50, '{p:int}'));
        $spec = $extractor->extract([
            ['method' => 'GET', 'pattern' => $pattern, 'handler' => 'h', 'name' => null, 'segments' => []],
        ])->build();
        $converted = '/' . implode('/', array_fill(0, 50, '{p}'));

        /** @var array<string, mixed> $operation */
        $operation = $spec['paths'][$converted]['get'];

        /** @var list<array<string, mixed>> $parameters */
        $parameters = $operation['parameters'];
        self::assertCount(50, $parameters);

        /** @var array<string, mixed> $lastParam */
        $lastParam = $parameters[49];

        /** @var array<string, mixed> $lastSchema */
        $lastSchema = $lastParam['schema'];
        self::assertSame('integer', $lastSchema['type']);
    }

    public function testDuplicatePathParameterNamesStillDocumentedOnceEach(): void
    {
        // Router rejects duplicates; the extractor must not crash on the
        // same shape arriving from external tooling.
        $extractor = new RouteSpecExtractor(new Info(title: 'X', version: '1'));
        $spec = $extractor->extract([
            ['method' => 'GET', 'pattern' => '/x/{a}/{a}', 'handler' => 'h', 'name' => null, 'segments' => []],
        ])->build();

        /** @var array<string, mixed> $dupOperation */
        $dupOperation = $spec['paths']['/x/{a}/{a}']['get'];

        /** @var list<array<string, mixed>> $dupParams */
        $dupParams = $dupOperation['parameters'];
        self::assertCount(2, $dupParams);
    }

    public function testUnicodeDescriptionsRoundtripThroughBothSerializers(): void
    {
        $text = 'Deskripsi 🎉 ünïcode — "quoted" \backslash\ newline
next line';
        $json = new JsonSpecificationSerializer();
        $yaml = new YamlSpecificationSerializer();

        /** @var array<string, string> $doc */
        $doc = ['text' => $text];
        $roundJson = $json->deserialize($json->serialize($doc));
        $yamlText = '';
        self::assertSame($text, $roundJson['text']);
        $yamlText = $yaml->serialize($doc);
        self::assertStringStartsWith('text: "', $yamlText);
        self::assertStringNotContainsString("\n", trim($yamlText));
        self::assertStringContainsString('\n', $yamlText);
    }

    public function testYamlQuotesNumericAndBooleanLookalikeKeys(): void
    {
        /** @var array<string, string> $lookalikes */
        $lookalikes = [
            '200' => 'status key',
            '1e5' => 'scientific',
            'on' => 'lookalike key',
            'N' => 'yaml1.1 bool',
        ];
        $yaml = new YamlSpecificationSerializer()->serialize($lookalikes);
        self::assertStringContainsString('"200": status key', $yaml);
        self::assertStringContainsString('"1e5": scientific', $yaml);
        self::assertStringContainsString('"on": lookalike key', $yaml);
        self::assertStringContainsString('"N": yaml1.1 bool', $yaml);
    }

    public function testPostmanHandlesEmptyAndWeirdSpecs(): void
    {
        $exporter = new PostmanCollectionExporter();
        $empty = $exporter->export([]);
        self::assertSame('ZEF API', $empty['info']['name']);
        self::assertSame([], $empty['item']);

        $weird = $exporter->export([
            'info' => ['title' => 'Weird'],
            'paths' => [
                '/ok' => ['get' => ['operationId' => 'ok', 'responses' => ['200' => ['description' => 'd']]]],
                'junk-key' => 'not-an-object',
                42 => ['delete' => 'not-an-object-either'],
            ],
        ]);
        self::assertCount(1, $weird['item']);
        self::assertSame('/ok', $weird['item'][0]['name']);
    }

    public function testPostmanAuthPrefersFirstSupportedScheme(): void
    {
        $exporter = new PostmanCollectionExporter();
        $collection = $exporter->export([
            'info' => ['title' => 'Auth', 'version' => '1'],
            'paths' => [],
            'components' => ['securitySchemes' => [
                'unknown-oauth' => ['type' => 'oauth2'],
                'key' => ['type' => 'apiKey', 'in' => 'header'],
                'bearer' => ['type' => 'http', 'scheme' => 'bearer'],
            ]],
        ]);

        /** @var array<string, mixed> $auth */
        $auth = $collection['auth'] ?? [];
        self::assertSame('apikey', $auth['type']);
    }

    public function testSpecValidatorToleratesGarbageShapes(): void
    {
        $validator = new OpenApiSpecValidator();
        $errors = $validator->validate([
            'openapi' => 3,
            'info' => 'nope',
            'paths' => [
                'bad' => 'worse',
                '/x' => ['brew' => 'cold', 'get' => 'not-even-an-object'],
            ],
            'components' => ['schemas' => ['ok-but' => 'not-object', '' => ['type' => 'object']]],
        ]);
        self::assertContains('Field "openapi" must be a semver string like "3.1.0".', $errors);
        self::assertContains('Field "info" must be an object.', $errors);
        self::assertContains("Path key 'bad' must be a non-empty string beginning with '/'.", $errors);
        self::assertContains("Path '/x' contains an unknown HTTP method 'brew'.", $errors);
        self::assertContains("Component schema 'ok-but' must be an object.", $errors);
    }

    public function testDeeplyNestedRefsDoNotExplodeValidator(): void
    {
        $validator = new OpenApiSpecValidator();
        $node = ['schema' => ['$ref' => '#/components/schemas/Nope']];
        for ($i = 0; $i < 100; ++$i) {
            $node = ['schema' => ['items' => $node]];
        }
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Deep', 'version' => '1'],
            'paths' => ['/d' => ['get' => ['operationId' => 'd', 'responses' => ['200' => ['description' => 'ok', 'content' => ['application/json' => $node]]]]]],
        ];
        $errors = $validator->validate($spec);
        self::assertContains("Unresolvable \$ref '#/components/schemas/Nope' (missing from components.schemas).", $errors);
    }

    public function testMultipleRequestBodyAttributesRejected(): void
    {
        $handlerClass = EdgeMatrixDoubleBodyHandler::class;
        $extractor = new RouteSpecExtractor(
            new Info(title: 'X', version: '1'),
            static fn (string $id): ?string => $id === 'h' ? $handlerClass : null,
        );
        $this->expectException(SpecificationException::class);
        $extractor->extract([
            ['method' => 'POST', 'pattern' => '/double', 'handler' => 'h', 'name' => null, 'segments' => []],
        ]);
    }

    public function testUnknownOperationIdCharsetFromRouteNameFallsBack(): void
    {
        $extractor = new RouteSpecExtractor(new Info(title: 'X', version: '1'));
        $spec = $extractor->extract([
            ['method' => 'GET', 'pattern' => '/x', 'handler' => 'h', 'name' => 'ข้อความไทย', 'segments' => []],
        ])->build();

        /** @var array<string, mixed> $thaiOperation */
        $thaiOperation = $spec['paths']['/x']['get'];
        self::assertIsString($thaiOperation['operationId']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9._-]{1,128}$/', $thaiOperation['operationId']);
    }

    public function testClassResolverReturningInterfaceIsIgnoredForInstantiation(): void
    {
        $extractor = new RouteSpecExtractor(
            new Info(title: 'X', version: '1'),
            static fn (string $handler): ?string => $handler === 'h' ? RequestHandlerInterface::class : null,
        );
        // An interface resolves but has no methods with attributes and no
        // handle() implementation — must not crash, just document defaults.
        $spec = $extractor->extract([
            ['method' => 'GET', 'pattern' => '/iface', 'handler' => 'h', 'name' => null, 'segments' => []],
        ])->build();

        /** @var array<string, mixed> $ifaceOperation */
        $ifaceOperation = $spec['paths']['/iface']['get'];

        /** @var array<int|string, mixed> $ifaceResponses */
        $ifaceResponses = $ifaceOperation['responses'];

        /** @var array<string, mixed> $iface200 */
        $iface200 = $ifaceResponses['200'];
        self::assertSame('Successful response.', $iface200['description']);
    }

    public function testBearerSchemeFromAttributesSurvivesValidation(): void
    {
        $handlerClass = EdgeMatrixJwtHandler::class;
        $extractor = new RouteSpecExtractor(
            new Info(title: 'X', version: '1'),
            static fn (string $id): ?string => $id === 'h' ? $handlerClass : null,
        );
        $spec = $extractor->extract([
            ['method' => 'GET', 'pattern' => '/jwt', 'handler' => 'h', 'name' => null, 'segments' => []],
        ])->build();
        $jwtComponents = $spec['components'] ?? [];
        self::assertIsArray($jwtComponents);
        $jwtSchemes = $jwtComponents['securitySchemes'] ?? [];
        self::assertIsArray($jwtSchemes);

        /** @var array<string, mixed> $jwtScheme */
        $jwtScheme = $jwtSchemes['jwt'] ?? [];
        self::assertSame('JWT', $jwtScheme['bearerFormat'] ?? null);
        $validator = new OpenApiSpecValidator();
        self::assertSame([], $validator->validate($spec));
    }
}
