<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Infrastructure layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi;

/**
 * Structural validation of an assembled OpenAPI document.
 *
 * This is NOT a full JSON-Schema validator: it checks the invariants the
 * framework itself relies on when serving/exporting documents (valid
 * version string, present info, resolvable refs, unique operationIds,
 * non-empty responses, sane parameters and security schemes) and returns
 * deterministic, human-readable error strings.
 */
final class OpenApiSpecValidator
{
    private const array METHODS = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'trace'];

    /**
     * @param array<string, mixed> $spec
     *
     * @return list<string> empty list when the document is valid
     */
    public function validate(array $spec): array
    {
        $errors = [];
        $version = $spec['openapi'] ?? null;
        if (!is_string($version) || preg_match('/^3\.\d+\.\d+$/', $version) !== 1) {
            $errors[] = 'Field "openapi" must be a semver string like "3.1.0".';
        }

        $info = $spec['info'] ?? null;
        if (!is_array($info)) {
            $errors[] = 'Field "info" must be an object.';
            $info = [];
        }
        $title = $info['title'] ?? null;
        if (!is_string($title) || trim($title) === '') {
            $errors[] = 'Field "info.title" must be a non-empty string.';
        }
        $infoVersion = $info['version'] ?? null;
        if (!is_string($infoVersion) || trim($infoVersion) === '') {
            $errors[] = 'Field "info.version" must be a non-empty string.';
        }

        $paths = $spec['paths'] ?? null;
        if (!is_array($paths)) {
            $errors[] = 'Field "paths" must be an object.';
            $paths = [];
        }

        $schemaNames = [];
        $components = is_array($spec['components'] ?? null) ? $spec['components'] : [];
        $schemas = is_array($components['schemas'] ?? null) ? $components['schemas'] : [];
        foreach ($schemas as $name => $schema) {
            if (is_string($name)) {
                $schemaNames[$name] = true;
            }
        }

        $operationIds = [];
        ksort($paths, SORT_STRING);
        foreach ($paths as $path => $operations) {
            if (!is_string($path) || $path === '' || $path[0] !== '/') {
                $errors[] = "Path key '" . $this->stringify($path) . "' must be a non-empty string beginning with '/'.";

                continue;
            }
            if (!is_array($operations)) {
                $errors[] = "Path '{$path}' must map to an object of operations.";

                continue;
            }
            foreach ($operations as $method => $operation) {
                if (!is_string($method) || !in_array($method, self::METHODS, true)) {
                    $errors[] = "Path '{$path}' contains an unknown HTTP method '" . $this->stringify($method) . "'.";

                    continue;
                }
                $where = "[{$method}] {$path}";
                if (!is_array($operation)) {
                    $errors[] = "Operation {$where} must be an object.";

                    continue;
                }
                $operationId = $operation['operationId'] ?? null;
                if (!is_string($operationId) || trim($operationId) === '') {
                    $errors[] = "Operation {$where} must define a non-empty operationId.";
                } elseif (isset($operationIds[$operationId])) {
                    $errors[] = "Duplicate operationId '{$operationId}' (also used by {$operationIds[$operationId]}).";
                } else {
                    $operationIds[$operationId] = $where;
                }
                $responses = $operation['responses'] ?? null;
                if (!is_array($responses) || $responses === []) {
                    $errors[] = "Operation {$where} must define at least one response.";
                } else {
                    foreach ($responses as $status => $response) {
                        if (!is_array($response) || !is_string($response['description'] ?? null) || $response['description'] === '') {
                            $errors[] = "Operation {$where} response '{$this->stringify($status)}' must carry a non-empty description.";
                        }
                    }
                }
                $parameters = is_array($operation['parameters'] ?? null) ? $operation['parameters'] : [];
                foreach ($parameters as $parameter) {
                    if (!is_array($parameter) || !is_string($parameter['name'] ?? null) || $parameter['name'] === '') {
                        $errors[] = "Operation {$where} contains a parameter without a non-empty name.";

                        continue;
                    }
                    $in = is_string($parameter['in'] ?? null) ? $parameter['in'] : '';
                    if (!in_array($in, ['query', 'header', 'path', 'cookie'], true)) {
                        $errors[] = "Operation {$where} parameter '{$parameter['name']}' has invalid location '{$in}'.";
                    } elseif ($in === 'path' && ($parameter['required'] ?? false) !== true && str_contains($path, '{' . $parameter['name'] . '}')) {
                        $errors[] = "Operation {$where} path parameter '{$parameter['name']}' must set required: true.";
                    }
                }
                $security = is_array($operation['security'] ?? null) ? $operation['security'] : [];
                foreach ($security as $requirement) {
                    if (!is_array($requirement)) {
                        $errors[] = "Operation {$where} contains a malformed security requirement.";
                    }
                }
            }
        }

        foreach ($schemas as $name => $schema) {
            if (!is_string($name) || $name === '') {
                $errors[] = 'Component schema names must be non-empty strings.';

                continue;
            }
            if (!is_array($schema)) {
                $errors[] = "Component schema '{$name}' must be an object.";
            }
        }

        $this->walkRefs($spec, $schemaNames, $errors);

        $securitySchemes = is_array($components['securitySchemes'] ?? null) ? $components['securitySchemes'] : [];
        foreach ($securitySchemes as $name => $scheme) {
            if (!is_string($name) || $name === '') {
                $errors[] = 'Security scheme names must be non-empty strings.';

                continue;
            }
            if (!is_array($scheme) || !is_string($scheme['type'] ?? null)) {
                $errors[] = "Security scheme '{$name}' must be an object with a type.";

                continue;
            }
            $type = $scheme['type'];
            if ($type === 'http' && (!is_string($scheme['scheme'] ?? null) || $scheme['scheme'] === '')) {
                $errors[] = "Security scheme '{$name}' of type http must define a scheme.";
            }
            if ($type === 'apiKey' && !in_array($scheme['in'] ?? null, ['query', 'header', 'cookie'], true)) {
                $errors[] = "Security scheme '{$name}' of type apiKey must define in: query|header|cookie.";
            }
            if ($type === 'openIdConnect' && !is_string($scheme['openIdConnectUrl'] ?? null)) {
                $errors[] = "Security scheme '{$name}' of type openIdConnect must define openIdConnectUrl.";
            }
        }

        return $errors;
    }

    /**
     * Collect every $ref that cannot be resolved against component schemas.
     *
     * @param array<mixed, mixed> $node
     * @param array<string, true> $schemaNames
     * @param list<string> $errors
     */
    private function walkRefs(array $node, array $schemaNames, array &$errors, int $depth = 0): void
    {
        if ($depth > 256) {
            return;
        }
        foreach ($node as $value) {
            if (is_array($value)) {
                $ref = $value['$ref'] ?? null;
                if (is_string($ref) && preg_match('~^#/components/schemas/([^/]+)$~', $ref, $matches) === 1) {
                    if (!isset($schemaNames[$matches[1]])) {
                        $errors[] = "Unresolvable \$ref '{$ref}' (missing from components.schemas).";
                    }

                    continue;
                }
                $this->walkRefs($value, $schemaNames, $errors, $depth + 1);
            }
        }
    }

    private function stringify(mixed $value): string
    {
        return is_string($value) ? $value : (string) json_encode($value, JSON_UNESCAPED_SLASHES);
    }
}
