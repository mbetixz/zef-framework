<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.20.0 — OpenAPI serialization: JSON stability + the
 * dependency-free deterministic YAML emitter (adversarial quoting).
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Zef\Framework\OpenApi\JsonSpecificationSerializer;
use Zef\Framework\OpenApi\SpecificationException;
use Zef\Framework\OpenApi\YamlSpecificationSerializer;

/**
 * @internal
 */
final class OpenApiSerializationTest extends TestCase
{
    public function testJsonPrettyAndCompact(): void
    {
        $serializer = new JsonSpecificationSerializer();
        $spec = ['openapi' => '3.1.0', 'info' => ['title' => 'API/Ünïcode ✓', 'version' => '1.0.0']];
        $pretty = $serializer->serialize($spec, true);
        self::assertStringContainsString("\n", $pretty);
        self::assertStringContainsString('API/Ünïcode ✓', $pretty); // unescaped unicode
        $compact = $serializer->serialize($spec, false);
        self::assertSame('{"openapi":"3.1.0","info":{"title":"API/Ünïcode ✓","version":"1.0.0"}}', $compact);
    }

    public function testJsonRoundtrip(): void
    {
        $serializer = new JsonSpecificationSerializer();
        $spec = ['openapi' => '3.1.0', 'paths' => ['/' => ['get' => ['responses' => ['200' => ['description' => 'ok']]]]]];
        $restored = $serializer->deserialize($serializer->serialize($spec));
        self::assertSame($spec, $restored);
    }

    public function testJsonDeserializeRejectsGarbage(): void
    {
        $serializer = new JsonSpecificationSerializer();
        $this->expectException(SpecificationException::class);
        $serializer->deserialize('{nope');
    }

    public function testJsonDeserializeRejectsScalars(): void
    {
        $serializer = new JsonSpecificationSerializer();
        $this->expectException(SpecificationException::class);
        $serializer->deserialize('"just a string"');
    }

    public function testJsonSerializeRejectsResources(): void
    {
        $serializer = new JsonSpecificationSerializer();
        $resource = fopen('php://memory', 'rb');
        self::assertIsResource($resource);
        $this->expectException(SpecificationException::class);

        try {
            $serializer->serialize(['r' => $resource]);
        } finally {
            fclose($resource);
        }
    }

    public function testYamlScalarsAndNesting(): void
    {
        $serializer = new YamlSpecificationSerializer();
        $yaml = $serializer->serialize([
            'openapi' => '3.1.0',
            'info' => ['title' => 'API', 'version' => '1.0.0'],
            'paths' => [],
            'tags' => [['name' => 'users', 'description' => 'User stuff']],
        ]);
        $expected = <<<'YAML'
            openapi: 3.1.0
            info:
              title: API
              version: 1.0.0
            paths: []
            tags:
              - name: users
                description: User stuff
            YAML;
        self::assertSame($expected . "\n", $yaml);
    }

    public function testYamlQuotesLookalikeStrings(): void
    {
        $serializer = new YamlSpecificationSerializer();
        $yaml = $serializer->serialize([
            'a' => 'null',
            'b' => 'true',
            'c' => '123',
            'd' => '1.50',
            'e' => 'hello: world',
            'f' => '#comment',
            'g' => '- dash start',
            'h' => ' spaced ',
            'i' => '',
            'j' => "line1\nline2",
            'k' => 'quote"inside',
            'l' => 'yes',
            'm' => '~',
            'n' => 'plain_safe',
        ]);
        self::assertStringContainsString('a: "null"', $yaml);
        self::assertStringContainsString('b: "true"', $yaml);
        self::assertStringContainsString('c: "123"', $yaml);
        self::assertStringContainsString('d: "1.50"', $yaml);
        self::assertStringContainsString('e: "hello: world"', $yaml);
        self::assertStringContainsString('f: "#comment"', $yaml);
        self::assertStringContainsString('g: "- dash start"', $yaml);
        self::assertStringContainsString('h: " spaced "', $yaml);
        self::assertStringContainsString('i: ""', $yaml);
        self::assertStringContainsString('j: "line1\nline2"', $yaml);
        self::assertStringContainsString('k: "quote\"inside"', $yaml);
        self::assertStringContainsString('l: "yes"', $yaml);
        self::assertStringContainsString('m: "~"', $yaml);
        self::assertStringContainsString('"n": plain_safe', $yaml);
    }

    public function testYamlBooleansNumbersNulls(): void
    {
        $serializer = new YamlSpecificationSerializer();
        $yaml = $serializer->serialize([
            'flag' => true,
            'off' => false,
            'count' => 42,
            'price' => 9,
            'nothing' => null,
            'nested' => ['deep' => ['deeper' => [1, 2, 3]]],
        ]);
        self::assertStringContainsString('flag: true', $yaml);
        self::assertStringContainsString('"off": false', $yaml);
        self::assertStringContainsString('count: 42', $yaml);
        self::assertStringContainsString('price: 9', $yaml);
        self::assertStringContainsString('nothing: null', $yaml);
        self::assertStringContainsString('  - 1', $yaml);
    }

    public function testYamlEmptyStructures(): void
    {
        $serializer = new YamlSpecificationSerializer();
        $yaml = $serializer->serialize(['map' => [], 'list' => [], 'empty' => '']);
        self::assertStringContainsString('map: []', $yaml);
        self::assertStringContainsString('list: []', $yaml);
        self::assertStringContainsString('empty: ""', $yaml);
    }

    public function testYamlListOfMaps(): void
    {
        $serializer = new YamlSpecificationSerializer();
        $yaml = $serializer->serialize([
            'servers' => [
                ['url' => 'https://api.example.com', 'description' => 'prod'],
                ['url' => 'https://staging.example.com'],
            ],
        ]);
        self::assertStringContainsString('  - url: https://api.example.com', $yaml);
        self::assertStringContainsString('    description: prod', $yaml);
        self::assertStringContainsString('  - url: https://staging.example.com', $yaml);
    }

    public function testYamlRejectsObjects(): void
    {
        $serializer = new YamlSpecificationSerializer();
        $this->expectException(SpecificationException::class);
        $serializer->serialize(['x' => new \stdClass()]);
    }

    public function testYamlDeterministic(): void
    {
        $serializer = new YamlSpecificationSerializer();
        $spec = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'T', 'version' => '1'],
            'paths' => ['/' => ['get' => ['responses' => ['200' => ['description' => 'ok']]]]],
        ];
        self::assertSame($serializer->serialize($spec), $serializer->serialize($spec));
    }

    public function testYamlRoundtripsThroughSymfonyParserForSanity(): void
    {
        // symfony/yaml is available in the DEV toolchain (deptrac); using it
        // here as an independent oracle for our emitter's correctness.
        if (!class_exists(Yaml::class)) {
            self::markTestSkipped('symfony/yaml not installed');
        }
        $serializer = new YamlSpecificationSerializer();
        $json = new JsonSpecificationSerializer();

        /** @var array<string, mixed> $spec */
        $spec = $json->deserialize($json->serialize([
            'openapi' => '3.1.0',
            'info' => ['title' => 'Quoted: yes', 'version' => '2.20.0'],
            'paths' => ['/a' => ['get' => ['responses' => ['200' => ['description' => 'ok: fine']]]]],
            'note' => '123',
        ]));
        $parsed = Yaml::parse($serializer->serialize($spec));
        self::assertSame($spec, $parsed);
    }

    public function testYamlDepthLimit(): void
    {
        $serializer = new YamlSpecificationSerializer();
        $deep = ['x' => 'leaf'];
        for ($i = 0; $i < 600; ++$i) {
            $deep = ['n' => $deep];
        }
        $this->expectException(SpecificationException::class);
        $serializer->serialize($deep);
    }
}
