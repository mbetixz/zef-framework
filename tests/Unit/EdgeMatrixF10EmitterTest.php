<?php

declare(strict_types=1);

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Exception\InvalidConfigurationException;
use Zef\Framework\Http\Response;
use Zef\Framework\MiddlewareDefinition;
use Zef\Framework\ResponseEmitter;

/**
 * Fase 10 — kurikulum edge-case adversarial: Kernel kecil (MiddlewareDefinition,
 * ResponseEmitter) yang terobservasi via CLI (ob_start), tanpa SAPI header.
 *
 * @internal
 */
final class EdgeMatrixF10EmitterTest extends TestCase
{
    /** MiddlewareDefinition:19/:38/:42/:60/:63 — default, kunci, reindex, dan kode eksepsi eksak. */
    public function testMiddlewareDefinitionCanonicalisation(): void
    {
        self::assertSame(0, new MiddlewareDefinition('svc.mw')->priority, 'prioritas default wajib 0');

        $both = MiddlewareDefinition::fromArray(['service' => 'svc.a', 'id' => 'svc.b', 'priority' => 5]);
        self::assertSame('svc.a', $both->serviceId, "kunci 'service' wajib menang atas 'id'");
        self::assertSame(5, $both->priority);

        $default = MiddlewareDefinition::fromArray(['service' => 'svc.c']);
        self::assertSame(0, $default->priority, 'fromArray tanpa prioritas wajib 0');

        $tags = MiddlewareDefinition::fromArray(['service' => 'svc.d', 'tags' => ['z' => 't1', 'a' => 't2']]);
        self::assertSame(['t1', 't2'], $tags->tags, 'tags wajib direindex jadi list');

        try {
            MiddlewareDefinition::fromArray(['service' => 'svc.e', 'priority' => []]);
            self::fail('Expected non-numeric priority rejection.');
        } catch (InvalidConfigurationException $e) {
            self::assertSame(0, $e->getCode(), 'kode eksepsi wajib 0');
        }
    }

    /** ResponseEmitter:60-:83 — body wajib terkirim utuh dalam chunk 8192. */
    public function testEmitterStreamsFullBodyInChunks(): void
    {
        $payload = \str_repeat('x', 20000);
        $response = new Response(200, ['Content-Type' => 'text/plain'], $payload);
        \ob_start();
        new ResponseEmitter()->emit($response);
        $out = (string) \ob_get_clean();
        self::assertSame($payload, $out, 'body 20000 byte wajib terkirim utuh (loop chunk tidak boleh putus/kurang)');
    }

    /** ResponseEmitter:64-:68 — 204/304 wajib tanpa output body. */
    public function testEmitterSuppressesBodyForBodylessStatuses(): void
    {
        $emitter = new ResponseEmitter();
        foreach ([204, 304] as $status) {
            \ob_start();
            $emitter->emit(new Response($status, [], 'must-not-appear'));
            self::assertSame('', (string) \ob_get_clean(), "status {$status} wajib tanpa body");
        }
    }
}
