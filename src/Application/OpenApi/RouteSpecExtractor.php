<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Application layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi;

/**
 * The bridge between the ZEF router and an OpenAPI document.
 *
 * Accepts the plain route arrays emitted by Router::getRoutes() (no
 * Router dependency, so the Application layer stays deptrac-clean) and
 * produces a SpecificationBuilder with one Operation per route.
 *
 * Enrichment: when a `classResolver` is provided (typically backed by the
 * container) the handler service id is mapped to a class whose
 * #[OpenApi], #[SecurityScheme], #[Tag], #[Security], #[Route],
 * #[Parameter], #[RequestBody], #[Response] and #[Deprecated] attributes
 * are merged into the generated operations. Handler conventions: method
 * metadata is read from handle() when present, otherwise __invoke().
 *
 * Without any attribute metadata every operation still documents itself
 * with derived path parameters and a default 200 response.
 */
final readonly class RouteSpecExtractor
{
    /**
     * Default router-constraint name => schema shape.
     */
    private const array CONSTRAINT_SCHEMAS = [
        'int' => ['type' => 'integer'],
        'uint' => ['type' => 'integer', 'minimum' => 1],
        'alpha' => ['type' => 'string', 'pattern' => '^[a-zA-Z]+$'],
        'slug' => ['type' => 'string', 'pattern' => '^[a-z0-9]+(?:-[a-z0-9]+)*$'],
        'uuid' => ['type' => 'string', 'format' => 'uuid'],
        'hex' => ['type' => 'string', 'pattern' => '^[0-9a-f]+$'],
    ];

    /**
     * @param null|\Closure(string): ?class-string $classResolver
     */
    public function __construct(
        private Info $info,
        private ?\Closure $classResolver = null,
        private OpenApiVersion $version = OpenApiVersion::V3_1_0,
    ) {}

    /**
     * @param list<array<string, mixed>> $routes Router::getRoutes() shaped arrays
     */
    public function extract(array $routes): SpecificationBuilderInterface
    {
        $generator = new SchemaGenerator();
        $usedOperationIds = [];
        $appliedClasses = [];
        $infoOverride = null;
        $rootSchemes = [];
        $rootTags = [];

        // Fase 0 — resolv handler classes dan kumpulkan metadata root
        // (#[OpenApi] info override, #[SecurityScheme], #[Tag]) sehingga
        // builder dibuat dengan Info final. Override pertama yang ditemui
        // menang; kelas yang sama hanya dipindai sekali per dokumen.
        foreach ($routes as $route) {
            $handler = $route['handler'] ?? null;
            if (!is_string($handler) || !$this->classResolver instanceof \Closure) {
                continue;
            }
            $resolved = ($this->classResolver)($handler);
            if (!is_string($resolved) || (!class_exists($resolved) && !interface_exists($resolved))) {
                continue;
            }
            if (isset($appliedClasses[$resolved])) {
                continue;
            }
            $appliedClasses[$resolved] = true;
            $override = $this->collectClassRootAttributes($resolved, $rootSchemes, $rootTags);
            if ($override instanceof Info && !$infoOverride instanceof Info) {
                $infoOverride = $override;
            }
        }

        $builder = new SpecificationBuilder($infoOverride ?? $this->info, $this->version);
        foreach ($rootSchemes as $schemeName => $scheme) {
            $builder->addSecurityScheme($schemeName, $scheme);
        }
        foreach ($rootTags as $rootTag) {
            $builder->addTag($rootTag);
        }

        foreach ($routes as $route) {
            $method = $route['method'] ?? null;
            $pattern = $route['pattern'] ?? null;
            $handler = $route['handler'] ?? null;
            if (!is_string($method) || !is_string($pattern) || !is_string($handler)) {
                throw new SpecificationException('Route arrays must provide string method, pattern, and handler entries.');
            }

            $handlerClass = null;
            if ($this->classResolver instanceof \Closure) {
                $resolved = ($this->classResolver)($handler);
                if (is_string($resolved) && (class_exists($resolved) || interface_exists($resolved))) {
                    $handlerClass = $resolved;
                }
            }

            $path = $this->toOpenApiPath($pattern);
            $parameters = $this->pathParameters($pattern, $route['segments'] ?? null);

            $name = $route['name'] ?? null;
            if (is_string($name) && preg_match('/^[A-Za-z0-9._-]{1,128}$/', $name) !== 1) {
                $name = null; // hostile/unusual route names fall back to derivation
            }
            $operationId = is_string($name) && $name !== ''
                ? $name
                : $this->deriveOperationId($method, $handler, $usedOperationIds);

            $meta = $this->collectMethodAttributes($handlerClass, $method, $pattern, $generator);
            if ($meta['operationIdOverride'] !== null && !is_string($name)) {
                $operationId = $meta['operationIdOverride'];
            }
            $usedOperationIds[$operationId] = true;

            foreach ($meta['classTags'] as $tag) {
                $builder->addTag($tag);
            }

            $responses = $meta['responses'];
            if ($responses === []) {
                $responses = ['200' => new Response('Successful response.')];
            }

            $security = $meta['methodSecurity'] ?? $meta['classSecurity'];

            $operation = new Operation(
                operationId: $operationId,
                method: $method,
                path: $path,
                responses: $responses,
                summary: $meta['summary'],
                description: $meta['description'],
                tags: $meta['tags'],
                parameters: [...$parameters, ...$meta['parameters']],
                requestBody: $meta['requestBody'],
                deprecated: $meta['deprecated'],
                security: $security,
            );
            $builder->addOperation($operation);
        }

        foreach ($generator->registeredSchemas() as $schemaName => $schema) {
            $builder->addSchema($schemaName, $schema);
        }

        return $builder;
    }

    /**
     * Convert a ZEF route pattern into an OpenAPI path template:
     * /users/{id:int} -> /users/{id}.
     */
    private function toOpenApiPath(string $pattern): string
    {
        $converted = preg_replace(
            '/\{([A-Za-z_][A-Za-z0-9_]*)(?::[A-Za-z_][A-Za-z0-9_]*)?\}/',
            '{$1}',
            $pattern,
        );
        if (!is_string($converted) || $converted === '' || $converted[0] !== '/') {
            throw new SpecificationException("Route pattern '{$pattern}' cannot be converted to an OpenAPI path.");
        }

        return $converted;
    }

    /**
     * Derive Parameter objects for every dynamic segment. Constraint names
     * map to schema types (int/uint/alpha/slug/uuid/hex); unknown or custom
     * constraints degrade to unconstrained strings.
     *
     * @param mixed $segments Router segment arrays (or null to parse pattern)
     *
     * @return list<Parameter>
     */
    private function pathParameters(string $pattern, mixed $segments): array
    {
        $names = [];
        if (is_array($segments)) {
            foreach ($segments as $segment) {
                if (is_array($segment) && ($segment['dynamic'] ?? false) === true && isset($segment['name']) && is_string($segment['name'])) {
                    $names[] = [$segment['name'], is_string($segment['constraint'] ?? null) ? $segment['constraint'] : null];
                }
            }
        }
        if ($names === []) {
            // segments absent/empty (fixture arrays, edge tooling): parse the
            // wire pattern directly. Duplicates are preserved verbatim — the
            // Router rejects them, but the extractor stays garbage-tolerant.
            if (preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)(?::([A-Za-z_][A-Za-z0-9_]*))?\}/', $pattern, $matches, PREG_SET_ORDER) > 0) {
                foreach ($matches as $match) {
                    $names[] = [$match[1], $match[2] ?? null];
                }
            }
        }

        $parameters = [];
        foreach ($names as [$paramName, $constraint]) {
            $parameters[] = new Parameter(
                name: $paramName,
                in: ParameterLocation::Path,
                schema: $this->constraintSchema($constraint),
                description: $constraint !== null ? "Path parameter constrained by '{$constraint}'." : '',
            );
        }

        return $parameters;
    }

    private function constraintSchema(?string $constraint): ?Schema
    {
        if ($constraint === null || !isset(self::CONSTRAINT_SCHEMAS[$constraint])) {
            return null;
        }
        $shape = self::CONSTRAINT_SCHEMAS[$constraint];

        return new Schema(
            type: SchemaType::from($shape['type']),
            format: $shape['format'] ?? null,
            pattern: $shape['pattern'] ?? null,
            minimum: $shape['minimum'] ?? null,
        );
    }

    /** Deterministic, collision-free fallback operation id.
     * @param array<string, true> $usedOperationIds
     */
    private function deriveOperationId(string $method, string $handler, array $usedOperationIds): string
    {
        $base = strtolower($method) . '.' . preg_replace('/[^A-Za-z0-9._-]/', '_', $handler);
        $base = trim($base, '.');
        if ($base === '' || preg_match('/^[A-Za-z0-9._-]{1,128}$/', $base) !== 1) {
            $base = strtolower($method) . '.route';
        }
        $candidate = $base;
        $suffix = 2;
        while (isset($usedOperationIds[$candidate])) {
            $candidate = $base . '.' . $suffix;
            ++$suffix;
        }

        return $candidate;
    }

    /**
     * Collect class-root attribute metadata into the extraction
     * accumulators: #[SecurityScheme] definitions (scheme name =>
     * definition) and #[Tag] declarations carrying a description (the
     * builder dedupes by tag name). Returns the #[OpenApi] document-info
     * override when the class carries one, null otherwise. Called at most
     * once per handler class per document; the first override wins.
     *
     * @param array<string, SecurityScheme> $rootSchemes
     * @param list<Tag> $rootTags
     */
    private function collectClassRootAttributes(string $class, array &$rootSchemes, array &$rootTags): ?Info
    {
        // @phpstan-ignore argument.type (resolved through class_exists in extract())
        $reflection = new \ReflectionClass($class);

        foreach ($reflection->getAttributes(Attribute\SecurityScheme::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            $meta = $attribute->newInstance();
            $rootSchemes[$meta->name] = new SecurityScheme(
                type: $meta->type,
                scheme: $meta->scheme,
                bearerFormat: $meta->bearerFormat,
                in: $meta->in,
                openIdConnectUrl: $meta->openIdConnectUrl,
                description: $meta->description,
            );
        }

        foreach ($reflection->getAttributes(Attribute\Tag::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            $meta = $attribute->newInstance();
            if ($meta->description !== '') {
                $rootTags[] = new Tag($meta->name, $meta->description);
            }
        }

        $root = $reflection->getAttributes(Attribute\OpenApi::class, \ReflectionAttribute::IS_INSTANCEOF)[0] ?? null;
        if ($root === null) {
            return null;
        }

        $meta = $root->newInstance();

        return new Info(
            title: $meta->title,
            version: $meta->version,
            description: $meta->description,
            termsOfService: $meta->termsOfService,
            contact: $meta->contact,
            license: $meta->license,
        );
    }

    /**
     * @return array{summary:string,description:string,tags:list<string>,classTags:list<Tag>,parameters:list<Parameter>,requestBody:?RequestBody,responses:array<int|string,Response>,deprecated:bool,classSecurity:list<SecurityRequirement>,methodSecurity:?list<SecurityRequirement>,operationIdOverride:?string}
     */
    private function emptyMeta(): array
    {
        return [
            'summary' => '',
            'description' => '',
            'tags' => [],
            'classTags' => [],
            'parameters' => [],
            'requestBody' => null,
            'responses' => [],
            'deprecated' => false,
            'classSecurity' => [],
            'methodSecurity' => null,
            'operationIdOverride' => null,
        ];
    }

    /**
     * Merge class-level + method-level operation metadata.
     *
     * @return array{summary:string,description:string,tags:list<string>,classTags:list<Tag>,parameters:list<Parameter>,requestBody:?RequestBody,responses:array<int|string,Response>,deprecated:bool,classSecurity:list<SecurityRequirement>,methodSecurity:?list<SecurityRequirement>,operationIdOverride:?string}
     */
    private function collectMethodAttributes(?string $handlerClass, string $method, string $pattern, SchemaGenerator $generator): array
    {
        $summary = '';
        $description = '';
        $tags = [];
        $classTags = [];
        $parameters = [];
        $requestBody = null;
        $deprecated = false;
        $classSecurity = [];
        $methodSecurity = null;
        $operationIdOverride = null;

        /** @var array<int|string, Response> $responses */
        $responses = [];

        if ($handlerClass === null) {
            return $this->emptyMeta();
        }

        // @phpstan-ignore argument.type (resolved through class_exists in extract())
        $reflection = new \ReflectionClass($handlerClass);

        foreach ($reflection->getAttributes(Attribute\Tag::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            $meta = $attribute->newInstance();
            if ($meta->description !== '') {
                $classTags[] = new Tag($meta->name, $meta->description);
            }
            $tags[] = $meta->name;
        }
        foreach ($reflection->getAttributes(Attribute\Security::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            $meta = $attribute->newInstance();
            $classSecurity[] = new SecurityRequirement([$meta->scheme => $meta->scopes]);
        }
        if ($reflection->getAttributes(Attribute\Deprecated::class, \ReflectionAttribute::IS_INSTANCEOF) !== []) {
            $deprecated = true;
        }

        $target = $this->documentationTarget($reflection);
        if (!$target instanceof \ReflectionMethod) {
            return $this->emptyMeta();
        }

        // Handler conventions: attributes may live on the PSR-15 handle()
        // method, or on dedicated public methods when a controller serves
        // several routes. Methods whose #[Route] declaration matches the
        // current method (+ optional path) take precedence over the
        // default target.
        $matched = [];
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $candidate) {
            if ($candidate->isStatic() || (str_starts_with($candidate->getName(), '__') && $candidate->getName() !== '__invoke')) {
                continue;
            }
            foreach ($candidate->getAttributes(Attribute\Route::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                $meta = $attribute->newInstance();
                if (strcasecmp($meta->method, $method) === 0
                    && in_array($meta->path, ['', $pattern, $this->toOpenApiPath($pattern)], true)) {
                    $matched[] = $candidate;

                    break;
                }
            }
        }
        if ($matched === []) {
            // Fall back to the default handler method — but only when it
            // carries no #[Route] of its own (those belong to other paths).
            $matched = $target->getAttributes(Attribute\Route::class, \ReflectionAttribute::IS_INSTANCEOF) === []
                ? [$target]
                : [];
        }
        if ($matched === []) {
            return self::emptyMeta();
        }
        foreach ($matched as $source) {
            $sourceName = $source->getName();
            foreach ($source->getAttributes(Attribute\Route::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                $meta = $attribute->newInstance();
                if (strcasecmp($meta->method, $method) !== 0) {
                    continue;
                }
                if (!in_array($meta->path, ['', $pattern, $this->toOpenApiPath($pattern)], true)) {
                    continue;
                }
                $summary = $meta->summary;
                $description = $meta->description;
                if ($meta->tags !== []) {
                    $tags = [...$tags, ...$meta->tags];
                }
                if ($meta->operationId !== null) {
                    $operationIdOverride = $meta->operationId;
                }
                if ($meta->deprecated) {
                    $deprecated = true;
                }
            }

            foreach ($source->getAttributes(Attribute\Parameter::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                $meta = $attribute->newInstance();
                $parameters[] = new Parameter(
                    name: $meta->name,
                    in: $meta->in,
                    schema: new Schema(type: $meta->type, format: $meta->format),
                    description: $meta->description,
                    required: $meta->required,
                    deprecated: $meta->deprecated,
                    example: $meta->example,
                );
            }

            foreach ($source->getAttributes(Attribute\RequestBody::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                $meta = $attribute->newInstance();
                if ($requestBody instanceof RequestBody) {
                    throw new SpecificationException("Handler {$handlerClass}::{$sourceName}() declares more than one #[RequestBody]; at most one is allowed per operation.");
                }
                $content = [];
                if ($meta->schema !== null && (class_exists($meta->schema) || enum_exists($meta->schema))) {
                    $generator->generateFromClass($meta->schema);
                    $content[$meta->mediaType->value] = new Schema(ref: '#/components/schemas/' . $generator->schemaNameFor($meta->schema));
                }
                $requestBody = new RequestBody(
                    content: $content !== [] ? $content : [MediaType::Json->value => new Schema(type: SchemaType::Object)],
                    description: $meta->description,
                    required: $meta->required,
                );
            }

            foreach ($source->getAttributes(Attribute\Response::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                $meta = $attribute->newInstance();
                $statusKey = (string) $meta->status;
                $content = [];
                if ($meta->schema !== null && (class_exists($meta->schema) || enum_exists($meta->schema))) {
                    $generator->generateFromClass($meta->schema);
                    $content[$meta->mediaType] = new Schema(ref: '#/components/schemas/' . $generator->schemaNameFor($meta->schema));
                }
                $responses[$statusKey] = new Response(
                    description: $meta->description !== '' ? $meta->description : 'Response.',
                    content: $content,
                );
            }

            foreach ($source->getAttributes(Attribute\Security::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                $meta = $attribute->newInstance();
                $methodSecurity[] = new SecurityRequirement([$meta->scheme => $meta->scopes]);
            }
            foreach ($source->getAttributes(Attribute\Tag::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                $meta = $attribute->newInstance();
                $tags[] = $meta->name;
            }
            if ($source->getAttributes(Attribute\Deprecated::class, \ReflectionAttribute::IS_INSTANCEOF) !== []) {
                $deprecated = true;
            }
        }

        $tags = array_values(array_unique($tags));

        return ['summary' => $summary, 'description' => $description, 'tags' => $tags, 'classTags' => $classTags, 'parameters' => $parameters, 'requestBody' => $requestBody, 'responses' => $responses, 'deprecated' => $deprecated, 'classSecurity' => $classSecurity, 'methodSecurity' => $methodSecurity, 'operationIdOverride' => $operationIdOverride];
    }

    /** PSR-15 handle() first, then __invoke().
     * @param \ReflectionClass<object> $reflection
     */
    private function documentationTarget(\ReflectionClass $reflection): ?\ReflectionMethod
    {
        if ($reflection->hasMethod('handle')) {
            return $reflection->getMethod('handle');
        }
        if ($reflection->hasMethod('__invoke')) {
            return $reflection->getMethod('__invoke');
        }

        return null;
    }
}
