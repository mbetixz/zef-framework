<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Application layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi;

/**
 * Converts an OpenAPI document into a Postman Collection v2.1 payload.
 *
 * Pure deterministic mapping (same spec in, same collection out):
 *   - one folder per path, one request per operation;
 *   - path parameters become :variables, query parameters become the url
 *     query list;
 *   - JSON request bodies embed the schema example/default when present;
 *   - security schemes map onto Postman's collection-level auth section.
 */
final class PostmanCollectionExporter
{
    private const string POSTMAN_SCHEMA = 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json';

    /**
     * @param array<string, mixed> $spec built OpenAPI document
     *
     * @return array{info: array<string, mixed>, item: list<array<string, mixed>>, auth?: array<string, mixed>}
     */
    public function export(array $spec): array
    {
        $info = is_array($spec['info'] ?? null) ? $spec['info'] : [];
        $paths = is_array($spec['paths'] ?? null) ? $spec['paths'] : [];
        $components = is_array($spec['components'] ?? null) ? $spec['components'] : [];
        $securitySchemes = is_array($components['securitySchemes'] ?? null) ? $components['securitySchemes'] : [];

        $items = [];
        foreach ($paths as $path => $operations) {
            if (!is_string($path) || !is_array($operations)) {
                continue;
            }
            $requests = [];
            foreach ($operations as $method => $operation) {
                if (!is_string($method) || !is_array($operation)) {
                    continue;
                }
                $requests[] = $this->buildRequest($method, $path, $operation);
            }
            if ($requests !== []) {
                $items[] = ['name' => $path, 'item' => $requests];
            }
        }

        $collection = [
            'info' => [
                'name' => is_string($info['title'] ?? null) ? $info['title'] : 'ZEF API',
                'schema' => self::POSTMAN_SCHEMA,
                'description' => is_string($info['description'] ?? null) ? $info['description'] : '',
            ],
            'item' => $items,
        ];
        if (is_string($info['version'] ?? null) && $info['version'] !== '') {
            $collection['info']['version'] = $info['version'];
        }

        $auth = $this->buildAuth($securitySchemes);
        if ($auth !== null) {
            $collection['auth'] = $auth;
        }

        return $collection;
    }

    /**
     * @param array<array-key, mixed> $operation
     *
     * @return array<string, mixed>
     */
    private function buildRequest(string $method, string $path, array $operation): array
    {
        $segments = [];
        foreach (explode('/', trim($path, '/')) as $segment) {
            $segments[] = $segment === '' ? $segment : preg_replace('/^\{([A-Za-z_][A-Za-z0-9_]*)\}$/', ':$1', $segment);
        }

        $query = [];
        $parameters = is_array($operation['parameters'] ?? null) ? $operation['parameters'] : [];
        foreach ($parameters as $parameter) {
            if (!is_array($parameter) || ($parameter['in'] ?? '') !== 'query' || !is_string($parameter['name'] ?? null)) {
                continue;
            }
            $entry = ['key' => $parameter['name'], 'value' => ''];
            if (is_string($parameter['description'] ?? null) && $parameter['description'] !== '') {
                $entry['description'] = $parameter['description'];
            }
            $query[] = $entry;
        }

        $description = '';
        if (is_string($operation['summary'] ?? null) && $operation['summary'] !== '') {
            $description = $operation['summary'];
        }
        if (is_string($operation['description'] ?? null) && $operation['description'] !== '') {
            $description = $description === '' ? $operation['description'] : $description . "\n\n" . $operation['description'];
        }

        $request = [
            'name' => is_string($operation['operationId'] ?? null) ? $operation['operationId'] : strtoupper($method) . ' ' . $path,
            'request' => [
                'method' => strtoupper($method),
                'header' => [],
                'url' => [
                    'raw' => '{{baseUrl}}' . $path,
                    'host' => ['{{baseUrl}}'],
                    'path' => $segments,
                    'query' => $query,
                ],
                'description' => $description,
            ],
            'response' => [],
        ];

        $body = $this->buildBody($operation);
        if ($body !== null) {
            $request['request']['body'] = $body;
        }

        return $request;
    }

    /**
     * @param array<array-key, mixed> $operation
     *
     * @return null|array<string, mixed>
     */
    private function buildBody(array $operation): ?array
    {
        $requestBody = is_array($operation['requestBody'] ?? null) ? $operation['requestBody'] : null;
        if ($requestBody === null) {
            return null;
        }
        $content = is_array($requestBody['content'] ?? null) ? $requestBody['content'] : [];
        foreach (['application/json', 'application/problem+json', 'text/plain', 'application/x-www-form-urlencoded', 'multipart/form-data'] as $mediaType) {
            if (!isset($content[$mediaType]) || !is_array($content[$mediaType])) {
                continue;
            }
            $schema = is_array($content[$mediaType]['schema'] ?? null) ? $content[$mediaType]['schema'] : [];
            $raw = json_encode($this->exampleFromSchema($schema), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            return [
                'mode' => 'raw',
                'raw' => $raw,
                'options' => ['raw' => ['language' => 'json']],
            ];
        }

        return ['mode' => 'raw', 'raw' => '{}', 'options' => ['raw' => ['language' => 'json']]];
    }

    /**
     * Build a deterministic minimal example value from a schema array.
     *
     * @param array<array-key, mixed> $schema
     */
    private function exampleFromSchema(array $schema): mixed
    {
        if (isset($schema['example'])) {
            return $schema['example'];
        }
        if (isset($schema['default'])) {
            return $schema['default'];
        }
        $enum = $schema['enum'] ?? null;
        if (is_array($enum) && isset($enum[0])) {
            return $enum[0];
        }
        $type = is_string($schema['type'] ?? null) ? $schema['type'] : 'object';
        if ($type === 'object') {
            $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
            $out = [];
            foreach ($properties as $name => $property) {
                if (is_string($name) && is_array($property)) {
                    $out[$name] = $this->exampleFromSchema($property);
                }
            }

            return $out === [] ? new \stdClass() : $out;
        }
        if ($type === 'array') {
            $items = is_array($schema['items'] ?? null) ? $schema['items'] : [];

            return [$this->exampleFromSchema($items)];
        }
        if ($type === 'integer' || $type === 'number') {
            return isset($schema['minimum']) && is_numeric($schema['minimum']) ? $schema['minimum'] : 1;
        }
        if ($type === 'boolean') {
            return true;
        }

        return ($schema['format'] ?? null) === 'uuid'
            ? '00000000-0000-4000-8000-000000000000'
            : 'string';
    }

    /**
     * @param array<array-key, mixed> $securitySchemes
     *
     * @return null|array<string, mixed>
     */
    private function buildAuth(array $securitySchemes): ?array
    {
        foreach ($securitySchemes as $scheme) {
            if (!is_array($scheme)) {
                continue;
            }
            $type = is_string($scheme['type'] ?? null) ? $scheme['type'] : '';
            if ($type === 'http') {
                $httpScheme = is_string($scheme['scheme'] ?? null) ? $scheme['scheme'] : '';
                if ($httpScheme === 'bearer') {
                    return [
                        'type' => 'bearer',
                        'bearer' => [['key' => 'token', 'value' => '<bearer-token>', 'type' => 'string']],
                    ];
                }
                if ($httpScheme === 'basic') {
                    return [
                        'type' => 'basic',
                        'basic' => [
                            ['key' => 'username', 'value' => '<username>', 'type' => 'string'],
                            ['key' => 'password', 'value' => '<password>', 'type' => 'string'],
                        ],
                    ];
                }
            }
            if ($type === 'apiKey') {
                $in = is_string($scheme['in'] ?? null) ? $scheme['in'] : 'header';

                return [
                    'type' => 'apikey',
                    'apikey' => [
                        ['key' => 'in', 'value' => $in, 'type' => 'string'],
                        ['key' => 'key', 'value' => '<api-key-name>', 'type' => 'string'],
                        ['key' => 'value', 'value' => '<api-key-value>', 'type' => 'string'],
                    ],
                ];
            }
        }

        return null;
    }
}
