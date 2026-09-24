<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.20.0 — OpenAPI RouteSpecExtractor: route table to
 * Operation mapping, attribute enrichment, constraint schemas.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\OpenApi\Attribute\Deprecated;
use Zef\Framework\OpenApi\Attribute\Parameter as ParameterAttr;
use Zef\Framework\OpenApi\Attribute\RequestBody as RequestBodyAttr;
use Zef\Framework\OpenApi\Attribute\Response as ResponseAttr;
use Zef\Framework\OpenApi\Attribute\Route as RouteAttr;
use Zef\Framework\OpenApi\Attribute\Schema as SchemaAttr;
use Zef\Framework\OpenApi\Attribute\Security as SecurityAttr;
use Zef\Framework\OpenApi\Attribute\SecurityScheme as SecuritySchemeAttr;
use Zef\Framework\OpenApi\Attribute\Tag as TagAttr;
use Zef\Framework\OpenApi\Info;
use Zef\Framework\OpenApi\ParameterLocation;
use Zef\Framework\OpenApi\RouteSpecExtractor;
use Zef\Framework\OpenApi\SecuritySchemeType;
use Zef\Framework\OpenApi\SpecificationException;

#[SchemaAttr(name: 'ApiRoot')]
#[SecuritySchemeAttr(name: 'bearer', type: SecuritySchemeType::Http, scheme: 'bearer', bearerFormat: 'JWT')]
#[TagAttr(name: 'users', description: 'User management')]
#[SecurityAttr(scheme: 'bearer')]
final class OpenApiExtractorUserController
{
    #[RouteAttr(method: 'GET', path: '/users/{id}', summary: 'Get one user', tags: ['users'])]
    #[ResponseAttr(status: 200, description: 'User found', schema: OpenApiExtractorUserDto::class)]
    #[ResponseAttr(status: 404, description: 'User not found')]
    #[ParameterAttr(name: 'X-Trace', in: ParameterLocation::Header, description: 'trace id')]
    public function handle(): never
    {
        throw new \LogicException('Documentation fixture only.');
    }

    #[RouteAttr(method: 'POST', path: '/users', summary: 'Create user')]
    #[RequestBodyAttr(schema: OpenApiExtractorCreateDto::class, required: true)]
    #[ParameterAttr(name: 'X-Trace', in: ParameterLocation::Header, description: 'trace id')]
    #[Deprecated]
    public function create(): never
    {
        throw new \LogicException('Documentation fixture only.');
    }
}

#[SchemaAttr(name: 'ExtractorUser')]
final class OpenApiExtractorUserDto
{
    public int $id;

    public string $name;
}

#[SchemaAttr(name: 'ExtractorCreateDto')]
final class OpenApiExtractorCreateDto
{
    public string $name;

    public string $email;
}

final class OpenApiExtractorLegacyHandler {}

/**
 * @internal
 */
final class OpenApiRouteSpecExtractorTest extends TestCase
{
    private RouteSpecExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new RouteSpecExtractor(
            new Info(title: 'Zef API', version: '2.20.0'),
            static fn (string $handler): ?string => match ($handler) {
                'api.user' => OpenApiExtractorUserController::class,
                'api.legacy' => OpenApiExtractorLegacyHandler::class,
                default => null,
            },
        );
    }

    public function testExtractsOperationsFromRouteTable(): void
    {
        $routes = [
            ['method' => 'GET', 'pattern' => '/users/{id:int}', 'handler' => 'api.user', 'name' => 'users.show',
                'segments' => [['dynamic' => true, 'name' => 'id', 'constraint' => 'int']]],
            ['method' => 'POST', 'pattern' => '/users', 'handler' => 'api.user', 'name' => null, 'segments' => []],
        ];
        $spec = $this->build($routes);

        self::assertSame('Zef API', $spec['info']['title']);
        self::assertArrayHasKey('/users/{id}', $spec['paths']);
        $get = $this->op($spec, '/users/{id}', 'get');
        self::assertSame('users.show', $get['operationId']);
        self::assertSame('Get one user', $get['summary']);
        self::assertSame(['users'], $get['tags']);

        /** @var list<array<string, mixed>> $params */
        $params = $get['parameters'];
        self::assertTrue($params[0]['required']);

        /** @var array<string, mixed> $paramSchema */
        $paramSchema = $params[0]['schema'];
        self::assertSame('integer', $paramSchema['type']);
    }

    public function testAttributeResponsesMergeWithSchemas(): void
    {
        $routes = [
            ['method' => 'GET', 'pattern' => '/users/{id}', 'handler' => 'api.user', 'name' => null, 'segments' => []],
        ];
        $spec = $this->build($routes);
        $get = $this->op($spec, '/users/{id}', 'get');
        self::assertSame('User found', $this->response($get, '200')['description']);

        /** @var array<string, mixed> $content200 */
        $content200 = $this->response($get, '200')['content'];

        /** @var array<string, mixed> $media200 */
        $media200 = $content200['application/json'];

        /** @var array<string, mixed> $schema200 */
        $schema200 = $media200['schema'];
        self::assertSame('#/components/schemas/ExtractorUser', $schema200['$ref']);
        self::assertSame('User not found', $this->response($get, '404')['description']);

        /** @var array<string, mixed> $components */
        $components = $spec['components'] ?? [];

        /** @var array<string, mixed> $schemas */
        $schemas = $components['schemas'];
        self::assertArrayHasKey('ExtractorUser', $schemas);
    }

    public function testRequestBodyAndHeaderParameter(): void
    {
        $routes = [
            ['method' => 'POST', 'pattern' => '/users', 'handler' => 'api.user', 'name' => null, 'segments' => []],
        ];
        $spec = $this->build($routes);
        $post = $this->op($spec, '/users', 'post');
        self::assertTrue($post['deprecated']);

        /** @var array<string, mixed> $requestBody */
        $requestBody = $post['requestBody'];
        self::assertTrue($requestBody['required']);

        /** @var array<string, mixed> $content */
        $content = $requestBody['content'];

        /** @var array<string, mixed> $media */
        $media = $content['application/json'];

        /** @var array<string, mixed> $schema */
        $schema = $media['schema'];
        self::assertSame('#/components/schemas/ExtractorCreateDto', $schema['$ref']);

        /** @var list<array<string, mixed>> $params */
        $params = $post['parameters'];
        $headerParam = null;
        foreach ($params as $parameter) {
            if ($parameter['name'] === 'X-Trace') {
                $headerParam = $parameter;
            }
        }
        self::assertNotNull($headerParam);
        self::assertSame('header', $headerParam['in']);
    }

    public function testClassSecuritySchemeDocumented(): void
    {
        $routes = [
            ['method' => 'GET', 'pattern' => '/users/{id}', 'handler' => 'api.user', 'name' => null, 'segments' => []],
        ];
        $spec = $this->build($routes);

        /** @var array<string, mixed> $components */
        $components = $spec['components'] ?? [];

        /** @var array<string, mixed> $schemes */
        $schemes = $components['securitySchemes'];

        /** @var array<string, mixed> $bearer */
        $bearer = $schemes['bearer'];
        self::assertSame('bearer', $bearer['scheme']);
        $get = $this->op($spec, '/users/{id}', 'get');
        self::assertSame([['bearer' => []]], $get['security']);
    }

    public function testDefaultResponseWithoutAttributes(): void
    {
        $routes = [
            ['method' => 'GET', 'pattern' => '/legacy', 'handler' => 'api.legacy', 'name' => null, 'segments' => []],
        ];
        $spec = $this->build($routes);
        self::assertSame('Successful response.', $this->response($this->op($spec, '/legacy', 'get'), '200')['description']);
    }

    public function testUnresolvableHandlerSkipsAttributes(): void
    {
        $routes = [
            ['method' => 'GET', 'pattern' => '/anon', 'handler' => 'unknown.service', 'name' => null, 'segments' => []],
        ];
        $spec = $this->build($routes);
        self::assertSame('get.unknown.service', $this->op($spec, '/anon', 'get')['operationId']);
    }

    public function testConstraintSchemasForKnownNames(): void
    {
        $routes = [
            ['method' => 'GET', 'pattern' => '/a/{id:uuid}/{n:uint}/{s:slug}/{h:hex}/{x:custom}', 'handler' => 'api.legacy', 'name' => null, 'segments' => []],
        ];
        $spec = $this->build($routes);
        $get = $this->op($spec, '/a/{id}/{n}/{s}/{h}/{x}', 'get');

        /** @var list<array<string, mixed>> $parameters */
        $parameters = $get['parameters'];
        $byName = [];
        foreach ($parameters as $parameter) {
            $name = $parameter['name'];
            $byName[is_string($name) ? $name : ''] = $parameter;
        }

        /** @var array<string, mixed> $idParam */
        $idParam = $byName['id'];

        /** @var array<string, mixed> $idSchema */
        $idSchema = $idParam['schema'];
        self::assertSame('uuid', $idSchema['format']);

        /** @var array<string, mixed> $nParam */
        $nParam = $byName['n'];

        /** @var array<string, mixed> $nSchema */
        $nSchema = $nParam['schema'];
        self::assertSame('integer', $nSchema['type']);
        self::assertSame(1, $nSchema['minimum']);

        /** @var array<string, mixed> $sParam */
        $sParam = $byName['s'];

        /** @var array<string, mixed> $sSchema */
        $sSchema = $sParam['schema'];
        self::assertSame('^[a-z0-9]+(?:-[a-z0-9]+)*$', $sSchema['pattern']);

        /** @var array<string, mixed> $hParam */
        $hParam = $byName['h'];

        /** @var array<string, mixed> $hSchema */
        $hSchema = $hParam['schema'];
        self::assertSame('^[0-9a-f]+$', $hSchema['pattern']);
        self::assertArrayNotHasKey('schema', $byName['x']); // custom constraint degrades
    }

    public function testOperationIdCollisionSuffixes(): void
    {
        $routes = [
            ['method' => 'GET', 'pattern' => '/a', 'handler' => 'mod.one', 'name' => null, 'segments' => []],
            ['method' => 'POST', 'pattern' => '/a', 'handler' => 'mod.one', 'name' => null, 'segments' => []],
        ];
        $spec = $this->build($routes);
        self::assertSame('get.mod.one', $this->op($spec, '/a', 'get')['operationId']);
        self::assertSame('post.mod.one', $this->op($spec, '/a', 'post')['operationId']);
    }

    public function testDuplicatePathMethodRejected(): void
    {
        $routes = [
            ['method' => 'GET', 'pattern' => '/dup', 'handler' => 'api.legacy', 'name' => 'one', 'segments' => []],
            ['method' => 'GET', 'pattern' => '/dup', 'handler' => 'api.legacy', 'name' => 'two', 'segments' => []],
        ];
        $this->expectException(SpecificationException::class);
        $this->extractor->extract($routes);
    }

    public function testMalformedRouteArrayThrows(): void
    {
        $this->expectException(SpecificationException::class);
        $this->extractor->extract([['method' => 7, 'pattern' => '/x', 'handler' => 'h']]);
    }

    public function testMalformedPatternThrows(): void
    {
        $routes = [
            ['method' => 'GET', 'pattern' => 'relative', 'handler' => 'api.legacy', 'name' => null, 'segments' => []],
        ];
        $this->expectException(SpecificationException::class);
        $this->extractor->extract($routes);
    }

    public function testClassInfoOverridesBuilderInfo(): void
    {
        $routes = [
            ['method' => 'GET', 'pattern' => '/users/{id}', 'handler' => 'api.user', 'name' => null, 'segments' => []],
        ];
        $spec = $this->build($routes);
        self::assertSame('Zef API', $spec['info']['title']); // controller has no #[OpenApi]
    }

    /** @param list<array<string, mixed>> $routes
     * @return array{openapi: string, info: array<string, mixed>, paths: array<string, array<string, array<string, mixed>>>, components?: array<string, mixed>}
     */
    private function build(array $routes): array
    {
        return $this->extractor->extract($routes)->build();
    }

    /** @param array<string, mixed> $spec
     * @return array<array-key, mixed>
     */
    private function op(array $spec, string $path, string $method): array
    {
        $paths = $spec['paths'];
        self::assertIsArray($paths);
        $byMethod = $paths[$path];
        self::assertIsArray($byMethod);
        $operation = $byMethod[$method];
        self::assertIsArray($operation);

        return $operation;
    }

    /** @param array<array-key, mixed> $operation
     * @return array<array-key, mixed>
     */
    private function response(array $operation, string $status): array
    {
        $responses = $operation['responses'];
        self::assertIsArray($responses);
        $response = $responses[$status];
        self::assertIsArray($response);

        return $response;
    }
}
