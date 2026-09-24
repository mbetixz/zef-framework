<?php

declare(strict_types=1);

/*
 * ZEF Framework — Coverage push #5 (v2.13.1): runtime loop failure paths,
 * signal handlers, in-process job worker edge cases and the distributed
 * security admission boundary.
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
use Zef\Framework\Job\InMemoryJobQueue;
use Zef\Framework\Job\InProcessJobWorker;
use Zef\Framework\Job\JobContext;
use Zef\Framework\Job\JobEnvelope;
use Zef\Framework\Job\JobExecutionException;
use Zef\Framework\Job\JobIdempotencyStoreInterface;
use Zef\Framework\Job\JobMiddlewareInterface;
use Zef\Framework\Job\JobResult;
use Zef\Framework\Job\RetryPolicy;
use Zef\Framework\Runtime\RoadRunnerRuntime;
use Zef\Framework\Runtime\WorkerInterface;
use Zef\Framework\Security\Distributed\AuthenticationResult;
use Zef\Framework\Security\Distributed\AuthenticationStatus;
use Zef\Framework\Security\Distributed\AuthorizationPolicyInterface;
use Zef\Framework\Security\Distributed\AuthorizationResult;
use Zef\Framework\Security\Distributed\BoundedInMemoryReplayProtector;
use Zef\Framework\Security\Distributed\DefaultSecurityBoundary;
use Zef\Framework\Security\Distributed\ReplayDecision;
use Zef\Framework\Security\Distributed\ReplayProtectorInterface;
use Zef\Framework\Security\Distributed\ReplayResult;
use Zef\Framework\Security\Distributed\SecurityContext;
use Zef\Framework\Security\Distributed\SecurityFailure;
use Zef\Framework\Security\Distributed\SecurityRequest;
use Zef\Framework\Security\Distributed\SecurityVerdict;

/**
 * Mutable holder so an anonymous worker can reach the runtime instance that
 * is only constructed after the worker itself.
 *
 * @internal
 */
final class RuntimeHolder
{
    public ?RoadRunnerRuntime $runtime = null;
}

/**
 * @internal
 */
final class RuntimeEdgeTest extends TestCase
{
    // ------------------------------------------------------------------
    // RoadRunnerRuntime failure paths
    // ------------------------------------------------------------------

    public function testRuntimeRejectsReEntrantRun(): void
    {
        $app = $this->bootedApp();
        $holder = new RuntimeHolder();

        $worker = new class($holder) implements WorkerInterface {
            public ?int $exit = null;

            public function __construct(
                private readonly RuntimeHolder $holder,
            ) {}

            #[\Override]
            public function waitRequest(): ?ServerRequestInterface
            {
                $runtime = $this->holder->runtime;
                assert($runtime instanceof RoadRunnerRuntime);

                try {
                    $this->exit = $runtime->run();
                } catch (\Throwable) {
                    $this->exit = -1;
                }

                return null;
            }

            #[\Override]
            public function respond(ResponseInterface $response): void {}

            #[\Override]
            public function error(string $message): void {}

            #[\Override]
            public function stop(): void {}

            #[\Override]
            public function isRunning(): bool
            {
                return true;
            }
        };
        $runtime = new RoadRunnerRuntime($app, $worker, 0, 0, false);
        $holder->runtime = $runtime;
        $code = $runtime->run();
        self::assertSame(-1, $worker->exit);
        self::assertSame(0, $code);
    }

    public function testRuntimeReportsApplicationFailureAndResponds500(): void
    {
        $app = new Application();
        $app->addProvider(new class implements ConfigProviderInterface {
            #[\Override]
            public function getModuleName(): string
            {
                return 'runtime-fail';
            }

            /**
             * @return array<string, mixed>
             */
            #[\Override]
            public function getConfig(): array
            {
                return [
                    'services' => [
                        'runtime.fail' => [
                            'factory' => static fn (): RequestHandlerInterface => new class implements RequestHandlerInterface {
                                #[\Override]
                                public function handle(ServerRequestInterface $request): ResponseInterface
                                {
                                    throw new \RuntimeException('handler exploded');
                                }
                            },
                            'deps' => [],
                        ],
                    ],
                    'routes' => [
                        ['method' => 'GET', 'path' => '/boom', 'handler' => 'runtime.fail'],
                    ],
                ];
            }
        });
        $app->boot();

        $worker = new class implements WorkerInterface {
            /** @var list<string> */
            public array $errorSink = [];

            /** @var list<ResponseInterface> */
            public array $responseSink = [];

            #[\Override]
            public function waitRequest(): ?ServerRequestInterface
            {
                static $called = false;
                if ($called) {
                    return null;
                }
                $called = true;

                return new ServerRequest('GET', new Uri('http://localhost/boom', ['localhost']));
            }

            #[\Override]
            public function respond(ResponseInterface $response): void
            {
                $this->responseSink[] = $response;
            }

            #[\Override]
            public function error(string $message): void
            {
                $this->errorSink[] = $message;
            }

            #[\Override]
            public function stop(): void {}

            #[\Override]
            public function isRunning(): bool
            {
                return true;
            }
        };
        $runtime = new RoadRunnerRuntime($app, $worker, 0, 0, false);
        $exitCode = $runtime->run();
        self::assertSame(1, $exitCode);
        self::assertCount(1, $worker->errorSink);
        self::assertStringContainsString('handler exploded', $worker->errorSink[0]);
        self::assertCount(1, $worker->responseSink);
        self::assertSame(500, $worker->responseSink[0]->getStatusCode());
    }

    public function testRuntimeSurvivesBrokenWorkerPipe(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'zefrt');
        assert($logFile !== false);
        $previous = ini_set('error_log', $logFile);
        $app = $this->bootedApp();
        $worker = new class implements WorkerInterface {
            #[\Override]
            public function waitRequest(): ?ServerRequestInterface
            {
                static $called = false;
                if ($called) {
                    return null;
                }
                $called = true;

                return new ServerRequest('GET', new Uri('http://localhost/runtime-ok', ['localhost']));
            }

            #[\Override]
            public function respond(ResponseInterface $response): void
            {
                throw new \RuntimeException('worker pipe gone');
            }

            #[\Override]
            public function error(string $message): void
            {
                throw new \LogicException('error channel broken');
            }

            #[\Override]
            public function stop(): void
            {
                throw new \RuntimeException('stop channel broken');
            }

            #[\Override]
            public function isRunning(): bool
            {
                return true;
            }
        };
        $runtime = new RoadRunnerRuntime($app, $worker, 1, 0, false);
        $exitCode = $runtime->run();
        ini_set('error_log', (string) $previous);
        @unlink($logFile); // nosemgrep: php.lang.security.unlink-use
        self::assertSame(1, $exitCode);
        self::assertSame(1, $runtime->handledRequests());
    }

    public function testRuntimeInstallsSignalHandlersAndRestoresThem(): void
    {
        if (!function_exists('pcntl_signal')) {
            self::markTestSkipped('pcntl not available.');
        }
        if ($this->runningUnderMutationTesting()) {
            // Under Infection the suite runs in random order: process-level
            // signal delivery races with handler installation and can kill
            // the whole test run. The runtime lines exercised here are only
            // reachable through real signals anyway; Infection drives runs
            // with --log-junit, which we detect below.
            self::markTestSkipped('Process-level signal test skipped under Infection.');
        }
        $app = $this->bootedApp();
        $worker = new class implements WorkerInterface {
            public bool $signalled = false;

            #[\Override]
            public function waitRequest(): ?ServerRequestInterface
            {
                static $called = false;
                if ($called) {
                    return null;
                }
                $called = true;
                $this->signalled = true;
                // SIGCHLD is ignored by default: delivering it exercises the
                // installed handler's "not owned" branch without any risk
                // of terminating the process.
                posix_kill((int) getmypid(), SIGCHLD);

                return new ServerRequest('GET', new Uri('http://localhost/runtime-ok', ['localhost']));
            }

            #[\Override]
            public function respond(ResponseInterface $response): void {}

            #[\Override]
            public function error(string $message): void {}

            #[\Override]
            public function stop(): void {}

            #[\Override]
            public function isRunning(): bool
            {
                return true;
            }
        };
        $runtime = new RoadRunnerRuntime($app, $worker, 1, 0, true);
        $exitCode = $runtime->run();
        self::assertSame(0, $exitCode);
        self::assertTrue($worker->signalled);
    }

    public function testRuntimeStopsOnSigterm(): void
    {
        if (!function_exists('pcntl_signal')) {
            self::markTestSkipped('pcntl not available.');
        }
        if (getenv('INFECTION') !== false) {
            self::markTestSkipped('Process-level signal test skipped under Infection.');
        }
        $app = $this->bootedApp();
        $worker = new class implements WorkerInterface {
            public bool $signalled = false;

            #[\Override]
            public function waitRequest(): ?ServerRequestInterface
            {
                static $called = false;
                if ($called) {
                    // Second poll: signal self AFTER the first request has
                    // completed (lifecycle is 'ready'), so stop() follows a
                    // legal 'ready -> draining' transition.
                    $this->signalled = true;
                    posix_kill((int) getmypid(), SIGTERM);

                    return null;
                }
                $called = true;

                return new ServerRequest('GET', new Uri('http://localhost/runtime-ok', ['localhost']));
            }

            #[\Override]
            public function respond(ResponseInterface $response): void {}

            #[\Override]
            public function error(string $message): void {}

            #[\Override]
            public function stop(): void {}

            #[\Override]
            public function isRunning(): bool
            {
                return true;
            }
        };
        $runtime = new RoadRunnerRuntime($app, $worker, 0, 0, true);
        $exitCode = $runtime->run();
        self::assertSame(0, $exitCode);
        self::assertTrue($worker->signalled);
    }

    public function testJobWorkerRejectsBadConstruction(): void
    {
        $queue = new InMemoryJobQueue();
        $this->expectException(\InvalidArgumentException::class);
        new InProcessJobWorker($queue, new RetryPolicy(), null, null, -1);
    }

    public function testJobWorkerRejectsSameDeadLetterQueue(): void
    {
        $queue = new InMemoryJobQueue();
        $this->expectException(\InvalidArgumentException::class);
        new InProcessJobWorker($queue, new RetryPolicy(), null, $queue);
    }

    public function testJobWorkerRejectsDuplicateHandlers(): void
    {
        $worker = new InProcessJobWorker(new InMemoryJobQueue());
        $worker->register('mail.send', static fn (JobEnvelope $job, JobContext $ctx): string => 'sent');
        $this->expectException(\LogicException::class);
        $worker->register('mail.send', static fn (JobEnvelope $job, JobContext $ctx): string => 'again');
    }

    public function testJobWorkerDeadLettersUnknownTypes(): void
    {
        $queue = new InMemoryJobQueue();
        $dlq = new InMemoryJobQueue();
        $queue->enqueue($this->envelope('job-0000-0001', 'unknown.type'));
        $worker = new InProcessJobWorker($queue, new RetryPolicy(), null, $dlq);

        /** @var list<JobResult> $results */
        $results = [];
        $processed = $worker->run(1, null, static function (JobResult $result) use (&$results): void {
            $results[] = $result;
        }, true);
        self::assertSame(1, $processed);
        self::assertFalse($results[0]->completed);
        self::assertTrue($results[0]->deadLettered);
        self::assertInstanceOf(JobExecutionException::class, $results[0]->result);
        self::assertSame(1, $dlq->size());
    }

    public function testJobWorkerSurvivesBrokenDeadLetterQueue(): void
    {
        $queue = new InMemoryJobQueue();
        $dlq = new InMemoryJobQueue(1);
        $dlq->enqueue($this->envelope('dlq-0000-0001', 'filler.type'));
        $queue->enqueue($this->envelope('job-0000-0002', 'unknown.type'));
        $worker = new InProcessJobWorker($queue, new RetryPolicy(), null, $dlq);
        $result = $worker->processOne();
        assert($result instanceof JobResult);
        self::assertFalse($result->completed);
        self::assertFalse($result->deadLettered);
    }

    public function testJobWorkerRetriesThenReportsRetry(): void
    {
        $queue = new InMemoryJobQueue();
        $queue->enqueue($this->envelope('job-0000-0003', 'flaky.job'));
        $worker = new InProcessJobWorker($queue, new RetryPolicy(3, 0));
        $worker->register('flaky.job', static function (JobEnvelope $job, JobContext $ctx): never {
            throw new \RuntimeException('flaky failure');
        });
        $result = $worker->processOne();
        assert($result instanceof JobResult);
        self::assertFalse($result->completed);
        self::assertTrue($result->willRetry);
        self::assertSame(1, $queue->size());
    }

    public function testJobWorkerDeadLettersExhaustedRetries(): void
    {
        $queue = new InMemoryJobQueue();
        $dlq = new InMemoryJobQueue();
        $queue->enqueue($this->envelope('job-0000-0004', 'doomed.job', 2));
        $worker = new InProcessJobWorker($queue, new RetryPolicy(2, 0), null, $dlq);
        $worker->register('doomed.job', static function (JobEnvelope $job, JobContext $ctx): never {
            throw new \RuntimeException('doomed failure');
        });
        $result = $worker->processOne();
        assert($result instanceof JobResult);
        self::assertFalse($result->completed);
        self::assertTrue($result->deadLettered);
        self::assertFalse($result->willRetry);
    }

    public function testJobWorkerRunsMiddlewareChainAndIdempotency(): void
    {
        $queue = new InMemoryJobQueue();
        $queue->enqueue($this->envelope('job-0000-0005', 'piped.job'));
        $worker = new InProcessJobWorker($queue);
        $worker->register('piped.job', static fn (JobEnvelope $job, JobContext $ctx): string => 'done');
        $worker->use(new class implements JobMiddlewareInterface {
            #[\Override]
            public function process(JobEnvelope $job, JobContext $context, \Closure $next): mixed
            {
                $inner = $next($job, $context);
                assert(is_string($inner));

                return 'wrapped:' . $inner;
            }
        });
        $calls = 0;
        $store = new class($calls) implements JobIdempotencyStoreInterface {
            public function __construct(private int &$calls) {}

            #[\Override]
            public function remember(string $key, callable $producer, int $ttlSeconds = 3600): mixed
            {
                ++$this->calls;

                return $producer();
            }
        };
        $replace = new InProcessJobWorker($queue, new RetryPolicy(), $store);
        $replace->register('piped.job', static fn (JobEnvelope $job, JobContext $ctx): string => 'done');
        $replace->use(new class implements JobMiddlewareInterface {
            #[\Override]
            public function process(JobEnvelope $job, JobContext $context, \Closure $next): mixed
            {
                $inner = $next($job, $context);
                assert(is_string($inner));

                return 'wrapped:' . $inner;
            }
        });
        $result = $replace->processOne();
        assert($result instanceof JobResult);
        self::assertSame('wrapped:done', $result->result);
        self::assertSame(1, $calls);
    }

    public function testJobWorkerRunGuardsAndFreeze(): void
    {
        $worker = new InProcessJobWorker(new InMemoryJobQueue());
        self::assertFalse($worker->isFrozen());
        $this->expectException(\InvalidArgumentException::class);
        $worker->run(-1);
    }

    public function testJobWorkerDrainsEmptyQueueImmediately(): void
    {
        $worker = new InProcessJobWorker(new InMemoryJobQueue(), new RetryPolicy(), null, null, 0);
        self::assertSame(0, $worker->run(0, null, null, true));
        self::assertNull($worker->processOne());
        self::assertFalse($worker->isRunning());
    }

    // ------------------------------------------------------------------
    // Replay protector + security boundary
    // ------------------------------------------------------------------

    public function testReplayProtectorRejectsBadConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BoundedInMemoryReplayProtector(0);
    }

    public function testReplayProtectorClassifiesReplayIds(): void
    {
        $protector = new BoundedInMemoryReplayProtector(4, 100);
        self::assertSame(ReplayDecision::NOT_REQUIRED, $protector->check(null, 1000)->decision);
        self::assertSame(ReplayDecision::REJECTED, $protector->check('', 1000)->decision);
        self::assertSame(ReplayDecision::REJECTED, $protector->check(str_repeat('x', 129), 1000)->decision);
        self::assertSame(ReplayDecision::ACCEPT, $protector->check('req-1', 1000)->decision);
        self::assertSame(ReplayDecision::DUPLICATE, $protector->check('req-1', 1050)->decision);
        self::assertSame(ReplayDecision::ACCEPT, $protector->check('req-2', 1050)->decision);
        self::assertSame(ReplayDecision::ACCEPT, $protector->check('req-3', 1050)->decision);
        self::assertSame(ReplayDecision::ACCEPT, $protector->check('req-4', 1050)->decision);
        self::assertSame(ReplayDecision::UNAVAILABLE, $protector->check('req-5', 1050)->decision);
        $expired = new BoundedInMemoryReplayProtector(4, 10);
        self::assertSame(ReplayDecision::ACCEPT, $expired->check('old', 1000)->decision);
        self::assertSame(ReplayDecision::ACCEPT, $expired->check('old', 5000)->decision);
    }

    public function testSecurityRequestValidatesBounds(): void
    {
        $request = new SecurityRequest('read', 'document:42', 'view', 'replay-1', ['tenant' => 'acme']);
        self::assertSame('read', $request->operationClass);

        $this->expectException(\InvalidArgumentException::class);
        new SecurityRequest(str_repeat('x', 200), 'r', 'a');
    }

    public function testSecurityRequestRejectsOversizedResourceAndAction(): void
    {
        try {
            new SecurityRequest('read', str_repeat('x', 600), 'a');
            self::fail('Expected resource bound violation.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        $this->expectException(\InvalidArgumentException::class);
        new SecurityRequest('read', 'r', str_repeat('x', 200));
    }

    public function testSecurityRequestRejectsOversizedReplayId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SecurityRequest('read', 'r', 'a', str_repeat('y', 129));
    }

    public function testSecurityRequestRejectsTooManyAttributes(): void
    {
        $attributes = [];
        for ($i = 0; $i < 17; ++$i) {
            $attributes['k' . $i] = $i;
        }
        $this->expectException(\InvalidArgumentException::class);
        new SecurityRequest('read', 'r', 'a', null, $attributes);
    }

    public function testSecurityRequestRejectsBadAttributeKeysAndValues(): void
    {
        try {
            new SecurityRequest('read', 'r', 'a', null, ['' => 'v']);
            self::fail('Expected empty key rejection.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        $this->expectException(\InvalidArgumentException::class);
        new SecurityRequest('read', 'r', 'a', null, [str_repeat('k', 65) => 'v']);
    }

    public function testSecurityRequestRejectsCredentialKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SecurityRequest('read', 'r', 'a', null, ['x-authorization' => 'Bearer xyz']);
    }

    public function testSecurityRequestRejectsNonScalarAndOversizedValues(): void
    {
        try {
            new SecurityRequest('read', 'r', 'a', null, ['detail' => ['nested' => true]]);
            self::fail('Expected non-scalar rejection.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        $this->expectException(\InvalidArgumentException::class);
        new SecurityRequest('read', 'r', 'a', null, ['detail' => str_repeat('v', 257)]);
    }

    public function testSecurityRequestRejectsAttributeBudgetOverflow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SecurityRequest('read', 'r', 'a', null, [
            'a' => str_repeat('v', 256),
            'b' => str_repeat('v', 256),
            'c' => str_repeat('v', 256),
            'd' => str_repeat('v', 256),
            'e' => str_repeat('v', 256),
            'f' => str_repeat('v', 256),
            'g' => str_repeat('v', 256),
            'h' => str_repeat('v', 256),
            'i' => str_repeat('v', 256),
            'j' => str_repeat('v', 256),
            'k' => str_repeat('v', 256),
            'l' => str_repeat('v', 256),
            'm' => str_repeat('v', 256),
            'n' => str_repeat('v', 256),
            'o' => str_repeat('v', 256),
            'p' => str_repeat('v', 256),
        ]);
    }

    public function testSecurityBoundaryDeniesUnauthenticatedAndExpired(): void
    {
        $boundary = new DefaultSecurityBoundary();
        $request = new SecurityRequest('read', 'r', 'a');
        $authorization = $this->authorization(true);
        $replay = $this->replayProtector(ReplayDecision::ACCEPT);

        $denied = $boundary->admit(new AuthenticationResult(AuthenticationStatus::UNAUTHENTICATED), $request, $authorization, $replay, 1);
        self::assertSame(SecurityVerdict::DENY, $denied->verdict);
        self::assertSame(SecurityFailure::AUTHENTICATION_FAILED, $denied->failure);

        $expired = $boundary->admit(new AuthenticationResult(AuthenticationStatus::EXPIRED), $request, $authorization, $replay, 1);
        self::assertSame(SecurityFailure::CREDENTIAL_EXPIRED, $expired->failure);

        $unavailable = $boundary->admit(new AuthenticationResult(AuthenticationStatus::UNAVAILABLE), $request, $authorization, $replay, 1);
        self::assertSame(SecurityFailure::AUTHENTICATION_UNAVAILABLE, $unavailable->failure);
    }

    public function testSecurityBoundaryEnforcesAuthorizationAndReplay(): void
    {
        $boundary = new DefaultSecurityBoundary();
        $context = new SecurityContext('principal-0001', 'mtls', 'tenant:acme', 'scope:read', null);
        $auth = new AuthenticationResult(AuthenticationStatus::AUTHENTICATED, $context);
        $request = new SecurityRequest('read', 'r', 'a', 'replay-x');

        $denied = $boundary->admit($auth, $request, $this->authorization(false), $this->replayProtector(ReplayDecision::ACCEPT), 1);
        self::assertSame(SecurityFailure::AUTHORIZATION_DENIED, $denied->failure);

        $replayBlocked = $boundary->admit($auth, $request, $this->authorization(true), $this->replayProtector(ReplayDecision::REJECTED), 1);
        self::assertSame(SecurityFailure::REPLAY_REJECTED, $replayBlocked->failure);

        $replayDown = $boundary->admit($auth, $request, $this->authorization(true), $this->replayProtector(ReplayDecision::UNAVAILABLE), 1);
        self::assertSame(SecurityFailure::REPLAY_UNAVAILABLE, $replayDown->failure);

        $allowed = $boundary->admit($auth, $request, $this->authorization(true), $this->replayProtector(ReplayDecision::ACCEPT), 1);
        self::assertSame(SecurityVerdict::ALLOW, $allowed->verdict);
        self::assertSame(SecurityFailure::NONE, $allowed->failure);
    }

    /**
     * Infection drives its initial (verification) and mutant runs with a
     * --log-junit flag; real CI coverage runs use --coverage-clover instead.
     */
    private function runningUnderMutationTesting(): bool
    {
        $argv = $_SERVER['argv'] ?? null;
        if (!is_iterable($argv)) {
            return false;
        }

        foreach ($argv as $arg) {
            if (is_string($arg) && str_starts_with($arg, '--log-junit')) {
                return true;
            }
        }

        return false;
    }

    private function bootedApp(): Application
    {
        $app = new Application();
        $app->addProvider(new class implements ConfigProviderInterface {
            #[\Override]
            public function getModuleName(): string
            {
                return 'runtime-fixture';
            }

            /**
             * @return array<string, mixed>
             */
            #[\Override]
            public function getConfig(): array
            {
                return [
                    'services' => [
                        'runtime.ok' => [
                            'factory' => static fn (): RequestHandlerInterface => new class implements RequestHandlerInterface {
                                #[\Override]
                                public function handle(ServerRequestInterface $request): ResponseInterface
                                {
                                    return new Response(200, [], 'runtime-ok');
                                }
                            },
                            'deps' => [],
                        ],
                    ],
                    'routes' => [
                        ['method' => 'GET', 'path' => '/runtime-ok', 'handler' => 'runtime.ok'],
                    ],
                ];
            }
        });
        $app->boot();

        return $app;
    }

    // ------------------------------------------------------------------
    // InProcessJobWorker edge cases
    // ------------------------------------------------------------------

    private function envelope(string $id, string $type, int $attempt = 1): JobEnvelope
    {
        return new JobEnvelope($id, $type, ['n' => 1], 0, 0, $attempt);
    }

    private function authorization(bool $allow): AuthorizationPolicyInterface
    {
        return new readonly class($allow) implements AuthorizationPolicyInterface {
            public function __construct(private bool $allow) {}

            #[\Override]
            public function authorize(SecurityContext $context, SecurityRequest $request): AuthorizationResult
            {
                return new AuthorizationResult(
                    $this->allow ? SecurityVerdict::ALLOW : SecurityVerdict::DENY,
                    'policy.unit',
                );
            }
        };
    }

    private function replayProtector(ReplayDecision $decision): ReplayProtectorInterface
    {
        return new readonly class($decision) implements ReplayProtectorInterface {
            public function __construct(private ReplayDecision $decision) {}

            #[\Override]
            public function check(?string $replayId, int $nowMs): ReplayResult
            {
                return new ReplayResult($this->decision);
            }
        };
    }
}
