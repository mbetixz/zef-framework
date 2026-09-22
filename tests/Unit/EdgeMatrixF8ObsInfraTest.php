<?php

declare(strict_types=1);

/*
 * Edge-Case Matrix — Fase 8 (mutation round 3): Infrastructure\Observability.
 * Target escape baseline f8-infra-obs: OtlpHttpJsonExporter 68,
 * PrometheusRenderer 18. OTLP diuji end-to-end terhadap server HTTP in-process
 * (pcntl_fork) yang merekam path + RAW body → struktur payload dibedah persis.
 */

namespace Zef\Framework\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Observability\LogRecord;
use Zef\Framework\Observability\MeterInterface;
use Zef\Framework\Observability\OtlpHttpJsonExporter;
use Zef\Framework\Observability\PrometheusRenderer;
use Zef\Framework\Observability\SpanContext;
use Zef\Framework\Observability\SpanData;

/**
 * @internal
 */
final class EdgeMatrixF8ObsInfraTest extends TestCase
{
    // ------------------------------------------------------------------
    // OTLP end-to-end (server fork in-process)
    // ------------------------------------------------------------------

    /** Struktur payload traces persis: resource/scope/span/status/parent/events. */
    public function testTracePayloadStructureIsExact(): void
    {
        $this->runOtlpServerSession(2, function (string $base, string $sink): void {
            $exporter = new OtlpHttpJsonExporter($base, ['service.name' => 'svc/web'], 500);
            $trace = str_repeat('11', 16);
            $span = new SpanContext($trace, str_repeat('22', 8));
            $parent = new SpanContext($trace, str_repeat('33', 8));
            $data = new SpanData(
                name: 'op',
                context: $span,
                parent: $parent,
                startNs: 1,
                endNs: 2,
                startUnixNano: 100,
                endUnixNano: 200,
                status: 'ERROR',
                statusDescription: str_repeat('x', 1200),
                attributes: ['k' => 'v', 'n' => 7, 'f' => 1.5, 'b' => true, 'arr' => ['x', 'y']],
                events: [['name' => str_repeat('e', 300), 'time_unix_nano' => 55, 'attributes' => ['ea' => 1]]],
            );
            $exporter->export([$data]);

            $lines = $this->sinkLines($sink, 1);
            $body = $lines[0]['decoded'];
            self::assertSame('/v1/traces', $lines[0]['path']);

            $rs = $body['resourceSpans'];
            self::assertIsList($rs);
            self::assertSame(
                [['key' => 'service.name', 'value' => ['stringValue' => 'svc/web']]],
                $rs[0]['resource']['attributes'], // @phpstan-ignore-line
            );
            // JSON_UNESCAPED_SLASHES: slash TIDAK boleh di-escape di raw body
            self::assertStringContainsString('svc/web', $lines[0]['raw'], 'JSON_UNESCAPED_SLASHES wajib aktif');
            $ss = $rs[0]['scopeSpans']; // @phpstan-ignore-line
            self::assertSame(['name' => 'zef-observability'], $ss[0]['scope']); // @phpstan-ignore-line
            $out = $ss[0]['spans'][0]; // @phpstan-ignore-line
            self::assertSame($trace, $out['traceId']); // @phpstan-ignore-line
            self::assertSame(str_repeat('22', 8), $out['spanId']); // @phpstan-ignore-line
            self::assertSame('op', $out['name']); // @phpstan-ignore-line
            self::assertSame('SPAN_KIND_SERVER', $out['kind']); // @phpstan-ignore-line
            self::assertSame('100', $out['startTimeUnixNano']); // @phpstan-ignore-line
            self::assertSame('200', $out['endTimeUnixNano']); // @phpstan-ignore-line
            self::assertSame('STATUS_CODE_ERROR', $out['status']['code']); // @phpstan-ignore-line
            self::assertSame(1024, \strlen($out['status']['message']), 'status description dibatasi 1024 char'); // @phpstan-ignore-line
            self::assertSame(str_repeat('33', 8), $out['parentSpanId']); // @phpstan-ignore-line
            self::assertSame(256, \strlen($out['events'][0]['name']), 'event name dibatasi 256 char'); // @phpstan-ignore-line
            self::assertSame(
                ['name' => str_repeat('e', 253) . '…', 'timeUnixNano' => '55', 'attributes' => [['key' => 'ea', 'value' => ['intValue' => '1']]]],
                $out['events'][0], // @phpstan-ignore-line
            );
            self::assertSame(
                ['key' => 'n', 'value' => ['intValue' => '7']],
                $out['attributes'][1], // @phpstan-ignore-line
                'int menjadi intValue string',
            );
            self::assertSame(['key' => 'f', 'value' => ['doubleValue' => 1.5]], $out['attributes'][2]); // @phpstan-ignore-line
            self::assertSame(['key' => 'b', 'value' => ['boolValue' => true]], $out['attributes'][3]); // @phpstan-ignore-line
            self::assertSame(
                ['key' => 'arr', 'value' => ['arrayValue' => ['values' => [['stringValue' => 'x'], ['stringValue' => 'y']]]]],
                $out['attributes'][4], // @phpstan-ignore-line
            );

            // Span tanpa parent/desc/events → field opsional absen + status UNSET.
            $plain = new SpanData('p', new SpanContext($trace, str_repeat('44', 8)), null, 1, 2, 5, 6, 'OK', null, [], []);
            $exporter->export([$plain]);
            $lines = $this->sinkLines($sink, 2);
            $out2 = $lines[1]['decoded']['resourceSpans'][0]['scopeSpans'][0]['spans'][0]; // @phpstan-ignore-line
            self::assertArrayNotHasKey('parentSpanId', $out2); // @phpstan-ignore-line
            self::assertArrayNotHasKey('message', $out2['status']); // @phpstan-ignore-line
            self::assertArrayNotHasKey('events', $out2); // @phpstan-ignore-line
            self::assertSame('STATUS_CODE_OK', $out2['status']['code']); // @phpstan-ignore-line
        });
    }

    /** Status panah match: nilai tak dikenal → STATUS_CODE_UNSET. */
    public function testUnknownSpanStatusMapsToUnset(): void
    {
        $this->runOtlpServerSession(1, function (string $base, string $sink): void {
            $exporter = new OtlpHttpJsonExporter($base, [], 500);
            $trace = str_repeat('ab', 16);
            $data = new SpanData('s', new SpanContext($trace, str_repeat('cd', 8)), null, 1, 2, 5, 6, 'WEIRD', null, [], []);
            $exporter->export([$data]);
            $lines = $this->sinkLines($sink, 1);
            $out = $lines[0]['decoded']['resourceSpans'][0]['scopeSpans'][0]['spans'][0]; // @phpstan-ignore-line
            self::assertSame('STATUS_CODE_UNSET', $out['status']['code']); // @phpstan-ignore-line
        });
    }

    /** Struktur payload metrics: temporality 2, monotonic true, asDouble float, time string. */
    public function testMetricsPayloadStructureIsExact(): void
    {
        $this->runOtlpServerSession(1, function (string $base, string $sink): void {
            $exporter = new OtlpHttpJsonExporter($base, ['service.name' => 'm'], 500);
            $exporter->exportMetrics([ // @phpstan-ignore-line
                'requests.total' => ['sum' => 5, 'attributes' => ['code' => 200]],
            ]);
            $lines = $this->sinkLines($sink, 1);
            self::assertSame('/v1/metrics', $lines[0]['path']);
            $body = $lines[0]['decoded'];
            $rm = $body['resourceMetrics'];
            self::assertSame([['key' => 'service.name', 'value' => ['stringValue' => 'm']]], $rm[0]['resource']['attributes'], 'resource wajib ada di payload metrics'); // @phpstan-ignore-line
            self::assertSame(['name' => 'zef-observability'], $rm[0]['scopeMetrics'][0]['scope']); // @phpstan-ignore-line
            $metric = $rm[0]['scopeMetrics'][0]['metrics'][0]; // @phpstan-ignore-line
            self::assertSame('requests.total', $metric['name']); // @phpstan-ignore-line
            self::assertSame(2, $metric['sum']['aggregationTemporality']); // @phpstan-ignore-line
            self::assertTrue($metric['sum']['isMonotonic']); // @phpstan-ignore-line
            $dp = $metric['sum']['dataPoints'][0]; // @phpstan-ignore-line
            // Catatan triage: json_encode(5.0) === "5" tanpa PRESERVE_ZERO_FRACTION,
            // sehingga cast float tak-terobservasi lewat transport JSON.
            self::assertSame(5, $dp['asDouble']); // @phpstan-ignore-line
            self::assertIsString($dp['timeUnixNano']); // @phpstan-ignore-line
            self::assertMatchesRegularExpression('/^\d+$/', $dp['timeUnixNano']);
            self::assertSame([['key' => 'code', 'value' => ['intValue' => '200']]], $dp['attributes']); // @phpstan-ignore-line
        });
    }

    /** Struktur payload logs + arrayValue non-list key dinormalkan + resource tetap ada. */
    public function testLogsPayloadAndNonListArrayValues(): void
    {
        $this->runOtlpServerSession(1, function (string $base, string $sink): void {
            $exporter = new OtlpHttpJsonExporter($base, ['service.name' => 'logsvc'], 500);
            $exporter->exportLogs([
                new LogRecord('WARN', 'watch out', 77, ['tags' => [5 => 'a', 6 => 'b']]),
            ]);
            $lines = $this->sinkLines($sink, 1);
            self::assertSame('/v1/logs', $lines[0]['path']);
            self::assertSame('POST', $lines[0]['method'], 'OTLP wajib POST (bukan GET bawaan wrapper)');
            self::assertStringContainsString('Content-Type: application/json', $lines[0]['headers']);
            $body = $lines[0]['decoded'];
            $rl = $body['resourceLogs'];
            self::assertSame([['key' => 'service.name', 'value' => ['stringValue' => 'logsvc']]], $rl[0]['resource']['attributes'], 'resource wajib ada di payload logs'); // @phpstan-ignore-line
            $rec = $rl[0]['scopeLogs'][0]['logRecords'][0]; // @phpstan-ignore-line
            self::assertSame('77', $rec['timeUnixNano']); // @phpstan-ignore-line
            self::assertSame('WARN', $rec['severityText']); // @phpstan-ignore-line
            self::assertSame(['stringValue' => 'watch out'], $rec['body']); // @phpstan-ignore-line
            $values = $rec['attributes'][0]['value']['arrayValue']['values']; // @phpstan-ignore-line
            self::assertIsList($values, 'arrayValue.values wajib list meski input non-list');
            self::assertSame([['stringValue' => 'a'], ['stringValue' => 'b']], $values);
        });
    }

    /** Membunuh ReturnRemoval: export kosong tidak boleh memicu POST. */
    public function testEmptyExportsAreNoOps(): void
    {
        $this->runOtlpServerSession(0, function (string $base, string $sink): void {
            $exporter = new OtlpHttpJsonExporter($base, [], 500);
            $exporter->export([]);
            $exporter->exportMetrics([]);
            $exporter->exportLogs([]);
            self::assertFileDoesNotExist($sink, 'tidak ada POST untuk batch kosong');
        });
    }

    /** Kontrak default timeout = 500 ms (membunuh Dec/Inc default :21 via refleksi). */
    public function testDefaultTimeoutContractIsExactlyFiveHundredMs(): void
    {
        self::assertSame(
            500,
            new \ReflectionParameter([OtlpHttpJsonExporter::class, '__construct'], 'timeoutMs')->getDefaultValue(),
        );
    }

    /** asDouble wajib float RIIL: sum string '12.5' → 12.5 (JSON tanpa kutip).
     * Membunuh CastFloat:64 — dengan sum int lama, cast tak-terobservasi di JSON. */
    public function testAsDoubleIsRealFloatFromNumericStringSum(): void
    {
        $this->runOtlpServerSession(1, function (string $base, string $sink): void {
            $exporter = new OtlpHttpJsonExporter($base, [], 500);
            $exporter->exportMetrics([ // @phpstan-ignore-line
                'lat.p50' => ['sum' => '12.5', 'attributes' => []],
            ]);
            $lines = $this->sinkLines($sink, 1);
            $dp = $lines[0]['decoded']['resourceMetrics'][0]['scopeMetrics'][0]['metrics'][0]['sum']['dataPoints'][0]; // @phpstan-ignore-line
            self::assertIsFloat($dp['asDouble'], 'asDouble wajib float JSON, bukan string'); // @phpstan-ignore-line
            self::assertSame(12.5, $dp['asDouble']);
        });
    }

    /** scope.name 'zef-observability' wajib utuh di payload logs
     * (membunuh ArrayItemRemoval :100/:101 pada literal payload). */
    public function testLogsScopeNameSurvivesInPayload(): void
    {
        $this->runOtlpServerSession(1, function (string $base, string $sink): void {
            $exporter = new OtlpHttpJsonExporter($base, [], 500);
            $exporter->exportLogs([new LogRecord('INFO', 'halo', 5, [])]);
            $lines = $this->sinkLines($sink, 1);
            $rl = $lines[0]['decoded']['resourceLogs'];
            self::assertSame(['name' => 'zef-observability'], $rl[0]['scopeLogs'][0]['scope']); // @phpstan-ignore-line
            self::assertSame([], $rl[0]['resource']['attributes'], 'resource wajib tetap dirender (meski kosong)'); // @phpstan-ignore-line
        });
    }

    /** Parse baris status WAJIB caret-anchored: header decoy 'X-Proto: HTTP/2 599'
     * TIDAK boleh dianggap baris status — export tetap sukses (200).
     * Membunuh PregMatchRemoveCaret:237 (mutan membaca decoy → 599 → gagal). */
    public function testHttpStatusLineParseIsCaretAnchoredAgainstDecoy(): void
    {
        $this->runOtlpServerSession(1, function (string $base, string $sink): void {
            $exporter = new OtlpHttpJsonExporter($base, [], 500);
            $exporter->export([$this->span()]); // void: sukses = tanpa exception
            self::assertCount(1, \file($sink) ?: [], 'request terkirim & diterima server'); // @phpstan-ignore-line
        });
    }

    /** endpointFor: trailing slash dirapikan, path sinyal tidak diduplikasi. */
    public function testEndpointNormalization(): void
    {
        $this->runOtlpServerSession(2, function (string $base, string $sink): void {
            // base dengan trailing slash → rtrim → path tetap /v1/traces
            new OtlpHttpJsonExporter($base . '/', [], 500)->export([$this->span()]);
            $lines = $this->sinkLines($sink, 1);
            self::assertSame('/v1/traces', $lines[0]['path'], 'rtrim menghapus slash sebelum append');

            // endpoint yang sudah berakhir /v1/metrics tidak di-append ulang
            new OtlpHttpJsonExporter($base . '/v1/metrics', [], 500)->exportMetrics([ // @phpstan-ignore-line
                'm' => ['sum' => 1, 'attributes' => []],
            ]);
            $lines = $this->sinkLines($sink, 2);
            self::assertSame('/v1/metrics', $lines[1]['path']);
        });
    }

    /** Status 400 → permanent (InvalidArgumentException); pesan transport persis. */
    public function testPermanentRejectionAndTransportFailure(): void
    {
        $this->runOtlpServerSession(1, function (string $base, string $sink): void {
            $exporter = new OtlpHttpJsonExporter($base . '/reject', [], 500);

            try {
                $exporter->export([$this->span()]);
                self::fail('400 must be a permanent rejection');
            } catch (\InvalidArgumentException $e) {
                self::assertSame('OTLP exporter permanent rejection.', $e->getMessage());
            }

            // Transport failure ke port tertutup (koneksi ditolak).
            $closed = new OtlpHttpJsonExporter('http://127.0.0.1:1', [], 500);

            try {
                $closed->export([$this->span()]);
                self::fail('closed port must fail transport');
            } catch (\RuntimeException $e) {
                self::assertStringStartsWith('OTLP exporter transport failure: ', $e->getMessage());
                self::assertTrue(\strlen($e->getMessage()) > 40, 'pesan harus memuat detail error');
            }
        });
    }

    /** Status 500/503 → transient (RuntimeException) — batas 4xx/5xx persis. */
    public function testTransientRejectionBoundaries(): void
    {
        $this->runOtlpServerSession(2, function (string $base, string $sink): void {
            foreach (['/e500', '/error'] as $suffix) {
                $exporter = new OtlpHttpJsonExporter($base . $suffix, [], 500);

                try {
                    $exporter->export([$this->span()]);
                    self::fail("{$suffix} must be a transient rejection");
                } catch (\RuntimeException $e) {
                    self::assertSame('OTLP exporter transient rejection.', $e->getMessage());
                }
            }
        });
    }

    /** timeoutMs guard: 0 ditolak, 1 dan 10000 sah (mutan batas dua arah). */
    public function testTimeoutGuardBoundaries(): void
    {
        foreach ([0, 10001] as $bad) {
            try {
                new OtlpHttpJsonExporter('http://127.0.0.1:1', [], $bad);
                self::fail("timeoutMs={$bad} must be rejected");
            } catch (\InvalidArgumentException $e) {
                self::assertSame('timeoutMs is outside its allowed range.', $e->getMessage());
            }
        }
        self::assertNotNull(new OtlpHttpJsonExporter('http://127.0.0.1:1', [], 1)); // @phpstan-ignore-line
        self::assertNotNull(new OtlpHttpJsonExporter('http://127.0.0.1:1', [], 10_000)); // @phpstan-ignore-line
    }

    // ------------------------------------------------------------------
    // PrometheusRenderer
    // ------------------------------------------------------------------

    /** Series counter murni (count==sum): baris TYPE + nilai, tanpa _sum/_count. */
    public function testCounterSeriesRendering(): void
    {
        $meter = new F8FakeMeter([
            'http.requests|{"code":"200"}' => ['count' => 5, 'sum' => 5.0, 'attributes' => []],
        ]);
        $out = new PrometheusRenderer()->render($meter, ['env' => 'prod']);
        self::assertSame(
            "# TYPE http_requests counter\n"
            . 'http_requests{code="200",env="prod"} 5.0' . "\n",
            $out,
            'label harus ksort: code sebelum env',
        );
    }

    /** Series observasi (count!=sum): TYPE _sum/_count + dua baris ekstra. */
    public function testObservationSeriesAddsHistogramMembers(): void
    {
        $meter = new F8FakeMeter([
            'latency|{}' => ['count' => 3, 'sum' => 10.5, 'attributes' => []],
        ]);
        $out = new PrometheusRenderer()->render($meter);
        self::assertSame(
            "# TYPE latency counter\n"
            . "latency 3.0\n"
            . "# TYPE latency_sum counter\n"
            . "# TYPE latency_count counter\n"
            . "latency_sum 10.5\n"
            . "latency_count 3.0\n",
            $out,
        );
    }

    /** Nilai non-numerik → 0.0; string numerik di-cast float. */
    public function testNonNumericDefaultsAndStringCast(): void
    {
        $meter = new F8FakeMeter([
            'a|{}' => ['count' => '7', 'sum' => '7', 'attributes' => []],   // string → float
            'b|{}' => ['count' => null, 'sum' => null, 'attributes' => []], // non-numerik → 0.0
            'd|{}' => ['count' => 5, 'sum' => '9', 'attributes' => []],     // sum string → histogram 9.0
        ]);
        $out = new PrometheusRenderer()->render($meter);
        self::assertStringContainsString("a 7.0\n", $out);
        self::assertStringContainsString("b 0.0\n", $out);
        // b: count 0.0 & sum 0.0 → TANPA histogram; mutan OneZeroFloat (0.0 → 1.0)
        // membuat selisih 1.0 → anggota b_sum muncul → asersi ini membunuhnya.
        self::assertStringNotContainsString('b_sum', $out);
        // d: sum string '9' wajib ter-cast 9.0 pada baris nilai histogram.
        self::assertStringContainsString("d_sum 9.0\n", $out);
        self::assertStringContainsString("d_count 5.0\n", $out);
        self::assertStringNotContainsString('NaN', $out);
        self::assertStringNotContainsString('Array', $out);
    }

    /** Boundary eksak PHP_FLOAT_EPSILON: |sum-count| == epsilon TIDAK memicu
     * histogram (> vs >=). 0.5 + eps eksak ter-representasi → selisih tepat eps. */
    public function testEpsilonBoundaryDoesNotEmitHistogram(): void
    {
        $eps = \PHP_FLOAT_EPSILON;
        $meter = new F8FakeMeter([
            'edge|{}' => ['count' => 0.5, 'sum' => 0.5 + $eps, 'attributes' => []],
        ]);
        $out = new PrometheusRenderer()->render($meter);
        self::assertStringContainsString("edge 0.5\n", $out);
        self::assertStringNotContainsString('edge_sum', $out, 'selisih tepat epsilon bukan histogram');
        self::assertStringNotContainsString('edge_count', $out);
    }

    /** Dedup TYPE per-nama-metrik: dua series nama sama + metrik kedua.
     * Membunuh Concat/ConcatOperandRemoval :65/:66 — swap/replace kunci dedup
     * menduplikasi TYPE m_sum (2×), removal menciderai TYPE n_sum (hilang). */
    public function testObservationTypeDedupIsPerMetricName(): void
    {
        $meter = new F8FakeMeter([
            'm|{"a":1}' => ['count' => 2, 'sum' => 7.0, 'attributes' => ['a' => 1]],
            'm|{"a":2}' => ['count' => 3, 'sum' => 8.0, 'attributes' => ['a' => 2]],
            'n|{}' => ['count' => 1, 'sum' => 5.0, 'attributes' => []],
        ]);
        $out = new PrometheusRenderer()->render($meter);
        self::assertSame(1, \substr_count($out, '# TYPE m_sum counter'), 'TYPE m_sum tepat sekali');
        self::assertSame(1, \substr_count($out, '# TYPE m_count counter'));
        self::assertSame(1, \substr_count($out, '# TYPE n_sum counter'), 'metrik kedua tetap dapat TYPE _sum');
        self::assertStringContainsString("m_sum{a=\"1\"} 7.0\n", $out);
        self::assertStringContainsString("m_sum{a=\"2\"} 8.0\n", $out, 'kedua series tetap dirender');
        self::assertStringContainsString("n_sum 5.0\n", $out);
    }

    /** Nama metrik: trim, sanitasi, default, dan prefix digit. */
    public function testMetricNameSanitization(): void
    {
        $meter = new F8FakeMeter([
            '  hp 9!x|{}' => ['count' => 1, 'sum' => 1, 'attributes' => []],
            '|{}' => ['count' => 1, 'sum' => 1, 'attributes' => []],
        ]);
        $out = new PrometheusRenderer()->render($meter);
        self::assertStringContainsString('# TYPE hp_9_x counter', $out, 'trim + spasi → underscore');
        self::assertStringContainsString('hp_9_x 1.0', $out);
        self::assertStringContainsString('# TYPE zef_unnamed_metric counter', $out, 'nama kosong → default');
    }

    /** Label: nilai int di-cast, karakter di-escape, key non-string & non-scalar dibuang. */
    public function testLabelBlockSemantics(): void
    {
        $meter = new F8FakeMeter([
            'm|{"n":5,"quote":"a\"b\\\c","arr":["x"],"":"empty","ok":"v"}' => ['count' => 1, 'sum' => 1, 'attributes' => []],
        ]);
        $out = new PrometheusRenderer()->render($meter);
        self::assertStringContainsString('m{n="5",ok="v",quote="a\"b\\\c"} 1.0', $out, 'escape " dan \; int → string; kunci kosong dibuang');
        self::assertStringNotContainsString('"arr"', $out, 'nilai non-scalar dibuang');
    }

    /** Batas MAX_SERIES: tepat 4096 series dirender, ke-4097 dipotong. */
    public function testMaxSeriesBoundary(): void
    {
        $snapshot = [];
        for ($i = 0; $i < 4_097; ++$i) {
            $snapshot['m' . $i . '|{}'] = ['count' => 1, 'sum' => 1, 'attributes' => []];
        }
        $out = new PrometheusRenderer()->render(new F8FakeMeter($snapshot));
        self::assertSame(4_096, substr_count($out, '# TYPE '), 'tepat 4096 TYPE line');
        self::assertStringNotContainsString('m4096 ', $out, 'series ke-4097 tidak dirender');
    }

    /** Nilai khusus float: +Inf / -Inf / NaN dirender sesuai teks eksposisi. */
    public function testSpecialFloatRendering(): void
    {
        $meter = new F8FakeMeter([
            'pinf|{}' => ['count' => 1, 'sum' => \INF, 'attributes' => []],
            'ninf|{}' => ['count' => 1, 'sum' => -\INF, 'attributes' => []],
            'nan|{}' => ['count' => 1, 'sum' => \NAN, 'attributes' => []],
        ]);
        $out = new PrometheusRenderer()->render($meter);
        self::assertStringContainsString('pinf_sum +Inf', $out);
        self::assertStringContainsString('ninf_sum -Inf', $out);
        // NaN: abs(NaN - count) > eps bernilai false → series tanpa anggota _sum.
        self::assertStringContainsString("nan 1.0\n", $out);
        self::assertStringNotContainsString('nan_sum', $out);
    }

    /** Meter kosong → string kosong. */
    public function testEmptyMeterRendersEmptyString(): void
    {
        self::assertSame('', new PrometheusRenderer()->render(new F8FakeMeter([])));
    }

    private function span(): SpanData
    {
        return new SpanData(
            's',
            new SpanContext(str_repeat('aa', 16), str_repeat('bb', 8)),
            null,
            1,
            2,
            5,
            6,
            'OK',
            null,
            [],
            [],
        );
    }

    /** @return list<array{path:string,decoded:array<string,mixed>,raw:string,headers:string,method:string}> */
    private function sinkLines(string $sink, int $expected): array
    {
        self::assertFileExists($sink);
        $raw = (string) file_get_contents($sink);
        $out = [];
        foreach (array_filter(explode("\n", $raw)) as $line) { // @phpstan-ignore-line
            $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $out[] = [
                'path' => $entry['path'], // @phpstan-ignore-line
                'decoded' => json_decode($entry['raw'], true, 512, JSON_THROW_ON_ERROR), // @phpstan-ignore-line
                'raw' => $entry['raw'], // @phpstan-ignore-line
                'headers' => $entry['headers'] ?? '', // @phpstan-ignore-line
                'method' => $entry['method'] ?? '', // @phpstan-ignore-line
            ];
        }
        self::assertCount($expected, $out);

        return $out; // @phpstan-ignore-line
    }

    // ------------------------------------------------------------------
    // Server fork helper (pola sama dengan ObservabilityTest)
    // ------------------------------------------------------------------

    /**
     * Fork server HTTP in-process; /reject → 400, /e500 → 500, /error → 503,
     * selain itu 200 dengan RAW body dicatat ke sink.
     *
     * @param callable(string, string): mixed $test
     */
    private function runOtlpServerSession(int $requests, callable $test): void
    {
        if (!\function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl required for OTLP transport tests');
        }
        $buildDir = \dirname(__DIR__, 2) . '/build';
        if (!\is_dir($buildDir)) {
            \mkdir($buildDir, 0o777, true);
        }
        $sink = $buildDir . '/f8_otlp_sink_' . \uniqid('', true) . '.jsonl';
        $server = @\stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($server, "cannot bind OTLP test server: {$errstr}");
        $name = (string) \stream_socket_get_name($server, false);
        $port = (int) \substr($name, (int) \strrpos($name, ':') + 1);

        $pid = \pcntl_fork();
        self::assertNotSame(-1, $pid, 'fork failed');

        if ($pid === 0) {
            try {
                for ($i = 0; $i < $requests; ++$i) {
                    $conn = @\stream_socket_accept($server, 10);
                    if ($conn === false) {
                        break;
                    }
                    [$path, $body, $headers, $method] = $this->readHttpRequest($conn);
                    $status = match (true) {
                        \str_starts_with($path, '/reject') => 400,
                        \str_starts_with($path, '/e500') => 500,
                        \str_starts_with($path, '/error') => 503,
                        default => 200,
                    };
                    if ($status === 200) {
                        \file_put_contents(
                            $sink,
                            \json_encode(['path' => $path, 'raw' => $body, 'headers' => $headers, 'method' => $method]) . \PHP_EOL,
                            \FILE_APPEND,
                        );
                    }
                    \fwrite($conn, "HTTP/1.1 {$status} X\r\nContent-Type: application/json\r\nContent-Length: 2\r\nX-Proto: HTTP/2 599\r\nConnection: close\r\n\r\n{}");
                    \fclose($conn);
                }
            } catch (\Throwable) {
            }
            \exit(0);
        }

        try {
            $test('http://127.0.0.1:' . $port, $sink);
        } finally {
            \pcntl_waitpid($pid, $status);
            \fclose($server);
            @\unlink($sink);
        }
    }

    /**
     * @param resource $conn
     *
     * @return array{0:string,1:string,2:string,3:string}
     */
    private function readHttpRequest($conn): array
    {
        $buffer = '';
        while (!\str_contains($buffer, "\r\n\r\n")) {
            $chunk = \fread($conn, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $buffer .= $chunk;
        }
        $split = \explode("\r\n\r\n", $buffer, 2);
        $head = $split[0];
        $body = $split[1] ?? '';
        $length = 0;
        if (\preg_match('/Content-Length:\s*(\d+)/i', $head, $m) === 1) {
            $length = (int) $m[1];
        }
        while (\strlen($body) < $length) {
            $chunk = \fread($conn, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $body .= $chunk;
        }
        $line = \strtok($head, "\r\n") ?: ''; // @phpstan-ignore-line
        if (\preg_match('#^([A-Z]+)\s+(\S+)#', $line, $pm) === 1) {
            $method = $pm[1];
            $path = (string) (\parse_url($pm[2], PHP_URL_PATH) ?: '/'); // @phpstan-ignore-line
        } else {
            $method = '';
            $path = '/';
        }

        return [$path, $body, $head, $method];
    }
}

/**
 * @internal — meter palsu dengan snapshot terjadwal (bentuk sama dengan CounterMeter)
 */
final class F8FakeMeter implements MeterInterface
{
    public function __construct(private readonly array $snapshot) {} // @phpstan-ignore-line

    #[\Override]
    public function increment(string $name, float|int $value = 1, array $attributes = []): void {}

    #[\Override]
    public function observe(string $name, float $value, array $attributes = []): void {}

    #[\Override]
    public function snapshot(): array
    {
        return $this->snapshot; // @phpstan-ignore-line
    }
}
