<?php

declare(strict_types=1);

/*
 * ZEF Framework — Edge-Case Matrix fase 3 (Runtime lifecycle, v2.14.4).
 *
 * Kurikulum: docs/EDGE-CASE-MATRIX.md Tier 3. Setiap test mewakili skenario
 * adversarial nyata pada RoadRunnerRuntime/RoadRunnerWorkerAdapter/TinkerSession/
 * InMemoryWorker/BlockingSleeper: payload rusak, state antar-iterasi, sinyal
 * yang dimiliki vs asing, saturasi sumber daya, dan graceful shutdown.
 *
 * Pola aman untuk test sinyal: handler diverifikasi via
 * pcntl_signal_get_handler() SEBELUM sinyal dikirim ke proses sendiri, sehingga
 * mutan yang merusak instalasi handler gagal lewat assertion bersih — bukan
 * dengan mematikan proses uji.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zef\Framework\Application;
use Zef\Framework\Config\ConfigProviderInterface;
use Zef\Framework\Http\Response;
use Zef\Framework\Http\ServerRequest;
use Zef\Framework\Http\Uri;
use Zef\Framework\Observability\LogRecord;
use Zef\Framework\Observability\Telemetry;
use Zef\Framework\Runtime\BlockingSleeper;
use Zef\Framework\Runtime\InMemoryWorker;
use Zef\Framework\Runtime\RoadRunnerRuntime;
use Zef\Framework\Runtime\RoadRunnerWorkerAdapter;
use Zef\Framework\Runtime\TinkerSession;
use Zef\Framework\Runtime\WorkerInterface;

/**
 * @internal
 */
final class EdgeMatrixRuntimeWorker implements WorkerInterface
{
    public int $waitCalls = 0;

    public int $respondCalls = 0;

    public bool $stopCalled = false;

    public bool $signalSent = false;

    /** @var list<ServerRequestInterface> */
    public array $queue = [];

    /** @var list<string> */
    public array $errors = [];

    /** @var list<ResponseInterface> */
    public array $responses = [];

    /** @var list<callable|int> */
    public array $termHandlerSnapshots = [];

    /** @var list<callable|int> */
    public array $intHandlerSnapshots = [];

    /** @var list<string> */
    public array $ballast = [];

    /** @var list<LogRecord> */
    public array $logSnapshots = [];

    /** @var null|(\Closure(self, int): ?ServerRequestInterface) */
    public ?\Closure $onWait = null;

    public ?\Closure $onRespond = null;

    public ?\Closure $onError = null;

    #[\Override]
    public function waitRequest(): ?ServerRequestInterface
    {
        ++$this->waitCalls;
        if ($this->queue !== []) {
            return array_shift($this->queue);
        }
        if ($this->onWait instanceof \Closure) {
            return ($this->onWait)($this, $this->waitCalls);
        }

        return null;
    }

    #[\Override]
    public function respond(ResponseInterface $response): void
    {
        ++$this->respondCalls;
        if ($this->onRespond instanceof \Closure) {
            ($this->onRespond)($response);

            return;
        }
        $this->responses[] = $response;
    }

    #[\Override]
    public function error(string $message): void
    {
        if ($this->onError instanceof \Closure) {
            ($this->onError)($message);

            return;
        }
        $this->errors[] = $message;
    }

    #[\Override]
    public function stop(): void
    {
        $this->stopCalled = true;
    }

    #[\Override]
    public function isRunning(): bool
    {
        return true;
    }

    /** Captures the installed handler for a signal without delivering it. */
    public function snapshotHandler(int $signal): callable|int
    {
        return pcntl_signal_get_handler($signal);
    }

    /**
     * Fail-safe signal probe: only kills the runtime when an owned Closure
     * handler is really installed; otherwise leaves delivery to the
     * post-run assertions (no process death under any mutant).
     *
     * The signal is sent EXACTLY ONCE per worker: re-sending after a slow
     * delivery could leave a second pending signal that fires AFTER the
     * runtime restored SIG_DFL, killing the whole test process.
     */
    public function deliverSignalWhenOwned(int $signal, int $maxPolls = 200): void
    {
        if (!pcntl_signal_get_handler($signal) instanceof \Closure) {
            return;
        }
        if (!$this->signalSent) {
            posix_kill((int) getmypid(), $signal);
            $this->signalSent = true;
        }
        $polls = 0;
        while (!$this->stopCalled && $polls < $maxPolls) {
            usleep(25_000);
            ++$polls;
        }
    }
}

/**
 * @internal
 */
final class EdgeMatrixHandlerFixture implements RequestHandlerInterface
{
    public function __construct(private readonly \Closure $handler) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $response = ($this->handler)($request);
        assert($response instanceof ResponseInterface);

        return $response;
    }
}

/**
 * @internal
 */
final class EdgeMatrixRuntimeTest extends TestCase
{
    // ------------------------------------------------------------------
    // BlockingSleeper
    // ------------------------------------------------------------------

    public function testSleeperSilentlyIgnoresNonPositiveMilliseconds(): void
    {
        $start = microtime(true);
        BlockingSleeper::sleepMilliseconds(0);
        BlockingSleeper::sleepMilliseconds(-1);
        BlockingSleeper::sleepMilliseconds(-100000);
        self::assertLessThanOrEqual(0.05, microtime(true) - $start);
    }

    public function testSleeperSleepsForRequestedDurationOnPositiveInput(): void
    {
        $start = microtime(true);
        BlockingSleeper::sleepMilliseconds(120);
        $elapsed = microtime(true) - $start;
        self::assertGreaterThanOrEqual(0.11, $elapsed);
        self::assertLessThan(2.0, $elapsed, 'sleeper must not overshoot by orders of magnitude');
    }

    // ------------------------------------------------------------------
    // TinkerSession
    // ------------------------------------------------------------------

    public function testTinkerTreatsUppercaseExitAndQuitAsQuitCommands(): void
    {
        $session = new TinkerSession();
        self::assertSame(['bye.'], $session->run(['EXIT']));
        self::assertSame(['bye.'], $session->run(['Quit']));
        self::assertSame(['bye.'], $session->run(["exit \r\n"]));
    }

    public function testTinkerErrorReportContainsExceptionClassAndMessage(): void
    {
        $session = new TinkerSession();
        $output = $session->evaluate('throw new RuntimeException("boom");');
        self::assertSame('[error] RuntimeException: boom', $output);
        // The session must stay usable after a contained error.
        self::assertSame('3', $session->evaluate('1 + 2'));
    }

    public function testTinkerWrapCodeToleratesTrailingWhitespaceAfterStatement(): void
    {
        $session = new TinkerSession();
        self::assertSame('null', $session->evaluate('5; '));
        self::assertSame('null', $session->evaluate('$x = 7;  '));
        self::assertSame(7, $session->snapshot()['x']);
    }

    public function testTinkerTruncatesOutputExactlyAtLimitBoundary(): void
    {
        $session = new TinkerSession([], 240);
        $session->set('edge', str_repeat('a', 238));
        self::assertSame(240, strlen($session->evaluate('$edge')), 'a 240-char export fits untouched');
        $session->set('over', str_repeat('b', 239));
        $truncated = $session->evaluate('$over');
        self::assertSame(243, strlen($truncated), '241-char export becomes 240 chars plus a 3-byte ellipsis');
        self::assertTrue(str_ends_with($truncated, '…'));
        self::assertTrue(str_starts_with($truncated, "'bbb"));
    }

    // ------------------------------------------------------------------
    // InMemoryWorker
    // ------------------------------------------------------------------

    public function testInMemoryWorkerDrainsFifoCollectsResponsesAndStops(): void
    {
        $first = new ServerRequest('GET', new Uri('http://localhost/first', ['localhost']));
        $second = new ServerRequest('GET', new Uri('http://localhost/second', ['localhost']));
        $worker = new InMemoryWorker([$first, $second]);

        self::assertTrue($worker->isRunning());
        self::assertSame($first, $worker->waitRequest(), 'FIFO order: first request first');
        self::assertSame($second, $worker->waitRequest());
        self::assertNull($worker->waitRequest(), 'exhausted worker yields null, not an error');

        $ok = new Response(200, [], 'a');
        $teapot = new Response(418, [], 'b');
        $worker->respond($ok);
        $worker->respond($teapot);
        self::assertSame([$ok, $teapot], $worker->responses(), 'responses keep insertion order');

        $worker->stop();
        self::assertFalse($worker->isRunning(), 'stop() must flip the running flag');
        $worker->error('ignored');
    }

    // ------------------------------------------------------------------
    // RoadRunnerWorkerAdapter
    // ------------------------------------------------------------------

    public function testWorkerAdapterRejectsWorkersMissingRequiredMethods(): void
    {
        $bare = new class {
            public function __toString(): string
            {
                return 'bare';
            }
        };

        try {
            new RoadRunnerWorkerAdapter($bare);
            self::fail('worker without waitRequest() must be rejected');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('RoadRunner HTTP worker must expose waitRequest().', $e->getMessage());
        }

        $half = new class {
            public function waitRequest(): null
            {
                return null;
            }
        };

        try {
            new RoadRunnerWorkerAdapter($half);
            self::fail('worker without respond() must be rejected');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('RoadRunner HTTP worker must expose respond().', $e->getMessage());
        }
    }

    public function testWorkerAdapterForwardsErrorWithoutDoubleLogging(): void
    {
        $logFile = (string) tempnam(sys_get_temp_dir(), 'zef-edge-log');
        // ini_restore() would fall back to the MASTER ini value (stderr) and
        // break Infection's error_log=/dev/null routing: the first STDERR
        // byte makes Infection kill the whole suite. Always restore via
        // ini_set() to the previously captured value instead.
        $previousLog = (string) ini_get('error_log');
        ini_set('error_log', $logFile);

        try {
            $inner = new class {
                /** @var list<string> */
                public array $received = [];

                public function waitRequest(): null
                {
                    return null;
                }

                public function respond(ResponseInterface $response): void {}

                public function error(string $message): void
                {
                    $this->received[] = $message;
                }
            };
            $adapter = new RoadRunnerWorkerAdapter($inner);
            $adapter->error('channel outage');

            self::assertSame(['channel outage'], $inner->received, 'message forwarded verbatim');
            self::assertSame('', file_get_contents($logFile), 'native error_log must stay silent when the worker has error()');
        } finally {
            ini_set('error_log', $previousLog);
            @unlink($logFile); // nosemgrep: php.lang.security.unlink-use
        }
    }

    public function testWorkerAdapterFallsBackToErrorLogWhenErrorMethodMissing(): void
    {
        $logFile = (string) tempnam(sys_get_temp_dir(), 'zef-edge-log');
        $previousLog = (string) ini_get('error_log');
        ini_set('error_log', $logFile);

        try {
            $inner = new class {
                public function waitRequest(): null
                {
                    return null;
                }

                public function respond(ResponseInterface $response): void {}
            };
            $adapter = new RoadRunnerWorkerAdapter($inner);
            $adapter->error('fallback message');

            $contents = (string) file_get_contents($logFile);
            self::assertTrue(str_contains($contents, 'fallback message'), 'error_log fallback must receive the message');
        } finally {
            ini_set('error_log', $previousLog);
            @unlink($logFile); // nosemgrep: php.lang.security.unlink-use
        }
    }

    public function testWorkerAdapterPrefersDirectStopAndSkipsInnerWorker(): void
    {
        $inner = new class {
            public bool $stopped = false;

            public function stop(): void
            {
                $this->stopped = true;
            }
        };
        $outer = new class($inner) {
            public bool $stopped = false;

            public function __construct(private readonly object $inner) {}

            public function waitRequest(): null
            {
                return null;
            }

            public function respond(ResponseInterface $response): void {}

            public function error(string $message): void {}

            public function stop(): void
            {
                $this->stopped = true;
            }

            public function getWorker(): object
            {
                return $this->inner;
            }
        };

        $adapter = new RoadRunnerWorkerAdapter($outer);
        $adapter->stop();

        self::assertTrue($outer->stopped, 'direct stop() must be used');
        self::assertFalse($inner->stopped, 'the inner worker must NOT be stopped twice');
    }

    public function testWorkerAdapterFallsBackToInnerWorkerThroughGetWorker(): void
    {
        $inner = new class {
            public bool $stopped = false;

            public function stop(): void
            {
                $this->stopped = true;
            }
        };
        $outer = new readonly class($inner) {
            public function __construct(private object $inner) {}

            public function waitRequest(): null
            {
                return null;
            }

            public function respond(ResponseInterface $response): void {}

            public function error(string $message): void {}

            public function getWorker(): object
            {
                return $this->inner;
            }
        };

        $adapter = new RoadRunnerWorkerAdapter($outer);
        $adapter->stop();
        self::assertTrue($inner->stopped, 'fallback must stop the inner worker');
    }

    public function testWorkerAdapterSurvivesNonObjectInnerWorkerOnStop(): void
    {
        $outer = new class {
            public bool $stopped = false;

            public function waitRequest(): null
            {
                return null;
            }

            public function respond(ResponseInterface $response): void {}

            public function error(string $message): void {}

            public function stop(): void
            {
                $this->stopped = true;
            }

            public function getWorker(): string
            {
                return 'not-a-worker';
            }
        };

        $adapter = new RoadRunnerWorkerAdapter($outer);
        $adapter->stop();
        self::assertTrue($outer->stopped);
    }

    public function testWorkerAdapterMirrorsInnerStopStateAndNormalizesRequests(): void
    {
        $request = new ServerRequest('GET', new Uri('http://localhost/x', ['localhost']));
        $running = new readonly class($request) {
            public function __construct(private ServerRequestInterface $request) {}

            public function waitRequest(): ServerRequestInterface
            {
                return $this->request;
            }

            public function respond(ResponseInterface $response): void {}

            public function error(string $message): void {}

            public function stop(): void {}

            public function isStopped(): bool
            {
                return false;
            }
        };
        $adapter = new RoadRunnerWorkerAdapter($running);
        self::assertTrue($adapter->isRunning(), 'inner not stopped => adapter running');
        self::assertSame($request, $adapter->waitRequest(), 'PSR-7 request passes through untouched');

        $stopping = new class {
            public function waitRequest(): null
            {
                return null;
            }

            public function respond(ResponseInterface $response): void {}

            public function error(string $message): void {}

            public function stop(): void {}

            public function isStopped(): bool
            {
                return true;
            }
        };
        $stoppingAdapter = new RoadRunnerWorkerAdapter($stopping);
        self::assertFalse($stoppingAdapter->isRunning(), 'inner stopped => adapter not running');

        $garbage = new class {
            /** @return list<string> */
            public function waitRequest(): array
            {
                return ['not-a-request'];
            }

            public function respond(ResponseInterface $response): void {}
        };
        $garbageAdapter = new RoadRunnerWorkerAdapter($garbage);
        self::assertNull($garbageAdapter->waitRequest(), 'non PSR-7 payload is normalized to null');
    }

    // ------------------------------------------------------------------
    // RoadRunnerRuntime: lifecycle state machine
    // ------------------------------------------------------------------

    public function testRuntimeAcceptsStopBeforeRunWithoutLifecycleErrors(): void
    {
        $app = $this->runtimeApp(static fn (): ResponseInterface => new Response(200, [], 'ok'));
        $runtime = new RoadRunnerRuntime($app, new EdgeMatrixRuntimeWorker(), 0, 0, false);

        $runtime->stop();
        self::assertFalse($runtime->isRunning());
        self::assertSame(0, $runtime->run(), 'pre-run stop() simply skips the loop');
    }

    public function testRuntimeRejectsStopAfterCleanShutdownAsIllegalTransition(): void
    {
        $app = $this->runtimeApp(static fn (): ResponseInterface => new Response(200, [], 'ok'));
        $worker = new EdgeMatrixRuntimeWorker();
        $runtime = new RoadRunnerRuntime($app, $worker, 0, 0, false);
        self::assertSame(0, $runtime->run());

        try {
            $runtime->stop();
            self::fail('stop() after shutdown must hit the stopped -> draining guard');
        } catch (\LogicException $e) {
            self::assertSame('Illegal runtime lifecycle transition stopped -> draining.', $e->getMessage());
        }
    }

    public function testRuntimeEmitsExactLifecycleMeterEventsForCleanRun(): void
    {
        $app = $this->runtimeApp(static fn (): ResponseInterface => new Response(200, [], 'ok'));
        $worker = new EdgeMatrixRuntimeWorker();
        $worker->queue[] = $this->runtimeRequest();
        $runtime = new RoadRunnerRuntime($app, $worker, 1, 0, false);
        $exit = $runtime->run();

        self::assertSame(0, $exit);
        self::assertSame(1, $worker->waitCalls, 'waitRequest must actually be polled');
        self::assertSame(1, $runtime->handledRequests());
        self::assertCount(1, $worker->responses);
        self::assertSame(200, $worker->responses[0]->getStatusCode());

        $snapshot = $this->meterSnapshot($app);
        self::assertSame(1, $this->meterCount($snapshot, 'zef.lifecycle.events.total', 'worker.started'));
        // The meter allowlists known lifecycle event names: the compatibility
        // boundary event is the only unknown one in a clean run, so it lands
        // in the 'other' bucket exactly once.
        self::assertSame(1, $this->meterCount($snapshot, 'zef.lifecycle.events.total', 'other'), 'runtime.compatibility.boundary.ready is recorded (bucketed as other)');
        self::assertSame(1, $this->meterCount($snapshot, 'zef.lifecycle.events.total', 'worker.ready'), 'ready fires exactly at the first handled request');
        self::assertSame(1, $this->meterCount($snapshot, 'zef.lifecycle.events.total', 'worker.terminated'), 'clean exit tags worker.terminated');
        self::assertSame(0, $this->meterCount($snapshot, 'zef.lifecycle.events.total', 'worker.recovery.detected'));
        self::assertSame(0, $this->meterCount($snapshot, 'zef.runtime.resource.events.total', 'saturated'), 'no saturation without a memory limit');
    }

    public function testRuntimeRecordsReadyTransitionExactlyOncePerLifetime(): void
    {
        $app = $this->runtimeApp(static fn (): ResponseInterface => new Response(200, [], 'ok'));
        $worker = new EdgeMatrixRuntimeWorker();
        $worker->queue = [$this->runtimeRequest(), $this->runtimeRequest()];
        $runtime = new RoadRunnerRuntime($app, $worker, 0, 0, false);
        self::assertSame(0, $runtime->run());

        self::assertSame(3, $worker->waitCalls, 'two served requests plus the final null poll');
        self::assertSame(2, $runtime->handledRequests());
        $snapshot = $this->meterSnapshot($app);
        self::assertSame(1, $this->meterCount($snapshot, 'zef.lifecycle.events.total', 'worker.started'));
        self::assertSame(1, $this->meterCount($snapshot, 'zef.lifecycle.events.total', 'worker.ready'), 'the ready transition must not repeat per request');
        self::assertSame(1, $this->meterCount($snapshot, 'zef.lifecycle.events.total', 'worker.terminated'));
    }

    public function testRuntimeLeavesApplicationUnusableAfterShutdown(): void
    {
        $app = $this->runtimeApp(static fn (): ResponseInterface => new Response(200, [], 'ok'));
        $runtime = new RoadRunnerRuntime($app, new EdgeMatrixRuntimeWorker(), 0, 0, false);
        self::assertSame(0, $runtime->run());

        try {
            $app->handle(new ServerRequest('GET', new Uri('http://localhost/runtime-ok', ['localhost'])));
            self::fail('the runtime must shut the application down when the loop exits');
        } catch (\LogicException $e) {
            self::assertTrue(str_contains($e->getMessage(), 'shut down'), 'unexpected failure: ' . $e->getMessage());
        }
    }

    public function testRuntimeAnswers500AndTagsRecoveryWhenHandlerExplodes(): void
    {
        $app = $this->runtimeApp(static function (): never {
            throw new \RuntimeException('handler exploded');
        });
        $worker = new EdgeMatrixRuntimeWorker();
        $worker->queue[] = $this->runtimeRequest();
        $runtime = new RoadRunnerRuntime($app, $worker, 1, 0, false);
        $exit = $runtime->run();

        self::assertSame(1, $exit, 'handler failure => exit code 1');
        self::assertSame(1, $runtime->handledRequests());
        self::assertCount(1, $worker->responses, 'the 500 answer still reaches the client');
        self::assertSame(500, $worker->responses[0]->getStatusCode());
        self::assertSame('application/json', $worker->responses[0]->getHeaderLine('Content-Type'));
        self::assertTrue(str_contains($worker->responses[0]->getBody()->__toString(), '"status":500'));
        self::assertSame(['RuntimeException: handler exploded'], $worker->errors, 'the failure is reported verbatim on the error channel');

        $snapshot = $this->meterSnapshot($app);
        self::assertSame(1, $this->meterCount($snapshot, 'zef.lifecycle.events.total', 'worker.started'));
        self::assertSame(0, $this->meterCount($snapshot, 'zef.lifecycle.events.total', 'worker.ready'), 'a crashed first request never becomes ready');
        self::assertSame(1, $this->meterCount($snapshot, 'zef.lifecycle.events.total', 'worker.recovery.detected'), 'exit code 1 tags worker.recovery.detected');
        self::assertSame(0, $this->meterCount($snapshot, 'zef.lifecycle.events.total', 'worker.terminated'));
    }

    public function testRuntimeWritesWorkerErrorToErrorLogWhenErrorChannelThrows(): void
    {
        $logFile = (string) tempnam(sys_get_temp_dir(), 'zef-edge-errlog');
        $previousLog = (string) ini_get('error_log');
        ini_set('error_log', $logFile);

        try {
            $app = $this->runtimeApp(static function (): never {
                throw new \RuntimeException('handler exploded');
            });
            $worker = new EdgeMatrixRuntimeWorker();
            $worker->queue[] = $this->runtimeRequest();
            $worker->onError = static function (string $message): never {
                throw new \RuntimeException('error channel down');
            };
            $runtime = new RoadRunnerRuntime($app, $worker, 1, 0, false);
            $exit = $runtime->run();

            self::assertSame(1, $exit);
            $contents = (string) file_get_contents($logFile);
            self::assertTrue(str_contains($contents, 'RuntimeException: handler exploded'), 'error_log receives the ORIGINAL failure when the error channel throws');
        } finally {
            ini_set('error_log', $previousLog);
            @unlink($logFile); // nosemgrep: php.lang.security.unlink-use
        }
    }

    public function testRuntimeReportsRespondFailureThroughErrorChannel(): void
    {
        $app = $this->runtimeApp(static function (): never {
            throw new \RuntimeException('handler exploded');
        });
        $worker = new EdgeMatrixRuntimeWorker();
        $worker->queue[] = $this->runtimeRequest();
        $worker->onRespond = static function (ResponseInterface $response): never {
            throw new \RuntimeException('pipe blown');
        };
        $runtime = new RoadRunnerRuntime($app, $worker, 1, 0, false);
        $exit = $runtime->run();

        self::assertSame(1, $exit);
        self::assertSame(
            ['RuntimeException: handler exploded', 'RuntimeException: pipe blown'],
            $worker->errors,
            'both the handler failure and the respond failure are reported',
        );
        self::assertCount(0, $worker->responses, 'the failed 500 delivery never lands in the response sink');
    }

    // ------------------------------------------------------------------
    // RoadRunnerRuntime: telemetry plumbing
    // ------------------------------------------------------------------

    public function testRuntimeFlushesTelemetryPerRequestAndAtShutdown(): void
    {
        putenv('ZEF_OTEL_ENABLED=true');

        try {
            $app = $this->runtimeApp(static fn (): ResponseInterface => new Response(200, [], 'ok'));
            $worker = new EdgeMatrixRuntimeWorker();
            $worker->queue = [$this->runtimeRequest(), $this->runtimeRequest()];
            $runtime = new RoadRunnerRuntime($app, $worker, 0, 0, false);
            self::assertSame(0, $runtime->run());

            self::assertSame(2, $runtime->handledRequests());
            $snapshot = $this->meterSnapshot($app);
            self::assertSame(
                3,
                $this->meterCount($snapshot, 'zef.lifecycle.events.total', 'telemetry.flush'),
                'one flush per request plus one at application shutdown',
            );
            self::assertSame(
                1,
                $this->meterCount($snapshot, 'zef.lifecycle.events.total', 'telemetry.shutdown'),
                'the shutdown counter is emitted exactly once',
            );
        } finally {
            putenv('ZEF_OTEL_ENABLED');
        }
    }

    public function testRuntimeEmitsSaturationHealthWithExactMeterSeriesAndLogs(): void
    {
        putenv('ZEF_RUNTIME_SATURATION_PERCENT=50');
        putenv('ZEF_OTEL_ENABLED=true');
        $this->warmMemory();
        $usage = memory_get_usage(true);
        $limit = (int) ((float) $usage / 0.55);

        try {
            $app = $this->runtimeApp(static fn (): ResponseInterface => new Response(200, [], 'ok'));
            $worker = new EdgeMatrixRuntimeWorker();
            $worker->queue[] = $this->runtimeRequest();
            // Application::shutdown() clears telemetry logs in the run() finally
            // block: snapshot them inside the loop (right after the response is
            // delivered) while the in-memory buffer is still alive.
            $worker->onRespond = function (ResponseInterface $response) use ($app, $worker): void {
                $worker->logSnapshots = $this->telemetryLogs($app);
            };
            $runtime = new RoadRunnerRuntime($app, $worker, 1, $limit, false);
            self::assertSame(0, $runtime->run());

            $snapshot = $this->meterSnapshot($app);
            $key = 'zef.runtime.resource.events.total|' . json_encode(
                ['event.name' => 'saturated'],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
            self::assertArrayHasKey($key, $snapshot, 'saturation meter series must use the exact event.name tag');
            self::assertSame(1, $snapshot[$key]['count'], 'one saturation emission for the single loop iteration (maxJobs stops the final poll)');

            $saturated = [];
            foreach ($worker->logSnapshots as $log) {
                if ($log->body === 'runtime.resource.saturated') {
                    $saturated[] = $log;
                }
            }
            self::assertCount(1, $saturated, 'the saturated log body uses the runtime.resource. prefix');
            foreach ($saturated as $log) {
                self::assertSame('runtime.resource.saturated', $log->attributes['event.name'] ?? null);
                $percent = $log->attributes['memory.percent'] ?? null;
                self::assertIsFloat($percent);
                self::assertGreaterThan(52.0, $percent);
                self::assertLessThan(58.0, $percent);
            }
        } finally {
            putenv('ZEF_RUNTIME_SATURATION_PERCENT');
            putenv('ZEF_OTEL_ENABLED');
        }
    }

    public function testRuntimeClampsResourceCapacityEnvFloorToMinimum(): void
    {
        putenv('ZEF_RUNTIME_RESOURCE_CAPACITY=0');

        try {
            $app = $this->runtimeApp(static fn (): ResponseInterface => new Response(200, [], 'ok'));
            $worker = new EdgeMatrixRuntimeWorker();
            $worker->queue[] = $this->runtimeRequest();
            $runtime = new RoadRunnerRuntime($app, $worker, 0, 0, false);
            self::assertSame(0, $runtime->run());

            self::assertSame(1, $runtime->handledRequests(), 'capacity 0 from env is clamped up to the minimum of 1');
            self::assertCount(1, $worker->responses);
            self::assertSame(200, $worker->responses[0]->getStatusCode(), 'the request is admitted, never answered with 503');
        } finally {
            putenv('ZEF_RUNTIME_RESOURCE_CAPACITY');
        }
    }

    public function testRuntimeAdmitsRequestsWithDefaultCapacityWhenEnvAbsent(): void
    {
        $app = $this->runtimeApp(static fn (): ResponseInterface => new Response(200, [], 'ok'));
        $worker = new EdgeMatrixRuntimeWorker();
        $worker->queue[] = $this->runtimeRequest();
        $runtime = new RoadRunnerRuntime($app, $worker, 0, 0, false);
        $exit = $runtime->run();

        self::assertSame(0, $exit);
        self::assertSame(1, $runtime->handledRequests());
        self::assertCount(1, $worker->responses);

        $snapshot = $this->meterSnapshot($app);
        self::assertSame(0, $this->meterCount($snapshot, 'zef.runtime.resource.events.total', 'saturated'), 'no memory limit => no saturation health, ever');
    }

    public function testRuntimeExitsWithTwoWhenMemoryLimitIsBreachedMidRequest(): void
    {
        $this->warmMemory();
        $usage = memory_get_usage(true);
        $limit = (int) ((float) $usage * 1.05);

        $app = $this->runtimeApp(static fn (): ResponseInterface => new Response(200, [], 'ok'));
        $worker = new EdgeMatrixRuntimeWorker();
        $worker->onWait = static function (EdgeMatrixRuntimeWorker $w, int $call): ?ServerRequestInterface {
            if ($call === 1) {
                $w->ballast[] = str_repeat('x', 6 * 1024 * 1024);

                return new ServerRequest('GET', new Uri('http://localhost/runtime-ok', ['localhost']));
            }

            return null;
        };
        $runtime = new RoadRunnerRuntime($app, $worker, 0, $limit, false);
        $exit = $runtime->run();

        self::assertSame(2, $exit, 'breaching the memory limit mid-request stops the worker with exit code 2');
        self::assertSame(1, $runtime->handledRequests(), 'the loop stops before accepting another request');
        self::assertCount(1, $worker->responses, 'the handled request still got its 200');

        $snapshot = $this->meterSnapshot($app);
        self::assertSame(1, $this->meterCount($snapshot, 'zef.lifecycle.events.total', 'worker.recovery.detected'), 'exit code 2 counts as a recovery event');
        $key = 'zef.runtime.resource.events.total|' . json_encode(
            ['event.name' => 'saturated'],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        self::assertSame(1, $snapshot[$key]['count'] ?? 0, 'exactly one loop iteration ran: the extra emit of a continued loop would be visible');
    }

    // ------------------------------------------------------------------
    // RoadRunnerRuntime: signal ownership (fail-safe pattern)
    // ------------------------------------------------------------------

    public function testRuntimeOwnsSignalHandlersOnlyForTheRunLifetime(): void
    {
        if (!function_exists('pcntl_signal')) {
            self::markTestSkipped('pcntl not available.');
        }
        $app = $this->runtimeApp(static fn (): ResponseInterface => new Response(200, [], 'ok'));
        $worker = new EdgeMatrixRuntimeWorker();
        $worker->onWait = static function (EdgeMatrixRuntimeWorker $w, int $call): ?ServerRequestInterface {
            $w->termHandlerSnapshots[] = $w->snapshotHandler(SIGTERM);
            $w->intHandlerSnapshots[] = $w->snapshotHandler(SIGINT);

            return $call === 1 ? new ServerRequest('GET', new Uri('http://localhost/runtime-ok', ['localhost'])) : null;
        };
        $runtime = new RoadRunnerRuntime($app, $worker, 0, 0, true);
        self::assertSame(0, $runtime->run());

        self::assertNotSame(SIG_DFL, $worker->termHandlerSnapshots[0], 'SIGTERM handler must be installed while the run loop is alive');
        self::assertNotSame(SIG_DFL, $worker->intHandlerSnapshots[0], 'SIGINT handler must be installed while the run loop is alive');
        self::assertSame(SIG_DFL, pcntl_signal_get_handler(SIGTERM), 'SIGTERM ownership is released after the run');
        self::assertSame(SIG_DFL, pcntl_signal_get_handler(SIGINT), 'SIGINT ownership is released after the run');
    }

    public function testRuntimeDrainsGracefullyWhenSigtermArrivesMidLoop(): void
    {
        if (!function_exists('pcntl_signal')) {
            self::markTestSkipped('pcntl not available.');
        }
        $app = $this->runtimeApp(static fn (): ResponseInterface => new Response(200, [], 'ok'));
        $worker = new EdgeMatrixRuntimeWorker();
        $worker->onWait = static function (EdgeMatrixRuntimeWorker $w, int $call): ?ServerRequestInterface {
            if ($call === 1) {
                return new ServerRequest('GET', new Uri('http://localhost/runtime-ok', ['localhost']));
            }
            $w->deliverSignalWhenOwned(SIGTERM);

            return null;
        };
        $runtime = new RoadRunnerRuntime($app, $worker, 0, 0, true);
        $exit = $runtime->run();

        self::assertTrue($worker->stopCalled, 'SIGTERM must reach the owned handler and stop the runtime');
        self::assertSame(0, $exit);
        self::assertSame(1, $runtime->handledRequests(), 'the in-flight request completed before the drain');
        self::assertCount(1, $worker->responses);

        $snapshot = $this->meterSnapshot($app);
        self::assertSame(1, $this->meterCount($snapshot, 'zef.lifecycle.events.total', 'worker.terminated'));
        self::assertSame(SIG_DFL, pcntl_signal_get_handler(SIGTERM), 'handlers are restored even after a signal drain');
    }

    public function testRuntimeDrainsGracefullyWhenSigintArrivesMidLoop(): void
    {
        if (!function_exists('pcntl_signal')) {
            self::markTestSkipped('pcntl not available.');
        }
        $app = $this->runtimeApp(static fn (): ResponseInterface => new Response(200, [], 'ok'));
        $worker = new EdgeMatrixRuntimeWorker();
        $worker->onWait = static function (EdgeMatrixRuntimeWorker $w, int $call): ?ServerRequestInterface {
            if ($call === 1) {
                return new ServerRequest('GET', new Uri('http://localhost/runtime-ok', ['localhost']));
            }
            $w->deliverSignalWhenOwned(SIGINT);

            return null;
        };
        $runtime = new RoadRunnerRuntime($app, $worker, 0, 0, true);
        $exit = $runtime->run();

        self::assertTrue($worker->stopCalled, 'SIGINT must reach the owned handler and stop the runtime');
        self::assertSame(0, $exit);
        self::assertSame(SIG_DFL, pcntl_signal_get_handler(SIGINT));
    }

    public function testRuntimeHandlerIgnoresSignalsItDoesNotOwn(): void
    {
        if (!function_exists('pcntl_signal')) {
            self::markTestSkipped('pcntl not available.');
        }
        $app = $this->runtimeApp(static fn (): ResponseInterface => new Response(200, [], 'ok'));
        $worker = new EdgeMatrixRuntimeWorker();
        $worker->onWait = static function (EdgeMatrixRuntimeWorker $w, int $call): ?ServerRequestInterface {
            if ($call === 1) {
                $w->deliverSignalWhenOwned(SIGCHLD, 1);

                return new ServerRequest('GET', new Uri('http://localhost/runtime-ok', ['localhost']));
            }

            return null;
        };
        $runtime = new RoadRunnerRuntime($app, $worker, 0, 0, true);
        $exit = $runtime->run();

        self::assertSame(0, $exit);
        self::assertSame(1, $runtime->handledRequests());
        self::assertFalse($worker->stopCalled, 'SIGCHLD is not owned: the handler must not stop the runtime');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function runtimeApp(\Closure $handler): Application
    {
        $app = new Application();
        $app->addProvider(new readonly class($handler) implements ConfigProviderInterface {
            public function __construct(private \Closure $handler) {}

            #[\Override]
            public function getModuleName(): string
            {
                return 'edge-matrix-runtime';
            }

            /**
             * @return array<string, mixed>
             */
            #[\Override]
            public function getConfig(): array
            {
                $handler = $this->handler;

                return [
                    'services' => [
                        'edge.runtime.svc' => [
                            'factory' => static fn (): RequestHandlerInterface => new EdgeMatrixHandlerFixture($handler),
                            'deps' => [],
                        ],
                    ],
                    'routes' => [
                        ['method' => 'GET', 'path' => '/runtime-ok', 'handler' => 'edge.runtime.svc'],
                    ],
                ];
            }
        });
        $app->boot();

        return $app;
    }

    /**
     * @return array<string, array{count: float|int, sum: float, attributes: array<string, mixed>}>
     */
    private function meterSnapshot(Application $app): array
    {
        $telemetry = $app->getContainer()->get(Telemetry::class);
        assert($telemetry instanceof Telemetry);

        return $telemetry->meter()->snapshot();
    }

    /**
     * @param array<string, array{count: float|int, sum: float, attributes: array<string, mixed>}> $snapshot
     */
    private function meterCount(array $snapshot, string $series, string $eventName): float|int
    {
        $key = $series . '|' . json_encode(['event.name' => $eventName], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $snapshot[$key]['count'] ?? 0;
    }

    /** @return list<LogRecord> */
    private function telemetryLogs(Application $app): array
    {
        $telemetry = $app->getContainer()->get(Telemetry::class);
        assert($telemetry instanceof Telemetry);
        $property = new \ReflectionProperty(Telemetry::class, 'logs');
        $raw = $property->getValue($telemetry);
        assert(is_array($raw));
        $logs = [];
        foreach ($raw as $record) {
            assert($record instanceof LogRecord);
            $logs[] = $record;
        }

        return $logs;
    }

    /** Reserves MM arenas so memory_get_usage(true) stays stable during the run. */
    private function warmMemory(): void
    {
        $warm = str_repeat('x', 8 * 1024 * 1024);
        $copy = $warm . 'y';
        unset($warm, $copy);
    }

    private function runtimeRequest(): ServerRequestInterface
    {
        return new ServerRequest('GET', new Uri('http://localhost/runtime-ok', ['localhost']));
    }
}
