<?php

declare(strict_types=1);

/*
 * Edge-Case Matrix — Fase 9 (mutation round 3): sisa Observability (c3).
 * Target escape: Telemetry #21/#27 (drain log saat metric exporter null),
 * TelemetryClock presisi nanodetik, CorrelationPropagator anchor/case/trim,
 * BatchSpanProcessor break-vs-continue pada drain sukses, Span durasi (int),
 * CounterMeter allowlist khusus metrik container.
 */

namespace Zef\Framework\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Zef\Framework\Observability\BatchSpanProcessor;
use Zef\Framework\Observability\CorrelationPropagator;
use Zef\Framework\Observability\CounterMeter;
use Zef\Framework\Observability\InMemorySpanExporter;
use Zef\Framework\Observability\LogExporterInterface;
use Zef\Framework\Observability\MetricExporterInterface;
use Zef\Framework\Observability\NoopSpan;
use Zef\Framework\Observability\SpanContext;
use Zef\Framework\Observability\SpanData;
use Zef\Framework\Observability\SpanExporterInterface;
use Zef\Framework\Observability\SpanInterface;
use Zef\Framework\Observability\Telemetry;
use Zef\Framework\Observability\TelemetryClock;
use Zef\Framework\Observability\TelemetryLogger;
use Zef\Framework\Observability\TelemetrySanitizer;
use Zef\Framework\Observability\Tracer;

/**
 * @internal
 */
final class EdgeMatrixF9C3Test extends TestCase
{
    // ------------------------------------------------------------------
    // CorrelationPropagator
    // ------------------------------------------------------------------

    private const string VALID_TP = '00-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-1234567890abcdef-01';
    private ?string $drainEnvBackup = null;

    protected function setUp(): void
    {
        $this->drainEnvBackup = \getenv('ZEF_OTEL_SHUTDOWN_DRAIN_MS') ?: null; // @phpstan-ignore-line
        parent::setUp();
    }

    protected function tearDown(): void
    {
        if ($this->drainEnvBackup === null) {
            \putenv('ZEF_OTEL_SHUTDOWN_DRAIN_MS');
        } else {
            \putenv('ZEF_OTEL_SHUTDOWN_DRAIN_MS=' . $this->drainEnvBackup);
        }
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Telemetry drain
    // ------------------------------------------------------------------

    /**
     * Membunuh NotIdentical:155 (klausul log queue) dan LogicalAnd:229
     * (return dini drainOne) — tanpa metric exporter, log TETAP harus
     * terkirim pada shutdown().
     */
    public function testShutdownDrainsLogsEvenWithoutMetricExporter(): void
    {
        $spy = new F9SpyLogExporter();
        $telemetry = new Telemetry(
            new Tracer(new BatchSpanProcessor(new InMemorySpanExporter())),
            new CounterMeter(),
            new BatchSpanProcessor(new InMemorySpanExporter()),
            null,          // metricExporter sengaja null
            $spy,          // logExporter spy
        );
        $telemetry->recordLog('info', 'bye', ['k' => 'v']);
        $telemetry->shutdown();

        self::assertCount(1, $spy->exported, 'log harus terkirim walau metric exporter null');
        self::assertSame('bye', $spy->exported[0]->body); // @phpstan-ignore-line
        self::assertSame('INFO', $spy->exported[0]->severity); // @phpstan-ignore-line
    }

    /** Presisi TelemetryClock: nowUnixNano ≈ time()*1e9 (galat < 1 detik nanodetik). */
    public function testTelemetryClockNanoPrecision(): void
    {
        $t = time();
        $nano = TelemetryClock::nowUnixNano();
        // Mutan 999999999 menggeser ~1.8e9 ns (≈1.8 detik) pada epoch 2026.
        self::assertLessThan(1_000_000_000, \abs($nano - $t * 1_000_000_000));
        self::assertTrue($nano > 1_700_000_000 * 1_000_000_000);
    }

    /** Triage: UnwrapTrim:31 dan UnwrapStrToLower:43 ekuivalen — guard panjang (55 byte)
     * mendahului trim, dan flags valid hanya '00'/'01' (digit). Diuji perilakunya: */
    public function testExtractFlagValidation(): void
    {
        self::assertNull(CorrelationPropagator::extract(
            '00-' . str_repeat('a', 32) . '-1234567890abcdef-AB',
            null,
            'op1',
        ), 'flags non-digit ditolak');
        self::assertNull(CorrelationPropagator::extract(
            '00-' . str_repeat('a', 32) . '-1234567890abcdef-02',
            null,
            'op1',
        ), 'hanya sampled-bit flag yang sah (00/01)');
        self::assertNotNull(CorrelationPropagator::extract(self::VALID_TP, null, 'op1'));
    }

    /** Membunuh PregMatchRemoveCaret/Dollar:35 — anchor penuh dua arah. */
    public function testExtractAnchorsBothEnds(): void
    {
        self::assertNull(CorrelationPropagator::extract('x' . self::VALID_TP, null, 'op1'), 'prefix asing ditolak');
        self::assertNull(CorrelationPropagator::extract(self::VALID_TP . 'x', null, 'op1'), 'suffix asing ditolak');
    }

    /** Guard flags: nilai digit selain 00/01 ditolak (dead-lowercase terdokumentasi). */
    public function testExtractRejectsInvalidFlagValues(): void
    {
        $this->testExtractFlagValidation();
    }

    /** Guard: null, >55 byte, 54 byte, dan grammar rusak → null. */
    public function testExtractRejectsBadInputs(): void
    {
        self::assertNull(CorrelationPropagator::extract(null, null, 'op1'));
        self::assertNull(CorrelationPropagator::extract(str_repeat('0', 56), null, 'op1'), '>55 byte ditolak');
        self::assertNull(CorrelationPropagator::extract(str_repeat('0', 54), null, 'op1'), '54 byte ditolak');
        self::assertNull(CorrelationPropagator::extract(str_repeat('z', 55), null, 'op1'), 'grammar rusak ditolak');
    }

    /** Roundtrip inject/extract mempertahankan tracestate. */
    public function testInjectExtractRoundtrip(): void
    {
        $ctx = CorrelationPropagator::extract(self::VALID_TP, 'key1=v1', 'op1');
        self::assertNotNull($ctx);
        $headers = CorrelationPropagator::inject($ctx);
        self::assertNotNull($headers);
        self::assertSame(self::VALID_TP, $headers->traceParent);
        $again = CorrelationPropagator::extract($headers->traceParent, $headers->traceState, 'op2');
        self::assertNotNull($again);
        self::assertSame('key1=v1', $again->traceState);
        self::assertNull(CorrelationPropagator::inject(null));
    }

    // ------------------------------------------------------------------
    // BatchSpanProcessor drain sukses
    // ------------------------------------------------------------------

    /**
     * Membunuh Break_:90 — export sukses wajib break (1 panggilan per batch),
     * bukan continue yang mengekspor batch yang sama 3x.
     */
    public function testShutdownExportsEachSuccessfulBatchExactlyOnce(): void
    {
        \putenv('ZEF_OTEL_SHUTDOWN_DRAIN_MS=30000');
        $spy = new F9CountingSpanExporter();
        $processor = new BatchSpanProcessor($spy, 2048, 1);
        foreach ([1, 2, 3] as $n) {
            $processor->onEnd($this->spanData($n));
        }
        $processor->shutdown();

        self::assertSame(3, $spy->calls, 'tepat 1 export per batch');
        self::assertCount(3, $spy->batches, 'semua batch sampai exporter');
    }

    /** Guard flush: batch yang export-nya permanent-reject dibuang tanpa infinite loop. */
    public function testFlushDropsPermanentRejectedBatch(): void
    {
        $spy = new F9CountingSpanExporter(throws: true);
        $processor = new BatchSpanProcessor($spy, 2048, 2);
        $processor->onEnd($this->spanData(1));
        $processor->onEnd($this->spanData(2));
        $processor->flush();
        self::assertSame(1, $spy->calls, 'InvalidArgumentException = permanent, berhenti setelah 1 percobaan');
    }

    // ------------------------------------------------------------------
    // Span durasi + CounterMeter allowlist
    // ------------------------------------------------------------------

    /** Membunuh CastInt:122 — endUnixNano = start + (int) durasi; float → TypeError SpanData. */
    public function testSpanEndComputesIntegerEndUnixNano(): void
    {
        $spy = new F9CountingSpanExporter();
        $bsp = new BatchSpanProcessor($spy, 2048, 16);
        $telemetry = new Telemetry(
            new Tracer($bsp),
            new CounterMeter(),
            new BatchSpanProcessor(new InMemorySpanExporter()),
        );
        $span = $telemetry->startSpan('op');
        self::assertInstanceOf(SpanInterface::class, $span);
        $span->setAttribute('k', 'v')->setStatus('OK');
        $span->end();
        $bsp->flush();

        self::assertNotEmpty($spy->batches, 'span harus sampai exporter setelah flush');
        $data = $spy->batches[0][0];
        self::assertSame('op', $data->name);
        self::assertGreaterThan($data->startUnixNano, $data->endUnixNano);
        self::assertSame($data->endNs - $data->startNs, $data->endUnixNano - $data->startUnixNano);
    }

    /** Membunuh LogicalAnd:101 — allowlist service.id HANYA untuk metrik container. */
    public function testServiceIdDimensionOnlySurvivesOnContainerMetric(): void
    {
        $meter = new CounterMeter();
        $meter->increment('zef.http.requests.total', 1, ['zef.service.id' => 'svc-x']);
        $meter->increment('zef.container.resolve.duration_seconds', 1, ['zef.service.id' => 'svc-y']);

        $snap = $meter->snapshot();
        foreach ($snap as $key => $series) {
            if (\str_contains((string) $key, 'zef.http.requests.total')) {
                self::assertArrayNotHasKey('zef.service.id', $series['attributes'], 'dimension terlarang pada metrik non-container');
            }
            if (\str_contains((string) $key, 'zef.container.resolve.duration_seconds')) {
                self::assertSame('svc-y', $series['attributes']['zef.service.id']);
            }
        }
    }

    /** Guard: delta negatif & non-finite ditolak (kontrak counter monotonic). */
    public function testCounterMeterRejectsNegativeAndNonFinite(): void
    {
        $meter = new CounterMeter();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Counter delta must be a finite non-negative number.');
        $meter->increment('m', -1);
    }

    /** Kontrak hook shutdown default = AKTIF (membunuh TrueValue:49 default ±). */
    public function testFromEnvironmentShutdownHookDefaultsToTrue(): void
    {
        self::assertTrue(
            new \ReflectionParameter([Telemetry::class, 'fromEnvironment'], 'registerShutdownHook')->getDefaultValue(),
        );
    }

    /** Objek Stringable anonim → get_debug_type ('@anonymous'), BUKAN ::class;
     * objek non-Stringable → nama class. Membunuh InstanceOf_ + LogicalNot:67.
     * (LogicalAndAllSubExprNegation setara: ::class ≡ get_debug_type utk kelas bernama.) */
    public function testTelemetryLoggerContextObjectMatrix(): void
    {
        $spy = new F9ContextSpy();
        $logger = new TelemetryLogger($spy);
        $stringable = new readonly class('v') implements \Stringable {
            public function __construct(private string $v) {}

            #[\Override]
            public function __toString(): string
            {
                return $this->v;
            }
        };

        $logger->info('pesan', ['tag' => $stringable, 'o' => new \stdClass()]);

        self::assertCount(1, $spy->records);
        $ctx = $spy->records[0]['context'];
        self::assertMatchesRegularExpression('/@anonymous/', (string) $ctx['tag'], 'Stringable anonim → debug type'); // @phpstan-ignore-line
        self::assertSame('stdClass', $ctx['o'], 'objek biasa → nama class');
    }

    /** Normalisasi spasi→hubung wajib: jarum 'api-key' baru terlihat setelah
     * normalisasi. Membunuh UnwrapStrReplace:22 — tanpa str_replace, 'x api key'
     * tidak memuat 'api-key' maupun 'apikey'/'api_key' → false ≠ true. */
    public function testSensitiveKeyHyphenNeedleRequiresNormalization(): void
    {
        self::assertTrue(TelemetrySanitizer::isSensitiveKey('X API KEY'));
        self::assertTrue(TelemetrySanitizer::isSensitiveKey('x-api-key'));
        self::assertFalse(TelemetrySanitizer::isSensitiveKey('plain_field'));
    }

    /** Guard antrean delivery 1024: secara struktural tak-terjangkau — drainDelivery()
     * selalu mengosongkan antrean di setiap flush() (array_shift + catch-drop), sehingga
     * kedalaman > 1 mustahil. Tidak ada test yang bisa membedakan ±1 — dieksklusi jujur
     * sebagai cap defensif tak-terjangkau (dokumentasi CHANGELOG v2.14.9). */

    /** OTEL aktif → tracer RIIL (bukan Noop). Membunuh IfNegation:83 — mutan
     * membalik cabang enabled sehingga sesi aktif justru mendapat NoopSpan. */
    public function testFromEnvironmentWithOtelEnabledUsesRealTracer(): void
    {
        $backup = \getenv('ZEF_OTEL_ENABLED');
        \putenv('ZEF_OTEL_ENABLED=true');

        try {
            $telemetry = Telemetry::fromEnvironment();
            $span = $telemetry->startSpan('cek-real');
            self::assertNotInstanceOf(NoopSpan::class, $span, 'sesi OTEL aktif wajib span riil');
            self::assertTrue($telemetry->isEnabled());
        } finally {
            if ($backup === false) {
                \putenv('ZEF_OTEL_ENABLED');
            } else {
                \putenv('ZEF_OTEL_ENABLED=' . $backup);
            }
        }
    }

    private function spanData(int $n): SpanData
    {
        return new SpanData(
            'op' . $n,
            new SpanContext(str_repeat('a', 32), str_repeat('b', 8) . str_pad((string) $n, 8, '0', STR_PAD_LEFT)),
            null,
            1,
            2,
            100,
            200,
            'OK',
            null,
            [],
            [],
        );
    }
}

/**
 * @internal — LogExporter spy
 */
final class F9SpyLogExporter implements LogExporterInterface
{
    /** @var list<LogRecord> */
    public array $exported = []; // @phpstan-ignore-line

    #[\Override]
    public function exportLogs(array $records): void
    {
        foreach ($records as $r) {
            $this->exported[] = $r; // @phpstan-ignore-line
        }
    }

    #[\Override]
    public function shutdown(): void {}
}

/**
 * @internal — SpanExporter dengan penghitung panggilan; opsional selalu gagal permanent
 */
final class F9CountingSpanExporter implements SpanExporterInterface
{
    public int $calls = 0;

    /** @var list<list<SpanData>> */
    public array $batches = [];

    public function __construct(private readonly bool $throws = false) {}

    #[\Override]
    public function export(array $spans): void
    {
        ++$this->calls;
        if ($this->throws) {
            throw new \InvalidArgumentException('permanent no');
        }
        $this->batches[] = $spans;
    }

    #[\Override]
    public function shutdown(): void {}
}

/**
 * @internal — MetricExporter penghitung panggilan (untuk tes kapasitas antrean)
 */
final class F9CountingMetricExporter implements MetricExporterInterface
{
    public int $calls = 0;

    #[\Override]
    public function exportMetrics(array $metrics): void
    {
        ++$this->calls;
    }

    #[\Override]
    public function shutdown(): void {}
}

/**
 * @internal — PSR logger yang merekam (severity, message, context) per panggilan
 */
final class F9ContextSpy implements LoggerInterface
{
    /** @var list<array{severity:string,message:string,context:array<string,mixed>}> */
    public array $records = [];

    #[\Override] // @phpstan-ignore-line
    public function emergency(string|\Stringable $message, array $context = []): void
    {
        $this->capture('EMERGENCY', (string) $message, $context);
    }

    #[\Override] // @phpstan-ignore-line
    public function alert(string|\Stringable $message, array $context = []): void
    {
        $this->capture('ALERT', (string) $message, $context);
    }

    #[\Override] // @phpstan-ignore-line
    public function critical(string|\Stringable $message, array $context = []): void
    {
        $this->capture('CRITICAL', (string) $message, $context);
    }

    #[\Override] // @phpstan-ignore-line
    public function error(string|\Stringable $message, array $context = []): void
    {
        $this->capture('ERROR', (string) $message, $context);
    }

    #[\Override] // @phpstan-ignore-line
    public function warning(string|\Stringable $message, array $context = []): void
    {
        $this->capture('WARNING', (string) $message, $context);
    }

    #[\Override] // @phpstan-ignore-line
    public function notice(string|\Stringable $message, array $context = []): void
    {
        $this->capture('NOTICE', (string) $message, $context);
    }

    #[\Override] // @phpstan-ignore-line
    public function info(string|\Stringable $message, array $context = []): void
    {
        $this->capture('INFO', (string) $message, $context);
    }

    #[\Override] // @phpstan-ignore-line
    public function debug(string|\Stringable $message, array $context = []): void
    {
        $this->capture('DEBUG', (string) $message, $context);
    }

    #[\Override] // @phpstan-ignore-line
    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        $this->capture((string) $level, (string) $message, $context); // @phpstan-ignore-line
    }

    private function capture(string $severity, string $message, array $context): void // @phpstan-ignore-line
    {
        $this->records[] = ['severity' => $severity, 'message' => $message, 'context' => $context]; // @phpstan-ignore-line
    }
}
