<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.20.0 — Infection sweep for the OpenAPI module.
 * Each test kills a specific escaped-mutant family found by the chunked
 * mutation run: trim guards, loop-body removals, first-wins override
 * semantics, operation-id suffix arithmetic, and garbage-tolerance of
 * the Postman exporter.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Console\ConsoleIO;
use Zef\Framework\Http\ServerRequest;
use Zef\Framework\Http\Uri;
use Zef\Framework\OpenApi\Attribute\Deprecated as DeprecatedAttr;
use Zef\Framework\OpenApi\Attribute\OpenApi as OpenApiAttr;
use Zef\Framework\OpenApi\Attribute\Parameter as ParameterAttr;
use Zef\Framework\OpenApi\Attribute\Property;
use Zef\Framework\OpenApi\Attribute\Property as PropertyAttr;
use Zef\Framework\OpenApi\Attribute\RequestBody as RequestBodyAttr;
use Zef\Framework\OpenApi\Attribute\Response as ResponseAttr;
use Zef\Framework\OpenApi\Attribute\Route;
use Zef\Framework\OpenApi\Attribute\Route as RouteAttr;
use Zef\Framework\OpenApi\Attribute\Schema as SchemaAttr;
use Zef\Framework\OpenApi\Attribute\Security;
use Zef\Framework\OpenApi\Attribute\SecurityScheme as SecuritySchemeAttr;
use Zef\Framework\OpenApi\Attribute\Tag as TagAttr;
use Zef\Framework\OpenApi\Console\GenerateSpecCommand;
use Zef\Framework\OpenApi\Contact;
use Zef\Framework\OpenApi\Http\DocsUiHandler;
use Zef\Framework\OpenApi\Http\SpecHandler;
use Zef\Framework\OpenApi\Info;
use Zef\Framework\OpenApi\JsonSpecificationSerializer;
use Zef\Framework\OpenApi\License;
use Zef\Framework\OpenApi\OpenApiSpecValidator;
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
use Zef\Framework\OpenApi\SecurityRequirement;
use Zef\Framework\OpenApi\SecuritySchemeType;
use Zef\Framework\OpenApi\Server;
use Zef\Framework\OpenApi\SpecificationBuilder;
use Zef\Framework\OpenApi\SpecificationException;
use Zef\Framework\OpenApi\Tag;
use Zef\Framework\OpenApi\YamlSpecificationSerializer;
use Zef\Framework\Validation\Validator;

#[SchemaAttr(name: 'SweepNamedDto')]
final class OpenApiSweepNamedDto
{
    public string $id;
}

#[SchemaAttr(name: 'SweepZebraDto')]
final class OpenApiSweepZebraDto
{
    public string $z;
}

#[SchemaAttr(name: 'SweepAlphaDto')]
final class OpenApiSweepAlphaDto
{
    public string $a;
}

#[SchemaAttr(name: '   ')]
final class OpenApiSweepWhitespaceNamedDto
{
    public string $w;
}

#[SchemaAttr(name: 'SweepNamedEnum')]
enum OpenApiSweepNamedEnum: string
{
    case Hearts = 'hearts';
}

#[SchemaAttr(deprecated: true)]
final class OpenApiSweepDeprecatedDto
{
    public string $x;
}

final class OpenApiSweepBadConstraintDto
{
    #[PropertyAttr(minLength: -1)]
    public string $bad;
}

final class OpenApiSweepPlainDefaultsDto
{
    public string $withDefault = 'x';

    public int $required;
}

final class OpenApiSweepUntypedDto
{
    /**
     * Deliberately untyped: pins the generator's no-reflection-type path.
     *
     * @var mixed
     */
    public $loose; // phpcs:ignore SlevomatCodingStandard.TypeHints.PropertyTypeHint.MissingNativeTypeHint

    public int $n;
}

final class OpenApiSweepUnionDto
{
    public int|string $plain;

    public int|string|null $opt = null;
}

final class OpenApiSweepArrayDto
{
    /** @var array<string, mixed> */
    public array $meta;
}

#[SchemaAttr(name: 'SweepDefaultOverridden')]
final class OpenApiSweepDefaultOverrideDto
{
    #[PropertyAttr(default: 'attr-wins')]
    public string $withNative = 'native';
}

final class OpenApiSweepItemsDto
{
    /** @var list<mixed> */
    #[PropertyAttr(itemsType: SchemaType::Integer)]
    public array $tags;
}

final class OpenApiSweepInvokeHandler
{
    #[RouteAttr(method: 'GET', path: '/inv', summary: 'InvokeSweep')]
    public function __invoke(): never
    {
        throw new \LogicException('Documentation fixture only.');
    }
}

final class OpenApiSweepStaticHandler
{
    #[RouteAttr(method: 'GET', path: '/static', summary: 'Static summary')]
    public static function staticRoute(): never
    {
        throw new \LogicException('Documentation fixture only.');
    }

    #[RouteAttr(method: 'GET', path: '/real', summary: 'Real summary')]
    public function handle(): never
    {
        throw new \LogicException('Documentation fixture only.');
    }
}

final class OpenApiSweepDuplicateRouteHandler
{
    #[RouteAttr(method: 'GET', path: '/dup2', summary: 'First summary')]
    #[RouteAttr(method: 'GET', path: '/dup2', summary: 'Second summary')]
    #[ParameterAttr(name: 'X-Sweep', in: ParameterLocation::Header, description: 'sweep')]
    public function handle(): never
    {
        throw new \LogicException('Documentation fixture only.');
    }
}

final class OpenApiSweepNoRouteHandler
{
    #[ResponseAttr(status: 200, description: 'NoRoute Response')]
    public function handle(): never
    {
        throw new \LogicException('Documentation fixture only.');
    }
}

#[TagAttr(name: 'sweepClass')]
#[SecuritySchemeAttr(name: 'sweepLate', type: SecuritySchemeType::ApiKey, in: ParameterLocation::Query)]
final class OpenApiSweepLateSchemeHandler
{
    #[TagAttr(name: 'sweepMethod')]
    #[RouteAttr(method: 'GET', path: '/late', tags: ['sweepRouteOne', 'sweepRouteTwo'])]
    #[RouteAttr(method: 'POST', path: '/late')]
    #[RequestBodyAttr(schema: OpenApiSweepNamedDto::class)]
    #[ResponseAttr(status: 200, description: 'Late ok')]
    public function handle(): never
    {
        throw new \LogicException('Documentation fixture only.');
    }
}

#[SecuritySchemeAttr(name: 'sweepB', type: SecuritySchemeType::Http, scheme: 'digest')]
final class OpenApiSweepBSchemeHandler
{
    #[RouteAttr(method: 'GET', path: '/b')]
    #[RequestBodyAttr]
    public function handle(): never
    {
        throw new \LogicException('Documentation fixture only.');
    }
}

#[SecuritySchemeAttr(name: 'firstDoc', type: SecuritySchemeType::Http, scheme: 'bearer')]
#[OpenApiAttr(title: 'Sweep First', version: '1.0.1')]
final class OpenApiSweepDocFirstHandler
{
    #[RouteAttr(method: 'GET', path: '/f1')]
    public function handle(): never
    {
        throw new \LogicException('Documentation fixture only.');
    }
}

#[SecuritySchemeAttr(name: 'secondDoc', type: SecuritySchemeType::Http, scheme: 'basic')]
#[OpenApiAttr(title: 'Sweep Second', version: '2.0.2')]
final class OpenApiSweepDocSecondHandler
{
    #[RouteAttr(method: 'GET', path: '/f2')]
    public function handle(): never
    {
        throw new \LogicException('Documentation fixture only.');
    }
}

/**
 * @internal
 */
final class OpenApiInfectionSweepTest extends TestCase
{
    // ------------------------------------------------- domain VO guards

    public function testWhitespaceOnlyNamesAreRejected(): void
    {
        $caught = 0;
        $expecting = static function (callable $attempt) use (&$caught): void {
            try {
                $attempt();
            } catch (\InvalidArgumentException|SchemaDefinitionException) {
                ++$caught;

                return;
            }
            self::fail('Expected a validation exception for whitespace-only input.');
        };
        $expecting(static fn (): Tag => new Tag('  '));
        $expecting(static fn (): License => new License(name: '  '));
        $expecting(static fn (): TagAttr => new TagAttr('  '));
        $expecting(static fn (): Security => new Security(scheme: '  '));
        $expecting(static fn (): Parameter => new Parameter(name: '  ', in: ParameterLocation::Query));
        $expecting(static fn (): Schema => new Schema(type: SchemaType::Object, required: ['  ']));
        $expecting(static fn (): SecurityRequirement => new SecurityRequirement(['  ' => ['read']]));
        self::assertSame(7, $caught);
    }

    public function testSecurityRequirementJunkEntriesAreRejected(): void
    {
        $caught = 0;

        try {
            // @phpstan-ignore argument.type (intentionally invalid scheme key)
            new SecurityRequirement([7 => ['read']]);
        } catch (SchemaDefinitionException) {
            ++$caught;
        }

        try {
            // @phpstan-ignore argument.type (intentionally invalid scope value)
            new SecurityRequirement(['bearer' => [42]]);
        } catch (SchemaDefinitionException) {
            ++$caught;
        }
        self::assertSame(2, $caught);
    }

    public function testServerRejectsWhitespaceVariableKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Server('https://api', 'd', ['  ' => 'x']);
    }

    public function testContactWithNameOnlyIsValidAndEmptyIsRejected(): void
    {
        $contact = new Contact(name: 'Zef Team');
        self::assertSame('Zef Team', $contact->toArray()['name']);
        $this->expectException(\InvalidArgumentException::class);
        new Contact();
    }

    public function testSchemaAcceptsMultibytePropertyNameUnderCharLimit(): void
    {
        $name = str_repeat('é', 65); // 130 bytes, 65 characters
        $schema = new Schema(type: SchemaType::Object, properties: [$name => new Schema(type: SchemaType::String)]);
        self::assertArrayHasKey($name, $schema->properties);
    }

    public function testSchemaNumericConstraintsAreSerialized(): void
    {
        $schema = new Schema(type: SchemaType::String, minLength: 3, maxLength: 12);
        $out = $schema->toArray();
        self::assertSame(3, $out['minLength'] ?? null);
        self::assertSame(12, $out['maxLength'] ?? null);
    }

    public function testOperationRejectsHostileOperationId(): void
    {
        $this->expectException(SchemaDefinitionException::class);
        new Operation(operationId: '日本-ok', method: 'GET', path: '/x', responses: ['200' => new Response('ok')]);
    }

    public function testOperationResponseKeysAreStrings(): void
    {
        $operation = new Operation(operationId: 'op', method: 'GET', path: '/x', responses: ['200' => new Response('ok')]);
        $responses = $operation->toArray()['responses'];
        self::assertArrayHasKey(200, $responses);
    }

    public function testRequestBodyDefaultIsNotRequired(): void
    {
        $body = new RequestBody(content: ['application/json' => new Schema(type: SchemaType::String)]);
        self::assertFalse($body->required);
    }

    public function testAttributeDefaultsRemainFalse(): void
    {
        self::assertFalse(new ParameterAttr(name: 'p')->deprecated);
        self::assertFalse(new Property()->readOnly);
        self::assertFalse(new Property()->writeOnly);
        self::assertFalse(new RequestBodyAttr()->required);
        self::assertFalse(new Route()->deprecated);
        self::assertFalse(new SchemaAttr()->deprecated);
        self::assertSame('', new DeprecatedAttr()->description);
    }

    // ------------------------------------------------- postman exporter

    public function testPostmanSkipsJunkMethodButKeepsValidSibling(): void
    {
        $collection = new PostmanCollectionExporter()->export([
            'info' => ['title' => 'X', 'version' => '1'],
            'paths' => ['/x' => ['get' => 'oops', 'post' => ['responses' => ['200' => ['description' => 'd']]]]],
        ]);
        self::assertCount(1, $collection['item']);

        /** @var array<string, mixed> $folder */
        $folder = $collection['item'][0];

        /** @var array<int|string, mixed> $entries */
        $entries = $folder['item'];

        /** @var array<string, mixed> $entry */
        $entry = $entries[0] ?? [];
        self::assertSame('POST /x', $entry['name'] ?? null);
    }

    public function testPostmanSkipsNonArrayMediaContent(): void
    {
        $collection = new PostmanCollectionExporter()->export([
            'info' => ['title' => 'X', 'version' => '1'],
            'paths' => ['/j' => ['post' => [
                'operationId' => 'j',
                'requestBody' => ['content' => ['application/json' => 'oops']],
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
        self::assertSame('{}', $raw);
    }

    public function testPostmanNonArrayEnumFallsBackToTypeExample(): void
    {
        $collection = new PostmanCollectionExporter()->export([
            'info' => ['title' => 'X', 'version' => '1'],
            'paths' => ['/e' => ['post' => [
                'operationId' => 'e',
                'requestBody' => ['content' => ['application/json' => ['schema' => [
                    'type' => 'string',
                    'enum' => 'oops',
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
        self::assertStringContainsString('"string"', $raw);
    }

    public function testPostmanSkipsJunkPropertyEntries(): void
    {
        $collection = new PostmanCollectionExporter()->export([
            'info' => ['title' => 'X', 'version' => '1'],
            'paths' => ['/p' => ['post' => [
                'operationId' => 'p',
                'requestBody' => ['content' => ['application/json' => ['schema' => [
                    'type' => 'object',
                    'properties' => ['ok' => ['type' => 'string'], 7 => 'junk'],
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
        self::assertStringContainsString('"ok":"string"', $raw);
    }

    public function testUnknownHandlerClassStringIsTolerated(): void
    {
        $spec = $this->buildSpec([['method' => 'GET', 'pattern' => '/u', 'handler' => 'h', 'name' => null, 'segments' => []]], static fn (string $id): ?string => $id === 'h' ? 'Zef\Sweep\Void' : null);

        /** @var array<string, mixed> $operation */
        $operation = $spec['paths']['/u']['get'];

        /** @var array<string, mixed> $responses */
        $responses = $operation['responses'] ?? [];

        /** @var array<string, mixed> $response200 */
        $response200 = $responses['200'] ?? [];
        self::assertSame('Successful response.', $response200['description'] ?? null);
        self::assertSame('get.h', $operation['operationId'] ?? null);
    }

    public function testSchemesCollectedAfterUnresolvableRoute(): void
    {
        $spec = $this->buildSpec([
            ['method' => 'GET', 'pattern' => '/u', 'handler' => 'unknown.service', 'name' => null, 'segments' => []],
            ['method' => 'GET', 'pattern' => '/late', 'handler' => 'late', 'name' => null, 'segments' => []],
        ], static fn (string $id): ?string => match ($id) {
            'late' => OpenApiSweepLateSchemeHandler::class,
            default => null,
        });

        /** @var array<string, mixed> $components */
        $components = $spec['components'] ?? [];

        /** @var array<string, mixed> $schemes */
        $schemes = $components['securitySchemes'] ?? [];
        self::assertArrayHasKey('sweepLate', $schemes);
    }

    public function testSchemeCollectedAfterRepeatedClass(): void
    {
        $spec = $this->buildSpec([
            ['method' => 'GET', 'pattern' => '/x1', 'handler' => 'late', 'name' => null, 'segments' => []],
            ['method' => 'GET', 'pattern' => '/x2', 'handler' => 'late', 'name' => null, 'segments' => []],
            ['method' => 'GET', 'pattern' => '/z', 'handler' => 'bee', 'name' => null, 'segments' => []],
        ], static fn (string $id): ?string => match ($id) {
            'late' => OpenApiSweepLateSchemeHandler::class,
            'bee' => OpenApiSweepBSchemeHandler::class,
            default => null,
        });

        /** @var array<string, mixed> $components */
        $components = $spec['components'] ?? [];

        /** @var array<string, mixed> $schemes */
        $schemes = $components['securitySchemes'] ?? [];
        self::assertArrayHasKey('sweepLate', $schemes);
        self::assertArrayHasKey('sweepB', $schemes);
    }

    public function testFirstDocumentInfoOverrideWins(): void
    {
        $spec = $this->buildSpec([
            ['method' => 'GET', 'pattern' => '/f1', 'handler' => 'one', 'name' => null, 'segments' => []],
            ['method' => 'GET', 'pattern' => '/f2', 'handler' => 'two', 'name' => null, 'segments' => []],
        ], static fn (string $id): ?string => match ($id) {
            'one' => OpenApiSweepDocFirstHandler::class,
            'two' => OpenApiSweepDocSecondHandler::class,
            default => null,
        });

        /** @var array<string, mixed> $info */
        $info = $spec['info'] ?? [];
        self::assertSame('Sweep First', $info['title'] ?? null);
    }

    public function testHostileRouteNamesFallBackToDerivedIds(): void
    {
        $spec = $this->buildSpec([
            ['method' => 'GET', 'pattern' => '/a', 'handler' => 'api.user', 'name' => '日本-ok', 'segments' => []],
            ['method' => 'GET', 'pattern' => '/b', 'handler' => 'api.user', 'name' => 'ok-日本', 'segments' => []],
        ], static fn (string $id): ?string => $id === 'api.user' ? OpenApiSweepLateSchemeHandler::class : null);

        /** @var array<string, mixed> $operationA */
        $operationA = $spec['paths']['/a']['get'];

        /** @var array<string, mixed> $operationB */
        $operationB = $spec['paths']['/b']['get'];
        $operationIdA = $operationA['operationId'] ?? '';
        $operationIdB = $operationB['operationId'] ?? '';
        self::assertIsString($operationIdA);
        self::assertIsString($operationIdB);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9._-]{1,128}$/', $operationIdA);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9._-]{1,128}$/', $operationIdB);
    }

    public function testConstraintDescriptionIsDocumented(): void
    {
        $spec = $this->buildSpec([
            ['method' => 'GET', 'pattern' => '/i/{id:int}', 'handler' => 'h', 'name' => null,
                'segments' => [['dynamic' => true, 'name' => 'id', 'constraint' => 'int']]],
        ]);

        /** @var array<string, mixed> $operation */
        $operation = $spec['paths']['/i/{id}']['get'];

        /** @var list<array<string, mixed>> $parameters */
        $parameters = $operation['parameters'] ?? [];
        self::assertSame("Path parameter constrained by 'int'.", $parameters[0]['description'] ?? null);
    }

    public function testOperationIdCollisionSuffixesAreSequential(): void
    {
        $spec = $this->buildSpec([
            ['method' => 'GET', 'pattern' => '/a', 'handler' => 'mod.one', 'name' => null, 'segments' => []],
            ['method' => 'GET', 'pattern' => '/b', 'handler' => 'mod.one', 'name' => null, 'segments' => []],
            ['method' => 'GET', 'pattern' => '/c', 'handler' => 'mod.one', 'name' => null, 'segments' => []],
        ]);

        /** @var array<string, mixed> $operationA */
        $operationA = $spec['paths']['/a']['get'];

        /** @var array<string, mixed> $operationB */
        $operationB = $spec['paths']['/b']['get'];

        /** @var array<string, mixed> $operationC */
        $operationC = $spec['paths']['/c']['get'];
        self::assertSame('get.mod.one', $operationA['operationId'] ?? null);
        self::assertSame('get.mod.one.2', $operationB['operationId'] ?? null);
        self::assertSame('get.mod.one.3', $operationC['operationId'] ?? null);
    }

    public function testOperationsWithoutDeprecatedAttributeAreNotDeprecated(): void
    {
        $spec = $this->buildSpec([
            ['method' => 'GET', 'pattern' => '/plain', 'handler' => 'anon', 'name' => null, 'segments' => []],
            ['method' => 'GET', 'pattern' => '/late', 'handler' => 'late', 'name' => null, 'segments' => []],
        ], static fn (string $id): ?string => $id === 'late' ? OpenApiSweepLateSchemeHandler::class : null);

        /** @var array<string, mixed> $plain */
        $plain = $spec['paths']['/plain']['get'];

        /** @var array<string, mixed> $late */
        $late = $spec['paths']['/late']['get'];
        self::assertSame(false, $plain['deprecated'] ?? false);
        self::assertSame(false, $late['deprecated'] ?? false);
    }

    public function testInvokeOnlyHandlerSummaryIsDocumented(): void
    {
        $spec = $this->buildSpec([['method' => 'GET', 'pattern' => '/inv', 'handler' => 'h', 'name' => null, 'segments' => []]], static fn (string $id): ?string => $id === 'h' ? OpenApiSweepInvokeHandler::class : null);

        /** @var array<string, mixed> $operation */
        $operation = $spec['paths']['/inv']['get'];
        self::assertSame('InvokeSweep', $operation['summary'] ?? null);
    }

    public function testStaticRouteMethodsAreIgnored(): void
    {
        $spec = $this->buildSpec([
            ['method' => 'GET', 'pattern' => '/static', 'handler' => 'h', 'name' => null, 'segments' => []],
            ['method' => 'GET', 'pattern' => '/real', 'handler' => 'h', 'name' => null, 'segments' => []],
        ], static fn (string $id): ?string => $id === 'h' ? OpenApiSweepStaticHandler::class : null);

        /** @var array<string, mixed> $staticOperation */
        $staticOperation = $spec['paths']['/static']['get'];

        /** @var array<string, mixed> $realOperation */
        $realOperation = $spec['paths']['/real']['get'];
        self::assertArrayNotHasKey('summary', $staticOperation);
        self::assertSame('Real summary', $realOperation['summary'] ?? null);
    }

    public function testDuplicateRouteAttributesProduceSingleOperation(): void
    {
        $spec = $this->buildSpec([['method' => 'GET', 'pattern' => '/dup2', 'handler' => 'h', 'name' => null, 'segments' => []]], static fn (string $id): ?string => $id === 'h' ? OpenApiSweepDuplicateRouteHandler::class : null);

        /** @var array<string, mixed> $operation */
        $operation = $spec['paths']['/dup2']['get'];
        self::assertSame('Second summary', $operation['summary'] ?? null);

        /** @var list<array<string, mixed>> $parameters */
        $parameters = $operation['parameters'] ?? [];
        self::assertCount(1, $parameters);
    }

    public function testDefaultHandlerMethodResponsesStillApply(): void
    {
        $spec = $this->buildSpec([['method' => 'GET', 'pattern' => '/n', 'handler' => 'h', 'name' => null, 'segments' => []]], static fn (string $id): ?string => $id === 'h' ? OpenApiSweepNoRouteHandler::class : null);

        /** @var array<string, mixed> $operation */
        $operation = $spec['paths']['/n']['get'];

        /** @var array<string, mixed> $responses */
        $responses = $operation['responses'] ?? [];

        /** @var array<string, mixed> $response200 */
        $response200 = $responses['200'] ?? [];
        self::assertSame('NoRoute Response', $response200['description'] ?? null);
    }

    public function testRouteTagsAreAllPreserved(): void
    {
        $spec = $this->buildSpec([['method' => 'GET', 'pattern' => '/late', 'handler' => 'late', 'name' => null, 'segments' => []]], static fn (string $id): ?string => $id === 'late' ? OpenApiSweepLateSchemeHandler::class : null);

        /** @var array<string, mixed> $operation */
        $operation = $spec['paths']['/late']['get'];

        /** @var list<mixed> $tags */
        $tags = $operation['tags'] ?? [];
        self::assertContains('sweepRouteOne', $tags);
        self::assertContains('sweepRouteTwo', $tags);
        self::assertContains('sweepMethod', $tags);
        self::assertContains('sweepClass', $tags);
    }

    public function testRequestBodySchemaRefAndComponentAreRegistered(): void
    {
        $spec = $this->buildSpec([['method' => 'POST', 'pattern' => '/late', 'handler' => 'late', 'name' => null, 'segments' => []]], static fn (string $id): ?string => $id === 'late' ? OpenApiSweepLateSchemeHandler::class : null);

        /** @var array<string, mixed> $operation */
        $operation = $spec['paths']['/late']['post'];

        /** @var array<string, mixed> $requestBody */
        $requestBody = $operation['requestBody'] ?? [];

        /** @var array<string, mixed> $content */
        $content = $requestBody['content'] ?? [];

        /** @var array<string, mixed> $media */
        $media = $content['application/json'] ?? [];

        /** @var array<string, mixed> $schema */
        $schema = $media['schema'] ?? [];
        self::assertSame('#/components/schemas/SweepNamedDto', $schema['$ref'] ?? null);

        /** @var array<string, mixed> $components */
        $components = $spec['components'] ?? [];

        /** @var array<string, mixed> $schemas */
        $schemas = $components['schemas'] ?? [];
        self::assertArrayHasKey('SweepNamedDto', $schemas);
    }

    public function testSchemaLessRequestBodyDefaultsToObjectContent(): void
    {
        $spec = $this->buildSpec([['method' => 'GET', 'pattern' => '/b', 'handler' => 'bee', 'name' => null, 'segments' => []]], static fn (string $id): ?string => $id === 'bee' ? OpenApiSweepBSchemeHandler::class : null);

        /** @var array<string, mixed> $operation */
        $operation = $spec['paths']['/b']['get'];

        /** @var array<string, mixed> $requestBody */
        $requestBody = $operation['requestBody'] ?? [];

        /** @var array<string, mixed> $content */
        $content = $requestBody['content'] ?? [];

        /** @var array<string, mixed> $media */
        $media = $content['application/json'] ?? [];

        /** @var array<string, mixed> $schema */
        $schema = $media['schema'] ?? [];
        self::assertSame('object', $schema['type'] ?? null);
        self::assertArrayNotHasKey('$ref', $schema);
    }

    public function testResponseAttributeStatusKeysAreStrings(): void
    {
        $spec = $this->buildSpec([['method' => 'GET', 'pattern' => '/late', 'handler' => 'late', 'name' => null, 'segments' => []]], static fn (string $id): ?string => $id === 'late' ? OpenApiSweepLateSchemeHandler::class : null);

        /** @var array<string, mixed> $operation */
        $operation = $spec['paths']['/late']['get'];

        /** @var array<int|string, mixed> $responses */
        $responses = $operation['responses'] ?? [];

        /** @var array<string, mixed> $response200 */
        $response200 = $responses[200] ?? [];
        self::assertSame('Late ok', $response200['description'] ?? null);
    }

    // ------------------------------------------------- schema generator

    public function testSchemaNameHonoursDeclaredAttributeForClassAndEnum(): void
    {
        $generator = new SchemaGenerator();
        self::assertSame('SweepNamedDto', $generator->schemaNameFor(OpenApiSweepNamedDto::class));
        self::assertSame('SweepNamedEnum', $generator->schemaNameFor(OpenApiSweepNamedEnum::class));
        self::assertSame('OpenApiSweepBadConstraintDto', $generator->schemaNameFor(OpenApiSweepBadConstraintDto::class));
    }

    public function testWhitespaceSchemaNameFallsBackToShortName(): void
    {
        $generator = new SchemaGenerator();
        self::assertSame('OpenApiSweepWhitespaceNamedDto', $generator->schemaNameFor(OpenApiSweepWhitespaceNamedDto::class));
    }

    public function testRegisteredSchemasAreSortedByName(): void
    {
        $generator = new SchemaGenerator();
        $generator->generateFromClass(OpenApiSweepZebraDto::class);
        $generator->generateFromClass(OpenApiSweepAlphaDto::class);
        self::assertSame(['SweepAlphaDto', 'SweepZebraDto'], array_keys($generator->registeredSchemas()));
    }

    public function testFailedGenerationLeavesNoStuckCircularGuard(): void
    {
        $generator = new SchemaGenerator();

        try {
            $generator->generateFromClass(OpenApiSweepBadConstraintDto::class);
            self::fail('Expected the invalid constraint to be rejected.');
        } catch (SchemaDefinitionException) {
            // first attempt must fail
        }

        try {
            $generator->generateFromClass(OpenApiSweepBadConstraintDto::class);
            self::fail('Expected the second attempt to fail as well.');
        } catch (SchemaDefinitionException) {
            // second attempt must fail too (guard was released)
        }
        $healthy = new SchemaGenerator();
        $schema = $healthy->generateFromClass(OpenApiSweepNamedDto::class);
        self::assertSame(SchemaType::Object, $schema->type);
    }

    public function testPlainDefaultValuedPropertyIsNotRequired(): void
    {
        $schema = new SchemaGenerator()->generateFromClass(OpenApiSweepPlainDefaultsDto::class);
        self::assertSame(['required'], $schema->required);
    }

    public function testUntypedPropertyIsDocumentedWithoutCrash(): void
    {
        $schema = new SchemaGenerator()->generateFromClass(OpenApiSweepUntypedDto::class);
        self::assertArrayHasKey('loose', $schema->properties);
        self::assertSame(['n'], $schema->required);
    }

    public function testClassDeprecationFlowsIntoSchema(): void
    {
        $schema = new SchemaGenerator()->generateFromClass(OpenApiSweepDeprecatedDto::class);
        self::assertTrue($schema->deprecated);
        self::assertTrue($schema->toArray()['deprecated'] ?? false);

        $plain = new SchemaGenerator()->generateFromClass(OpenApiSweepPlainDefaultsDto::class);
        self::assertArrayNotHasKey('deprecated', $plain->toArray());
    }

    public function testArrayItemsAcceptAdditionalProperties(): void
    {
        $schema = new SchemaGenerator()->generateFromClass(OpenApiSweepArrayDto::class);

        /** @var Schema $items */
        $items = $schema->properties['meta']->items;
        self::assertTrue($items->additionalPropertiesAllowed);
    }

    public function testNonNullableUnionOmitsNullableFlag(): void
    {
        $schema = new SchemaGenerator()->generateFromClass(OpenApiSweepUnionDto::class);

        /** @var Schema $plain */
        $plain = $schema->properties['plain'];
        self::assertArrayNotHasKey('nullable', $plain->toArray());
        self::assertNotSame(null, $plain->oneOf);

        /** @var Schema $opt */
        $opt = $schema->properties['opt'];
        self::assertTrue($opt->nullable);
        self::assertSame(true, $opt->toArray()['nullable'] ?? null);
    }

    public function testAttributeDefaultOverridesNativeDefault(): void
    {
        $schema = new SchemaGenerator()->generateFromClass(OpenApiSweepDefaultOverrideDto::class);

        /** @var Schema $property */
        $property = $schema->properties['withNative'];
        self::assertSame('attr-wins', $property->default);
    }

    public function testAttributeItemsTypeOverridesDefaultArrayItems(): void
    {
        $schema = new SchemaGenerator()->generateFromClass(OpenApiSweepItemsDto::class);

        /** @var Schema $property */
        $property = $schema->properties['tags'];

        /** @var Schema $items */
        $items = $property->items;
        self::assertSame(SchemaType::Integer, $items->type);
    }

    // ------------------------------------------------- validator bridge

    public function testValidatorLastNumericBoundWins(): void
    {
        $validator = new Validator();
        $validator->field('qty')->required()->typeInt()->min(3)->min(5)->max(20);
        $schema = new SchemaGenerator()->generateFromValidator($validator);

        /** @var Schema $qty */
        $qty = $schema->properties['qty'];
        self::assertSame(5, $qty->minimum);
        self::assertSame(20, $qty->maximum);
    }

    public function testValidatorInEnumKeepsOnlyStringAndIntValues(): void
    {
        $validator = new Validator();
        $validator->field('mode')->required()->in(['alpha', 'beta', 7]);
        $schema = new SchemaGenerator()->generateFromValidator($validator);

        /** @var Schema $mode */
        $mode = $schema->properties['mode'];
        self::assertSame(['alpha', 'beta', 7], $mode->enum);
    }

    public function testValidatorNullableFlowsIntoSchema(): void
    {
        $nullable = new Validator();
        $nullable->field('opt')->typeString()->nullable();
        $schema = new SchemaGenerator()->generateFromValidator($nullable);
        self::assertTrue($schema->properties['opt']->nullable);

        $strict = new Validator();
        $strict->field('req')->typeString();
        $schemaStrict = new SchemaGenerator()->generateFromValidator($strict);
        self::assertNull($schemaStrict->properties['req']->nullable);
        self::assertArrayNotHasKey('nullable', $schemaStrict->properties['req']->toArray());
    }

    // ------------------------------------- adapters + infrastructure (B)

    public function testGenerateAcceptsUppercaseFormat(): void
    {
        $io = new ConsoleIO();
        $base = sys_get_temp_dir() . '/zef-openapi-sweep-' . uniqid('', true);
        $command = new GenerateSpecCommand();
        $exit = $command->run($io, [
            ['method' => 'GET', 'pattern' => '/x', 'handler' => 'h', 'name' => null, 'segments' => []],
        ], null, ['output' => $base . '.json', 'format' => 'JSON']);
        self::assertSame(0, $exit);
        self::assertFileExists($base . '.json');
        unlink($base . '.json');
    }

    public function testGenerateFallsBackToDefaultOutputName(): void
    {
        $cwd = getcwd();
        $work = sys_get_temp_dir() . '/zef-openapi-sweep-cwd-' . uniqid('', true);
        mkdir($work);
        chdir($work);

        try {
            $io = new ConsoleIO();
            $command = new GenerateSpecCommand();
            $exit = $command->run($io, [
                ['method' => 'GET', 'pattern' => '/x', 'handler' => 'h', 'name' => null, 'segments' => []],
            ]);
            self::assertSame(0, $exit);
            self::assertFileExists($work . '/openapi.json');

            $io2 = new ConsoleIO();
            $command2 = new GenerateSpecCommand();
            $exit2 = $command2->run($io2, [
                ['method' => 'GET', 'pattern' => '/x', 'handler' => 'h', 'name' => null, 'segments' => []],
            ], null, ['output' => 0]);
            self::assertSame(0, $exit2);
            self::assertFileExists($work . '/openapi.json');
        } finally {
            chdir((string) $cwd);
            foreach (['openapi.json', '0'] as $junk) {
                if (is_file($work . '/' . $junk)) {
                    unlink($work . '/' . $junk);
                }
            }
            rmdir($work);
        }
    }

    public function testGeneratePrettyIsTheDefault(): void
    {
        $io = new ConsoleIO();
        $base = sys_get_temp_dir() . '/zef-openapi-sweep-' . uniqid('', true);
        $command = new GenerateSpecCommand();
        $exit = $command->run($io, [
            ['method' => 'GET', 'pattern' => '/x', 'handler' => 'h', 'name' => null, 'segments' => []],
        ], null, ['output' => $base . '.json']);
        self::assertSame(0, $exit);
        $content = (string) file_get_contents($base . '.json');
        self::assertStringContainsString('"openapi": "3.1.0"', $content);
        unlink($base . '.json');
    }

    public function testGenerateReportsWrittenFileMessage(): void
    {
        $io = new ConsoleIO();
        $base = sys_get_temp_dir() . '/zef-openapi-sweep-' . uniqid('', true);
        $command = new GenerateSpecCommand();
        $exit = $command->run($io, [
            ['method' => 'GET', 'pattern' => '/x', 'handler' => 'h', 'name' => null, 'segments' => []],
        ], null, ['output' => $base . '.json']);
        self::assertSame(0, $exit);
        self::assertMatchesRegularExpression(
            '/^OpenAPI specification written: ' . preg_quote($base . '.json', '/') . ' \(\d+ bytes\)$/',
            implode("\n", $io->outLog()),
        );
        unlink($base . '.json');
    }

    public function testGeneratePostmanMessageAndPrettyCollection(): void
    {
        $io = new ConsoleIO();
        $base = sys_get_temp_dir() . '/zef-openapi-sweep-' . uniqid('', true);
        $command = new GenerateSpecCommand();
        $exit = $command->run($io, [
            ['method' => 'GET', 'pattern' => '/x', 'handler' => 'h', 'name' => null, 'segments' => []],
        ], null, ['output' => $base . '.json', 'postman' => $base . '-postman.json']);
        self::assertSame(0, $exit);
        self::assertMatchesRegularExpression(
            '/Postman collection written: ' . preg_quote($base . '-postman.json', '/') . ' \(\d+ bytes\)/',
            implode("\n", $io->outLog()),
        );
        $postman = (string) file_get_contents($base . '-postman.json');
        self::assertStringContainsString('"info": {', $postman);
        unlink($base . '.json');
        unlink($base . '-postman.json');
    }

    public function testSpecHandlerEtagIsQuotedSha256(): void
    {
        $handler = new SpecHandler($this->adapterSpec());
        $response = $handler->handle(new ServerRequest('GET', new Uri('https://api.local/o')));
        self::assertMatchesRegularExpression('/^"[0-9a-f]{64}"$/', $response->getHeaderLine('ETag'));
    }

    public function testSpecHandler304CarriesEtagHeader(): void
    {
        $handler = new SpecHandler($this->adapterSpec());
        $first = $handler->handle(new ServerRequest('GET', new Uri('https://api.local/o')));
        $second = $handler->handle(new ServerRequest('GET', new Uri('https://api.local/o'), headers: ['If-None-Match' => $first->getHeaderLine('ETag')]));
        self::assertSame(304, $second->getStatusCode());
        self::assertSame($first->getHeaderLine('ETag'), $second->getHeaderLine('ETag'));
    }

    public function testDocsUiResponsesCarryContentType(): void
    {
        $handler = new DocsUiHandler();
        $response = $handler->handle(new ServerRequest('GET', new Uri('https://api.local/docs')));
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));

        $disabled = new DocsUiHandler(enabled: false);
        $notFound = $disabled->handle(new ServerRequest('GET', new Uri('https://api.local/docs')));
        self::assertSame('text/plain; charset=utf-8', $notFound->getHeaderLine('Content-Type'));
    }

    public function testDocsUiTitleQuotesAreEscaped(): void
    {
        $handler = new DocsUiHandler(title: 'Docs "Q" & <T>');
        $body = (string) $handler->handle(new ServerRequest('GET', new Uri('https://api.local/docs')))->getBody();
        self::assertStringContainsString('&quot;Q&quot;', $body);
    }

    public function testJsonSerializerIsPrettyByDefault(): void
    {
        $json = new JsonSpecificationSerializer();
        self::assertStringContainsString('"openapi": "3.1.0"', $json->serialize(['openapi' => '3.1.0']));
        self::assertSame('{"openapi":"3.1.0"}', $json->serialize(['openapi' => '3.1.0'], false));
    }

    public function testYamlSerializesEmptyListItems(): void
    {
        $yaml = new YamlSpecificationSerializer();
        self::assertSame("x:\n  - []\n  - \"y\"\n", $yaml->serialize(['x' => [[], 'y']]));
    }

    public function testYamlIndentsNestedListItems(): void
    {
        $yaml = new YamlSpecificationSerializer();
        self::assertSame("x:\n  - \"0\": deep\n", $yaml->serialize(['x' => [['deep']]]));
    }

    public function testValidatorRejectsNonObjectPathEntry(): void
    {
        $validator = new OpenApiSpecValidator();
        $errors = $validator->validate([
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1'],
            'paths' => ['/x' => 'junk'],
        ]);
        self::assertSame(["Path '/x' must map to an object of operations."], $errors);
    }

    public function testValidatorRejectsUnknownHttpMethod(): void
    {
        $validator = new OpenApiSpecValidator();
        $errors = $validator->validate([
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1'],
            'paths' => ['/x' => ['purge' => ['operationId' => 't', 'responses' => ['200' => ['description' => 'ok']]]]],
        ]);
        self::assertSame(["Path '/x' contains an unknown HTTP method 'purge'."], $errors);
    }

    public function testValidatorAcceptsValidParameterLocation(): void
    {
        $validator = new OpenApiSpecValidator();
        $errors = $validator->validate([
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1'],
            'paths' => ['/x' => ['get' => ['operationId' => 'getX', 'responses' => ['200' => ['description' => 'ok']], 'parameters' => [
                ['name' => 'q', 'in' => 'query', 'required' => true],
            ]]]],
        ]);
        self::assertSame([], $errors);
    }

    public function testValidatorRejectsNonStringSchemeName(): void
    {
        $validator = new OpenApiSpecValidator();
        $errors = $validator->validate([
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1'],
            'paths' => [],
            'components' => ['securitySchemes' => [42 => 'junk']],
        ]);
        self::assertSame(['Security scheme names must be non-empty strings.'], $errors);
    }

    public function testValidatorRejectsNonObjectScheme(): void
    {
        $validator = new OpenApiSpecValidator();
        $errors = $validator->validate([
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1'],
            'paths' => [],
            'components' => ['securitySchemes' => ['ok' => 'junk']],
        ]);
        self::assertSame(["Security scheme 'ok' must be an object with a type."], $errors);
    }

    public function testValidatorVersionMustBeSemver(): void
    {
        $validator = new OpenApiSpecValidator();
        self::assertSame(['Field "openapi" must be a semver string like "3.1.0".'], $validator->validate($this->validSpec(['openapi' => 'a3.1.0'])));
        self::assertSame(['Field "openapi" must be a semver string like "3.1.0".'], $validator->validate($this->validSpec(['openapi' => '3.1.0a'])));
        self::assertSame(['Field "openapi" must be a semver string like "3.1.0".'], $validator->validate($this->validSpec(['openapi' => 31])));
    }

    public function testValidatorInfoFieldsMustBeNonEmpty(): void
    {
        $validator = new OpenApiSpecValidator();
        self::assertSame(
            ['Field "info.title" must be a non-empty string.'],
            $validator->validate($this->validSpec(['info' => ['title' => '  ', 'version' => '1']])),
        );
        self::assertSame(
            ['Field "info.version" must be a non-empty string.'],
            $validator->validate($this->validSpec(['info' => ['title' => 'T', 'version' => '  ']])),
        );
        self::assertSame(
            [
                'Field "info" must be an object.',
                'Field "info.title" must be a non-empty string.',
                'Field "info.version" must be a non-empty string.',
            ],
            $validator->validate($this->validSpec(['info' => 'junk'])),
        );
    }

    public function testValidatorPathKeyGuards(): void
    {
        $validator = new OpenApiSpecValidator();
        self::assertSame(
            ["Path '/b' must map to an object of operations.", "Path key '7' must be a non-empty string beginning with '/'."],
            $validator->validate($this->validSpec(['paths' => [7 => 'junk', '/b' => 'junk']])),
        );
        self::assertSame(
            ["Path key '' must be a non-empty string beginning with '/'.", "Path '/b' must map to an object of operations."],
            $validator->validate($this->validSpec(['paths' => ['' => 'junk', '/b' => 'junk']])),
        );
    }

    public function testValidatorOperationGuards(): void
    {
        $validator = new OpenApiSpecValidator();
        self::assertSame(
            [
                "Path '/a' contains an unknown HTTP method 'purge'.",
                'Operation [get] /a must be an object.',
                'Operation [get] /b must define a non-empty operationId.',
            ],
            $validator->validate($this->validSpec(['paths' => [
                '/a' => ['purge' => 'oops', 'get' => 'oops'],
                '/b' => ['get' => ['operationId' => '  ', 'responses' => ['200' => ['description' => 'ok']]]],
            ]])),
        );
    }

    public function testValidatorOperationIdDuplicateDetection(): void
    {
        $validator = new OpenApiSpecValidator();
        $op = static fn (string $id): array => ['operationId' => $id, 'responses' => ['200' => ['description' => 'ok']]];
        self::assertSame(
            ["Duplicate operationId 'dup' (also used by [get] /a)."],
            $validator->validate($this->validSpec(['paths' => ['/a' => ['get' => $op('dup')], '/b' => ['put' => $op('dup')]]])),
        );
    }

    public function testValidatorResponseGuards(): void
    {
        $validator = new OpenApiSpecValidator();
        self::assertSame(
            ['Operation [get] /x must define at least one response.'],
            $validator->validate($this->validSpec(['paths' => ['/x' => ['get' => ['operationId' => 'o', 'responses' => []]]]])),
        );
        self::assertSame(
            ['Operation [get] /x must define at least one response.'],
            $validator->validate($this->validSpec(['paths' => ['/x' => ['get' => ['operationId' => 'o', 'responses' => 'junk']]]])),
        );
        self::assertSame(
            ["Operation [get] /x response '200' must carry a non-empty description."],
            $validator->validate($this->validSpec(['paths' => ['/x' => ['get' => ['operationId' => 'o', 'responses' => ['200' => ['description' => '']]]]]])),
        );
        self::assertSame(
            ["Operation [get] /x response '204' must carry a non-empty description."],
            $validator->validate($this->validSpec(['paths' => ['/x' => ['get' => ['operationId' => 'o', 'responses' => ['204' => 'junk']]]]])),
        );
    }

    public function testValidatorParameterGuards(): void
    {
        $validator = new OpenApiSpecValidator();
        self::assertSame(
            ['Operation [get] /x contains a parameter without a non-empty name.'],
            $validator->validate($this->validSpec(['paths' => ['/x' => ['get' => ['operationId' => 'o', 'responses' => ['200' => ['description' => 'ok']], 'parameters' => ['junk']]]]])),
        );
        self::assertSame(
            ['Operation [get] /x contains a parameter without a non-empty name.'],
            $validator->validate($this->validSpec(['paths' => ['/x' => ['get' => ['operationId' => 'o', 'responses' => ['200' => ['description' => 'ok']], 'parameters' => [['name' => '']]]]]])),
        );
        self::assertSame(
            ["Operation [get] /x parameter 'q' has invalid location 'body'."],
            $validator->validate($this->validSpec(['paths' => ['/x' => ['get' => ['operationId' => 'o', 'responses' => ['200' => ['description' => 'ok']], 'parameters' => [['name' => 'q', 'in' => 'body']]]]]])),
        );
    }

    public function testValidatorPathParameterMustBeRequired(): void
    {
        $validator = new OpenApiSpecValidator();
        self::assertSame(
            ["Operation [get] /i/{id} path parameter 'id' must set required: true."],
            $validator->validate($this->validSpec(['paths' => ['/i/{id}' => ['get' => ['operationId' => 'o', 'responses' => ['200' => ['description' => 'ok']], 'parameters' => [
                ['name' => 'id', 'in' => 'path'],
            ]]]]])),
        );
        self::assertSame(
            [],
            $validator->validate($this->validSpec(['paths' => ['/i/{id}' => ['get' => ['operationId' => 'o', 'responses' => ['200' => ['description' => 'ok']], 'parameters' => [
                ['name' => 'id', 'in' => 'path', 'required' => true],
            ]]]]])),
        );
        self::assertSame(
            [],
            $validator->validate($this->validSpec(['paths' => ['/i/{id}' => ['get' => ['operationId' => 'o', 'responses' => ['200' => ['description' => 'ok']], 'parameters' => [
                ['name' => 'other', 'in' => 'path', 'required' => false],
            ]]]]])),
        );
    }

    public function testValidatorSecurityRequirementObjects(): void
    {
        $validator = new OpenApiSpecValidator();
        self::assertSame(
            ['Operation [get] /x contains a malformed security requirement.'],
            $validator->validate($this->validSpec(['paths' => ['/x' => ['get' => ['operationId' => 'o', 'responses' => ['200' => ['description' => 'ok']], 'security' => [42]]]]])),
        );
        self::assertSame(
            [],
            $validator->validate($this->validSpec(['paths' => ['/x' => ['get' => ['operationId' => 'o', 'responses' => ['200' => ['description' => 'ok']], 'security' => [['bearer' => []]]]]]])),
        );
    }

    public function testValidatorComponentSchemaGuards(): void
    {
        $validator = new OpenApiSpecValidator();
        self::assertSame(
            ['Component schema names must be non-empty strings.', "Component schema 'ok' must be an object."],
            $validator->validate($this->validSpec(['components' => ['schemas' => [42 => 'junk', 'ok' => 'junk']]])),
        );
        self::assertSame(
            [],
            $validator->validate($this->validSpec(['components' => ['schemas' => ['Fine' => ['type' => 'object']]]])),
        );
    }

    public function testValidatorSecuritySchemeTypeVariants(): void
    {
        $validator = new OpenApiSpecValidator();
        self::assertSame(
            ['Security scheme \'ok\' must be an object with a type.', "Security scheme 'arr' must be an object with a type.", "Security scheme 'h' of type http must define a scheme.", "Security scheme 'k' of type apiKey must define in: query|header|cookie.", "Security scheme 'oid' of type openIdConnect must define openIdConnectUrl."],
            $validator->validate($this->validSpec(['components' => ['securitySchemes' => [
                'ok' => 'junk',
                'arr' => ['type' => 7],
                'h' => ['type' => 'http'],
                'k' => ['type' => 'apiKey', 'in' => 'body'],
                'oid' => ['type' => 'openIdConnect'],
                'good' => ['type' => 'http', 'scheme' => 'bearer'],
            ]]])),
        );
    }

    public function testValidatorWalksRefsAndStopsAtRefNodes(): void
    {
        $validator = new OpenApiSpecValidator();
        $spec = $this->validSpec([
            'paths' => ['/x' => ['get' => ['operationId' => 'o', 'responses' => ['200' => ['description' => 'ok', 'content' => [
                'application/json' => ['schema' => ['$ref' => '#/components/schemas/Nope']],
            ]]]]]],
        ]);
        self::assertSame(
            ["Unresolvable \$ref '#/components/schemas/Nope' (missing from components.schemas)."],
            $validator->validate($spec),
        );

        $nested = $this->validSpec([
            'paths' => ['/x' => ['get' => ['operationId' => 'o', 'responses' => ['200' => ['description' => 'ok', 'content' => [
                'application/json' => ['one' => ['schema' => ['$ref' => '#/components/schemas/Nope']], 'two' => ['schema' => ['$ref' => '#/components/schemas/AlsoNope']]],
            ]]]]]],
        ]);
        self::assertSame(
            [
                "Unresolvable \$ref '#/components/schemas/Nope' (missing from components.schemas).",
                "Unresolvable \$ref '#/components/schemas/AlsoNope' (missing from components.schemas).",
            ],
            $validator->validate($nested),
        );

        $resolved = $this->validSpec([
            'paths' => ['/x' => ['get' => ['operationId' => 'o', 'responses' => ['200' => ['description' => 'ok', 'content' => [
                'application/json' => ['schema' => ['$ref' => '#/components/schemas/Fine', 'deep' => ['$ref' => 'https://external/schema']]],
            ]]]]]],
            'components' => ['schemas' => ['Fine' => ['type' => 'object']]],
        ]);
        self::assertSame([], $validator->validate($resolved));
    }

    // ------------------------------------------------- serializer errors

    public function testJsonSerializerWrapsEncodeFailures(): void
    {
        $json = new JsonSpecificationSerializer();

        try {
            $json->serialize(['bad' => "\xB1\x31"]);
            self::fail('Expected a SpecificationException for malformed UTF-8.');
        } catch (SpecificationException $exception) {
            self::assertStringStartsWith('Failed to serialize specification to JSON: ', $exception->getMessage());
        }
    }

    public function testJsonSerializerWrapsDecodeFailures(): void
    {
        $json = new JsonSpecificationSerializer();

        try {
            $json->deserialize('not-json');
            self::fail('Expected a SpecificationException for invalid JSON.');
        } catch (SpecificationException $exception) {
            self::assertStringStartsWith('Invalid JSON specification: ', $exception->getMessage());
        }

        try {
            $json->deserialize('"scalar-only"');
            self::fail('Expected a SpecificationException for non-array documents.');
        } catch (SpecificationException $exception) {
            self::assertSame('A specification document must deserialize to an object/array.', $exception->getMessage());
        }
    }

    public function testYamlRejectsUnsupportedNodesAndDepth(): void
    {
        $yaml = new YamlSpecificationSerializer();

        try {
            $yaml->serialize(['x' => new \stdClass()]);
            self::fail('Expected a SpecificationException for object nodes.');
        } catch (SpecificationException $exception) {
            self::assertSame('YAML emitter supports scalars and arrays only; got stdClass.', $exception->getMessage());
        }
    }

    public function testYamlEmptyArrayValueDoesNotStopSiblings(): void
    {
        $yaml = new YamlSpecificationSerializer();
        self::assertSame("a: []\nb: x\n", $yaml->serialize(['a' => [], 'b' => 'x']));
    }

    // ------------------------------------------------- command messages

    public function testGenerateErrorMessagesArePrefixed(): void
    {
        $io = new ConsoleIO();
        $command = new GenerateSpecCommand();
        $exit = $command->run($io, [
            ['method' => 'GET', 'pattern' => '/dup', 'handler' => 'h', 'name' => 'one', 'segments' => []],
            ['method' => 'GET', 'pattern' => '/dup', 'handler' => 'h', 'name' => 'two', 'segments' => []],
        ]);
        self::assertSame(1, $exit);
        self::assertStringStartsWith('openapi:generate failed: ', implode("\n", $io->errLog()));
    }

    public function testGenerateWriteFailureMessagesAreExact(): void
    {
        $io = new ConsoleIO();
        $command = new GenerateSpecCommand();
        $exit = $command->run($io, [
            ['method' => 'GET', 'pattern' => '/x', 'handler' => 'h', 'name' => null, 'segments' => []],
        ], null, ['output' => '/proc/self/definitely-not-a-dir/out.json']);
        self::assertSame(1, $exit);
        self::assertSame(
            ['openapi:generate failed: Output directory \'/proc/self/definitely-not-a-dir\' does not exist.'],
            $io->errLog(),
        );
    }

    // ------------------------------------------------- route extractor

    /**
     * @param list<array<string, mixed>> $routes
     *
     * @return array{openapi: string, info: array<string, mixed>, paths: array<string, array<string, array<string, mixed>>>, components?: array<string, mixed>, tags?: list<array<string, mixed>>}
     */
    private function buildSpec(array $routes, ?\Closure $resolver = null): array
    {
        $extractor = new RouteSpecExtractor(
            new Info(title: 'Sweep API', version: '1'),
            $resolver,
        );

        return $extractor->extract($routes)->build();
    }

    /** @return array<string, mixed> */
    private function adapterSpec(): array
    {
        $builder = new SpecificationBuilder(new Info(title: 'Sweep Adapter API', version: '1.0.0'));

        return $builder->build();
    }

    // ------------------------------------------------- validator matrix

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function validSpec(array $overrides = []): array
    {
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1'],
            'paths' => [],
        ];

        return array_merge($spec, $overrides);
    }
}
