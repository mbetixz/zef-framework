<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.20.0 — OpenAPI HTTP adapters (SpecHandler ETag/304,
 * DocsUiHandler escaping/disable) and the CLI generate command.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Console\ConsoleIO;
use Zef\Framework\Http\ServerRequest;
use Zef\Framework\Http\Uri;
use Zef\Framework\OpenApi\Console\GenerateSpecCommand;
use Zef\Framework\OpenApi\Http\DocsUiHandler;
use Zef\Framework\OpenApi\Http\SpecHandler;
use Zef\Framework\OpenApi\Info;
use Zef\Framework\OpenApi\SpecificationBuilder;

/**
 * @internal
 */
final class OpenApiAdaptersTest extends TestCase
{
    public function testSpecHandlerServesJson(): void
    {
        $handler = new SpecHandler($this->spec());
        $response = $handler->handle(new ServerRequest('GET', new Uri('https://api.local/openapi.json')));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('"title": "Adapter API"', (string) $response->getBody());
        self::assertStringStartsWith('"', $response->getHeaderLine('ETag'));
    }

    public function testSpecHandlerEtagRoundtrip(): void
    {
        $handler = new SpecHandler($this->spec());
        $first = $handler->handle(new ServerRequest('GET', new Uri('https://api.local/openapi.json')));
        $etag = $first->getHeaderLine('ETag');
        $second = $handler->handle(new ServerRequest('GET', new Uri('https://api.local/openapi.json'), headers: ['If-None-Match' => $etag]));
        self::assertSame(304, $second->getStatusCode());
        self::assertSame('', (string) $second->getBody());
    }

    public function testSpecHandlerDifferentDocumentDifferentEtag(): void
    {
        $a = new SpecHandler($this->spec());
        $builder = new SpecificationBuilder(new Info(title: 'Other API', version: '1.0.0'));
        $b = new SpecHandler($builder->build());
        self::assertNotSame($a->handle(new ServerRequest('GET', new Uri('https://api.local/x')))->getHeaderLine('ETag'), $b->handle(new ServerRequest('GET', new Uri('https://api.local/x')))->getHeaderLine('ETag'));
    }

    public function testDocsUiHandlerServesHtmlWithEscaping(): void
    {
        $handler = new DocsUiHandler(specUrl: '/openapi.json" onload="evil', title: 'Docs <script>');
        $response = $handler->handle(new ServerRequest('GET', new Uri('https://api.local/docs')));
        $body = (string) $response->getBody();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringNotContainsString('<script>alert', $body);
        self::assertStringNotContainsString('onload="evil', $body);
        self::assertStringContainsString('&lt;script&gt;', $body);
        self::assertStringContainsString('SwaggerUIBundle', $body);
    }

    public function testDocsUiHandlerDisabledFallsThroughTo404(): void
    {
        $handler = new DocsUiHandler(enabled: false);
        $response = $handler->handle(new ServerRequest('GET', new Uri('https://api.local/docs')));
        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('disabled', (string) $response->getBody());
    }

    public function testGenerateSpecCommandWritesJson(): void
    {
        $io = new ConsoleIO();
        $out = sys_get_temp_dir() . '/zef-openapi-test-' . uniqid('', true);
        $command = new GenerateSpecCommand();
        $exit = $command->run($io, [
            ['method' => 'GET', 'pattern' => '/ping', 'handler' => 'core.ping', 'name' => null, 'segments' => []],
        ], null, ['output' => $out . '.json', 'format' => 'json', 'pretty' => 'true']);
        self::assertSame(0, $exit);
        $content = (string) file_get_contents($out . '.json');
        self::assertStringContainsString('"openapi": "3.1.0"', $content);
        self::assertStringContainsString('get.core.ping', $content);
        unlink($out . '.json'); // nosemgrep: php.lang.security.unlink-use
    }

    public function testGenerateSpecCommandWritesYamlAndPostman(): void
    {
        $io = new ConsoleIO();
        $base = sys_get_temp_dir() . '/zef-openapi-test-' . uniqid('', true);
        $command = new GenerateSpecCommand();
        $exit = $command->run($io, [
            ['method' => 'POST', 'pattern' => '/greet', 'handler' => 'core.greet', 'name' => null, 'segments' => []],
        ], null, [
            'output' => $base . '.yaml',
            'format' => 'yaml',
            'postman' => $base . '-postman.json',
            'base-url' => 'https://api.zef.dev',
        ]);
        self::assertSame(0, $exit);
        $yaml = (string) file_get_contents($base . '.yaml');
        self::assertStringContainsString('openapi: 3.1.0', $yaml);
        self::assertStringContainsString('servers:', $yaml);
        self::assertStringContainsString('https://api.zef.dev', $yaml);
        $postman = (string) file_get_contents($base . '-postman.json');
        self::assertStringContainsString('v2.1.0', $postman);
        unlink($base . '.yaml'); // nosemgrep: php.lang.security.unlink-use
        unlink($base . '-postman.json'); // nosemgrep: php.lang.security.unlink-use
    }

    public function testGenerateSpecCommandRejectsUnknownFormat(): void
    {
        $io = new ConsoleIO();
        $command = new GenerateSpecCommand();
        $exit = $command->run($io, [], null, ['format' => 'xml']);
        self::assertSame(1, $exit);
        self::assertStringContainsString('Unsupported --format', implode("\n", $io->errLog()));
    }

    public function testGenerateSpecCommandRejectsUnwritableTarget(): void
    {
        $io = new ConsoleIO();
        $command = new GenerateSpecCommand();
        $exit = $command->run($io, [
            ['method' => 'GET', 'pattern' => '/x', 'handler' => 'h', 'name' => null, 'segments' => []],
        ], null, ['output' => '/proc/self/definitely-not-a-dir/out.json']);
        self::assertSame(1, $exit);
        self::assertSame(1, count($io->errLog()));
    }

    public function testGenerateSpecCommandReportsExtractorErrors(): void
    {
        $io = new ConsoleIO();
        $command = new GenerateSpecCommand();
        $exit = $command->run($io, [
            ['method' => 'GET', 'pattern' => '/dup', 'handler' => 'h', 'name' => 'one', 'segments' => []],
            ['method' => 'GET', 'pattern' => '/dup', 'handler' => 'h', 'name' => 'two', 'segments' => []],
        ]);
        self::assertSame(1, $exit);
        self::assertStringContainsString('openapi:generate failed', implode("\n", $io->errLog()));
    }

    /** @return array<string, mixed> */
    private function spec(): array
    {
        $builder = new SpecificationBuilder(new Info(title: 'Adapter API', version: '2.20.0'));

        return $builder->build();
    }
}
