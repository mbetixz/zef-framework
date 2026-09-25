<?php

declare(strict_types=1);

/*
 * ZEF Framework — v2.26.0 Async Runtime: blocking primitives.
 *
 * Deterministic tests for the fiber channel, semaphore, wait group,
 * coroutine-local storage and cancellation source: FIFO order, park/wake
 * pairing, close/cancel splicing, guard rails and idempotency, so a mutant
 * in any queue arithmetic (shift vs pop, splice filters, counter guards)
 * cannot survive.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Runtime\Async\AsyncException;
use Zef\Framework\Runtime\Async\AsyncTimeoutException;
use Zef\Framework\Runtime\Async\CancellationTokenSource;
use Zef\Framework\Runtime\Async\ChannelClosedException;
use Zef\Framework\Runtime\Async\CoroutineLocal;
use Zef\Framework\Runtime\Async\FiberChannel;
use Zef\Framework\Runtime\Async\FiberScheduler;
use Zef\Framework\Runtime\Async\FiberTask;
use Zef\Framework\Runtime\Async\HrMonotonicClock;
use Zef\Framework\Runtime\Async\MonotonicClockInterface;
use Zef\Framework\Runtime\Async\Semaphore;
use Zef\Framework\Runtime\Async\SuspensionHandle;
use Zef\Framework\Runtime\Async\TaskCancelledException;
use Zef\Framework\Runtime\Async\TaskState;
use Zef\Framework\Runtime\Async\WaitGroup;

/**
 * @internal
 */
final class AsyncPrimitivesV26Test extends TestCase
{
    private FakeAsyncClock $clock;

    private FakeAsyncSleeper $sleeper;

    private FiberScheduler $scheduler;

    protected function setUp(): void
    {
        $this->clock = new FakeAsyncClock();
        $this->sleeper = new FakeAsyncSleeper($this->clock);
        $this->scheduler = new FiberScheduler($this->clock, $this->sleeper);
    }

    // ------------------------------------------------------------------
    // FiberChannel
    // ------------------------------------------------------------------

    public function testChannelCapacityMustBePositive(): void
    {
        try {
            new FiberChannel($this->scheduler, 0);
            self::fail('zero capacity must be rejected');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Channel capacity must be >= 1, got 0.', $exception->getMessage());
        }
    }

    public function testBufferedValuesKeepStrictFifoOrder(): void
    {
        $scheduler = $this->scheduler;
        $received = null;

        $scheduler->run(function () use ($scheduler, &$received): string {
            $channel = new FiberChannel($scheduler, 3);
            $channel->send(1);
            $channel->send('two');
            $channel->send([3]);

            self::assertSame(3, $channel->count());
            $received = [$channel->receive(), $channel->receive(), $channel->receive()];

            return 'ok';
        });

        self::assertSame([1, 'two', [3]], $received);
    }

    public function testSendParksWhenFullAndDeliversThroughTheHandoff(): void
    {
        $scheduler = $this->scheduler;
        $received = null;

        $scheduler->run(function () use ($scheduler, &$received): string {
            $channel = new FiberChannel($scheduler, 1);
            $channel->send('first');

            $sender = $scheduler->spawn(function () use ($channel): string {
                $channel->send('second');

                return 'sent';
            }, 'sender');
            $scheduler->suspend();
            self::assertSame(1, $channel->count(), 'the parked sender must not buffer yet');

            $receiver = $scheduler->spawn(fn (): array => [$channel->receive(), $channel->receive()], 'receiver');
            $scheduler->await($sender);
            $received = $scheduler->await($receiver);

            return 'ok';
        });

        self::assertSame(['first', 'second'], $received);
    }

    public function testReceiveParksWhenEmptyAndTakesTheDirectHandoff(): void
    {
        $scheduler = $this->scheduler;
        $value = null;

        $scheduler->run(function () use ($scheduler, &$value): string {
            $channel = new FiberChannel($scheduler, 2);
            $receiver = $scheduler->spawn(static fn (): mixed => $channel->receive(), 'receiver');
            $scheduler->suspend();
            $channel->send('handoff');
            $value = $scheduler->await($receiver);

            return 'ok';
        });

        self::assertSame('handoff', $value);
    }

    public function testSendOnClosedChannelThrows(): void
    {
        $scheduler = $this->scheduler;
        $message = null;

        $scheduler->run(function () use ($scheduler, &$message): string {
            $channel = new FiberChannel($scheduler);
            $channel->close();
            self::assertTrue($channel->isClosed());

            try {
                $channel->send('x');
            } catch (ChannelClosedException $exception) {
                $message = $exception->getMessage();
            }

            return 'ok';
        });

        self::assertSame('cannot send: the channel is closed', $message);
    }

    public function testReceiveOnClosedAndDrainedChannelThrows(): void
    {
        $scheduler = $this->scheduler;
        $messages = [];

        $scheduler->run(function () use ($scheduler, &$messages): string {
            $channel = new FiberChannel($scheduler);
            $channel->send('last');
            $channel->close();

            $messages[] = $channel->receive();
            self::assertSame(0, $channel->count());

            try {
                $channel->receive();
            } catch (ChannelClosedException $exception) {
                $messages[] = $exception->getMessage();
            }

            return 'ok';
        });

        self::assertSame(['last', 'cannot receive: the channel is closed and drained'], $messages);
    }

    public function testCloseIsIdempotent(): void
    {
        $scheduler = $this->scheduler;
        $closedTwice = false;

        $scheduler->run(function () use ($scheduler, &$closedTwice): string {
            $channel = new FiberChannel($scheduler);
            $channel->close();
            $channel->close();
            $closedTwice = true;

            return 'ok';
        });

        self::assertTrue($closedTwice);
    }

    public function testCloseFailsParkedSendersButKeepsTheirBufferedPredecessors(): void
    {
        $scheduler = $this->scheduler;
        $outcome = [];

        $scheduler->run(function () use ($scheduler, &$outcome): string {
            $channel = new FiberChannel($scheduler, 1);
            $channel->send('buffered');

            $sender = $scheduler->spawn(function () use ($channel, &$outcome): string {
                try {
                    $channel->send('doomed');
                } catch (ChannelClosedException $exception) {
                    $outcome[] = 'sender: ' . $exception->getMessage();
                }

                return 'done';
            }, 'sender');
            $scheduler->suspend();

            $channel->close();
            $scheduler->await($sender);

            $drained = $channel->receive();
            assert(is_string($drained));
            $outcome[] = 'drain: ' . $drained;

            try {
                $channel->receive();
            } catch (ChannelClosedException $exception) {
                $outcome[] = 'after-drain: ' . $exception->getMessage();
            }

            return 'ok';
        });

        self::assertSame([
            'sender: cannot send: the channel was closed while waiting',
            'drain: buffered',
            'after-drain: cannot receive: the channel is closed and drained',
        ], $outcome);
    }

    public function testCloseFailsParkedReceivers(): void
    {
        $scheduler = $this->scheduler;
        $message = null;

        $scheduler->run(function () use ($scheduler, &$message): string {
            $channel = new FiberChannel($scheduler);
            $receiver = $scheduler->spawn(static fn (): mixed => $channel->receive(), 'receiver');
            $scheduler->suspend();

            $channel->close();

            try {
                $scheduler->await($receiver);
            } catch (ChannelClosedException $exception) {
                $message = $exception->getMessage();
            }

            return 'ok';
        });

        self::assertSame('cannot receive: the channel was closed while waiting', $message);
    }

    public function testCancelledReceiverIsSplicedOutOfTheQueue(): void
    {
        $scheduler = $this->scheduler;
        $value = null;

        $scheduler->run(function () use ($scheduler, &$value): string {
            $channel = new FiberChannel($scheduler);
            $receiver = $scheduler->spawn(static fn (): mixed => $channel->receive(), 'victim');
            $scheduler->suspend();
            self::assertTrue($receiver->cancel());

            try {
                $scheduler->await($receiver);
            } catch (TaskCancelledException) {
                // expected
            }

            $channel->send('survivor');
            $value = $channel->receive();

            return 'ok';
        });

        self::assertSame('survivor', $value, 'the stale parked receiver must not swallow the value');
    }

    public function testAdmitOneSenderKeepsFifoWhenBufferSlotFrees(): void
    {
        $scheduler = $this->scheduler;
        $received = null;

        $scheduler->run(function () use ($scheduler, &$received): string {
            $channel = new FiberChannel($scheduler, 1);
            $channel->send('v1');

            $sender = $scheduler->spawn(function () use ($channel): string {
                $channel->send('v2');

                return 'sent';
            }, 'sender');
            $scheduler->suspend();

            $first = $channel->receive();
            $second = $channel->receive();
            $scheduler->await($sender);
            $received = [$first, $second];

            return 'ok';
        });

        self::assertSame(['v1', 'v2'], $received);
    }

    public function testSenderCancellationLosesTheParkedValueButNotTheBuffer(): void
    {
        $scheduler = $this->scheduler;
        $received = null;

        $scheduler->run(function () use ($scheduler, &$received): string {
            $channel = new FiberChannel($scheduler, 1);
            $channel->send('kept');

            $sender = $scheduler->spawn(static fn () => $channel->send('lost'), 'sender');
            $scheduler->suspend();
            self::assertTrue($sender->cancel());

            try {
                $scheduler->await($sender);
            } catch (TaskCancelledException) {
                // expected
            }

            $received = $channel->receive();

            return 'ok';
        });

        self::assertSame('kept', $received);
    }

    // ------------------------------------------------------------------
    // Semaphore
    // ------------------------------------------------------------------

    public function testSemaphoreRequiresAtLeastOnePermit(): void
    {
        try {
            new Semaphore($this->scheduler, 0);
            self::fail('zero permits must be rejected');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Semaphore permit count must be >= 1, got 0.', $exception->getMessage());
        }
    }

    public function testTryAcquireReflectsPermitAvailability(): void
    {
        $semaphore = new Semaphore($this->scheduler, 2);

        self::assertTrue($semaphore->tryAcquire());
        self::assertTrue($semaphore->tryAcquire());
        self::assertFalse($semaphore->tryAcquire());
    }

    public function testAcquireParksAtZeroAndReleaseWakesTheOldestWaiter(): void
    {
        $scheduler = $this->scheduler;
        $acquired = [];

        $scheduler->run(function () use ($scheduler, &$acquired): string {
            $semaphore = new Semaphore($scheduler, 1);
            $semaphore->acquire();

            $first = $scheduler->spawn(function () use ($semaphore): string {
                $semaphore->acquire();

                return 'first-woke';
            }, 'waiter-1');
            $second = $scheduler->spawn(function () use ($semaphore): string {
                $semaphore->acquire();

                return 'second-woke';
            }, 'waiter-2');
            $scheduler->suspend();

            $semaphore->release();
            $acquired[] = $scheduler->await($first);
            $semaphore->release();
            $acquired[] = $scheduler->await($second);

            return 'ok';
        });

        self::assertSame(['first-woke', 'second-woke'], $acquired);
    }

    public function testReleaseWithoutAcquisitionThrows(): void
    {
        $semaphore = new Semaphore($this->scheduler, 1);

        try {
            $semaphore->release();
            self::fail('over-release must throw');
        } catch (\LogicException $exception) {
            self::assertSame('semaphore over-release: more release() calls than acquired permits', $exception->getMessage());
        }
    }

    public function testReleaseBeyondThePermitCountThrowsEvenAfterACompletedCycle(): void
    {
        $scheduler = $this->scheduler;

        $scheduler->run(function () use ($scheduler): string {
            $semaphore = new Semaphore($scheduler, 1);
            $semaphore->acquire();
            $semaphore->release();

            try {
                $semaphore->release();
                self::fail('the second release must exceed the permit pool');
            } catch (\LogicException $exception) {
                self::assertStringContainsString('over-release', $exception->getMessage());
            }

            return 'ok';
        });
    }

    public function testAcquireWithCancelledTokenFailsImmediately(): void
    {
        $scheduler = $this->scheduler;
        $message = null;

        $scheduler->run(function () use ($scheduler, &$message): string {
            $source = new CancellationTokenSource();
            $source->cancel();
            $semaphore = new Semaphore($scheduler, 1);

            try {
                $semaphore->acquire($source->token());
            } catch (TaskCancelledException $exception) {
                $message = $exception->getMessage();
            }

            return 'ok';
        });

        self::assertSame('cancellation requested through the token source', $message);
    }

    public function testAcquireIsAbortedWhenTheTokenCancelsMidPark(): void
    {
        $scheduler = $this->scheduler;
        $message = null;

        $scheduler->run(function () use ($scheduler, &$message): string {
            $source = new CancellationTokenSource();
            $semaphore = new Semaphore($scheduler, 1);
            $semaphore->acquire();

            $waiter = $scheduler->spawn(function () use ($semaphore, $source): string {
                $semaphore->acquire($source->token());

                return 'acquired';
            }, 'waiter');
            $scheduler->suspend();
            $source->cancel();

            try {
                $scheduler->await($waiter);
            } catch (TaskCancelledException $exception) {
                $message = $exception->getMessage();
            }

            return 'ok';
        });

        self::assertSame('semaphore acquisition cancelled by token', $message);
    }

    public function testSemaphoreCapsObservedConcurrency(): void
    {
        $scheduler = $this->scheduler;
        $maxSeen = 0;

        $scheduler->run(function () use ($scheduler, &$maxSeen): string {
            $semaphore = new Semaphore($scheduler, 2);
            $current = 0;
            $waitGroup = new WaitGroup($scheduler);
            $waitGroup->add(5);

            for ($i = 0; $i < 5; ++$i) {
                $scheduler->spawn(function () use ($scheduler, $semaphore, &$maxSeen, &$current, $waitGroup): string {
                    $semaphore->acquire();
                    ++$current;
                    $maxSeen = max($maxSeen, $current);
                    $scheduler->suspend();
                    $scheduler->suspend();
                    --$current;
                    $semaphore->release();
                    $waitGroup->done();

                    return 'worker';
                }, 'worker-' . $i);
            }

            $waitGroup->await();

            return 'ok';
        });

        self::assertSame(2, $maxSeen);
    }

    public function testCancelledWaiterIsSplicedSoTheNextReleaseWakesTheRightTask(): void
    {
        $scheduler = $this->scheduler;
        $result = null;

        $scheduler->run(function () use ($scheduler, &$result): string {
            $semaphore = new Semaphore($scheduler, 1);
            $semaphore->acquire();

            $victim = $scheduler->spawn(static fn () => $semaphore->acquire(), 'victim');
            $scheduler->suspend();
            $victim->cancel();

            try {
                $scheduler->await($victim);
            } catch (TaskCancelledException) {
                // expected
            }

            $survivor = $scheduler->spawn(static fn () => $semaphore->acquire(), 'survivor');
            $scheduler->suspend();
            $semaphore->release();
            $result = $scheduler->await($survivor);
            $semaphore->release();

            return 'ok';
        });

        self::assertNull($result, 'acquire() returns void; waking the survivor proves the splice');
    }

    // ------------------------------------------------------------------
    // WaitGroup
    // ------------------------------------------------------------------

    public function testWaitGroupAddRequiresPositiveDelta(): void
    {
        $waitGroup = new WaitGroup($this->scheduler);

        try {
            $waitGroup->add(0);
            self::fail('zero delta must be rejected');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('WaitGroup::add() requires a positive delta, got 0.', $exception->getMessage());
        }
    }

    public function testWaitGroupDoneWithoutAddThrows(): void
    {
        $waitGroup = new WaitGroup($this->scheduler);

        try {
            $waitGroup->done();
            self::fail('done() without add() must throw');
        } catch (\LogicException $exception) {
            self::assertSame('WaitGroup::done() called without a matching add()', $exception->getMessage());
        }
    }

    public function testWaitGroupCountsAndWakesAllWaitersAtZero(): void
    {
        $scheduler = $this->scheduler;
        $woke = [];

        $scheduler->run(function () use ($scheduler, &$woke): string {
            $waitGroup = new WaitGroup($scheduler);
            $waitGroup->add();
            $waitGroup->add(1);
            self::assertSame(2, $waitGroup->count(), 'two add() calls must accumulate');

            $waiterA = $scheduler->spawn(function () use ($waitGroup): string {
                $waitGroup->await();

                return 'A';
            }, 'waiter-a');
            $waiterB = $scheduler->spawn(function () use ($waitGroup): string {
                $waitGroup->await();

                return 'B';
            }, 'waiter-b');
            $scheduler->suspend();

            $waitGroup->done();
            self::assertSame(1, $waitGroup->count());
            $waitGroup->done();
            self::assertSame(0, $waitGroup->count());

            $woke[] = $scheduler->await($waiterA);
            $woke[] = $scheduler->await($waiterB);

            return 'ok';
        });

        self::assertSame(['A', 'B'], $woke);
    }

    public function testWaitGroupAwaitReturnsImmediatelyAtZero(): void
    {
        $scheduler = $this->scheduler;
        $finished = false;

        $scheduler->run(function () use ($scheduler, &$finished): string {
            $waitGroup = new WaitGroup($scheduler);
            $waitGroup->await();
            $finished = true;

            return 'ok';
        });

        self::assertTrue($finished);
    }

    public function testCancelledWaitGroupWaiterIsSplicedAndOthersStillWake(): void
    {
        $scheduler = $this->scheduler;
        $survivorWoke = false;

        $scheduler->run(function () use ($scheduler, &$survivorWoke): string {
            $waitGroup = new WaitGroup($scheduler);
            $waitGroup->add(1);

            $victim = $scheduler->spawn(static fn () => $waitGroup->await(), 'victim');
            $survivor = $scheduler->spawn(function () use ($waitGroup, &$survivorWoke): string {
                $waitGroup->await();
                $survivorWoke = true;

                return 'survivor';
            }, 'survivor');
            $scheduler->suspend();
            $victim->cancel();

            try {
                $scheduler->await($victim);
            } catch (TaskCancelledException) {
                // expected
            }

            $waitGroup->done();
            $scheduler->await($survivor);

            return 'ok';
        });

        self::assertTrue($survivorWoke);
    }

    // ------------------------------------------------------------------
    // CancellationTokenSource / token
    // ------------------------------------------------------------------

    public function testCancelIsOnceOnlyAndTracked(): void
    {
        $source = new CancellationTokenSource();
        $token = $source->token();

        self::assertFalse($source->isCancellationRequested());
        self::assertFalse($token->isCancelled());
        $token->throwIfCancelled();

        self::assertTrue($source->cancel());
        self::assertFalse($source->cancel());
        self::assertTrue($source->isCancellationRequested());
        self::assertTrue($token->isCancelled());

        try {
            $token->throwIfCancelled();
            self::fail('throwIfCancelled must throw after cancel()');
        } catch (TaskCancelledException $exception) {
            self::assertSame('cancellation requested through the token source', $exception->getMessage());
        }
    }

    public function testCallbacksFireInRegistrationOrderAndOnlyOnce(): void
    {
        $source = new CancellationTokenSource();
        $fired = [];

        $source->token()->register(static function () use (&$fired): void {
            $fired[] = 'first';
        });
        $source->token()->register(static function () use (&$fired): void {
            $fired[] = 'second';
        });

        $source->cancel();
        self::assertSame(['first', 'second'], $fired);

        $source->cancel();
        self::assertSame(['first', 'second'], $fired, 'callbacks must not re-fire on repeated cancel()');
    }

    public function testUnregisterRemovesTheCallbackExactlyOnce(): void
    {
        $source = new CancellationTokenSource();
        $fired = [];

        $source->token()->register(static function () use (&$fired): void {
            $fired[] = 'kept';
        });
        $dropped = $source->token()->register(static function () use (&$fired): void {
            $fired[] = 'dropped';
        });

        self::assertTrue($dropped());
        self::assertFalse($dropped());

        $source->cancel();

        self::assertSame(['kept'], $fired);
    }

    public function testRegisterOnAlreadyCancelledSourceFiresImmediately(): void
    {
        $source = new CancellationTokenSource();
        $source->cancel();

        $fired = [];
        $unregister = $source->token()->register(static function () use (&$fired): void {
            $fired[] = 'late';
        });

        self::assertSame(['late'], $fired);
        self::assertFalse($unregister(), 'unregistering an already-fired late callback reports false');
    }

    // ------------------------------------------------------------------
    // CoroutineLocal
    // ------------------------------------------------------------------

    public function testCoroutineLocalRoundTripsAndFallsBackToDefault(): void
    {
        $scheduler = $this->scheduler;
        $observed = null;

        $scheduler->run(function () use (&$observed): string {
            $local = new CoroutineLocal();
            $local->set('user', 'ica');
            $local->set('tenant', 'acme');
            self::assertSame('ica', $local->get('user'), 'the first write must survive a second write to the same scope');
            self::assertSame('acme', $local->get('tenant'));
            self::assertNull($local->get('missing'));
            self::assertSame('fallback', $local->get('missing', 'fallback'));

            $observed = $local->get('user');

            return 'ok';
        });

        self::assertSame('ica', $observed);
    }

    public function testCoroutineLocalScopesAreIsolatedPerCoroutine(): void
    {
        $scheduler = $this->scheduler;
        $observed = [];

        $scheduler->run(function () use ($scheduler, &$observed): string {
            $local = new CoroutineLocal();

            $read = static function (CoroutineLocal $scope): string {
                $value = $scope->get('k');
                assert(is_string($value));

                return $value;
            };

            $one = $scheduler->spawn(static function () use ($local, $read): string {
                $local->set('k', 'one');
                $local->set('k', 'one-again');

                return $read($local);
            }, 'one');
            $two = $scheduler->spawn(static function () use ($local, $read): string {
                self::assertNull($local->get('k'), 'sibling coroutines must not see each other');
                $local->set('k', 'two');

                return $read($local);
            }, 'two');

            $observed = $scheduler->awaitAll([$one, $two]);

            return 'ok';
        });

        self::assertSame(['one-again', 'two'], $observed);
    }

    public function testCoroutineLocalThrowsOutsideAnyCoroutine(): void
    {
        $local = new CoroutineLocal();

        try {
            $local->set('k', 1);
            self::fail('set() outside a coroutine must throw');
        } catch (\LogicException $exception) {
            self::assertSame('CoroutineLocal can only be used inside a coroutine.', $exception->getMessage());
        }

        try {
            $local->get('k');
            self::fail('get() outside a coroutine must throw');
        } catch (\LogicException $exception) {
            self::assertSame('CoroutineLocal can only be used inside a coroutine.', $exception->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // v2.26.0 mutation-driven edge cases
    // ------------------------------------------------------------------

    public function testDefaultChannelCapacityIsOne(): void
    {
        $scheduler = $this->scheduler;

        $scheduler->run(function () use ($scheduler): string {
            $channel = new FiberChannel($scheduler);
            $channel->send('first');

            $parked = $scheduler->spawn(static fn () => $channel->send('second'), 'parked-sender');
            $scheduler->suspend();

            self::assertSame(1, $channel->count(), 'the second send must park: default capacity is 1');
            self::assertTrue($parked->state() === TaskState::Running);

            $first = $channel->receive();
            assert(is_string($first));
            $scheduler->await($parked);

            return $first;
        });
    }

    public function testSendHandoffToParkedReceiverDoesNotAlsoBuffer(): void
    {
        $scheduler = $this->scheduler;

        $scheduler->run(function () use ($scheduler): string {
            $channel = new FiberChannel($scheduler);
            $receiver = $scheduler->spawn(static fn (): mixed => $channel->receive(), 'receiver');
            $scheduler->suspend();

            $channel->send('handoff');

            self::assertSame(0, $channel->count(), 'a direct handoff must not leave a buffered copy');

            $value = $scheduler->await($receiver);
            assert(is_string($value));

            return $value;
        });
    }

    public function testAdmitOneSenderRefillsTheBufferAndFinishesTheSender(): void
    {
        $scheduler = $this->scheduler;

        $scheduler->run(function () use ($scheduler): string {
            $channel = new FiberChannel($scheduler, 1);
            $channel->send('v1');

            $sender = $scheduler->spawn(static fn () => $channel->send('v2'), 'sender');
            $scheduler->suspend();

            $first = $channel->receive();
            assert(is_string($first));
            self::assertSame(1, $channel->count(), 'the parked sender value must move into the buffer');
            $scheduler->await($sender);
            $second = $channel->receive();
            assert(is_string($second));

            return $first . $second;
        });
    }

    public function testCancelledSenderIsSplicedSoTheSurvivingSenderDeliversNext(): void
    {
        $scheduler = $this->scheduler;
        $received = null;

        $scheduler->run(function () use ($scheduler, &$received): string {
            $channel = new FiberChannel($scheduler, 1);
            $channel->send('v1');

            $first = $scheduler->spawn(static fn () => $channel->send('cancelled-value'), 'sender-1');
            $scheduler->suspend();
            $second = $scheduler->spawn(static fn () => $channel->send('v3'), 'sender-2');
            $scheduler->suspend();

            self::assertTrue($first->cancel());

            try {
                $scheduler->await($first);
            } catch (TaskCancelledException) {
                // expected
            }

            $receiver = $scheduler->spawn(fn (): array => [$channel->receive(), $channel->receive()], 'receiver');
            $scheduler->await($second);
            $received = $scheduler->await($receiver);

            return 'ok';
        });

        self::assertSame(['v1', 'v3'], $received, 'the cancelled sender entry must not resurrect its value');
    }

    public function testSuspensionHandleDoubleSettlesAreIgnoredInsideCoroutines(): void
    {
        $scheduler = $this->scheduler;

        $scheduler->run(function () use ($scheduler): string {
            $handle = $scheduler->beginSuspension('double-settle probe');
            $handle->deliver('first');
            $handle->deliver('second');
            $value = $scheduler->awaitSuspension($handle);
            assert(is_string($value));

            return $value;
        });

        $code = $scheduler->run(function () use ($scheduler): string {
            $handle = $scheduler->beginSuspension('fail-then-deliver probe');
            $handle->fail(new TaskCancelledException('cancelled first'));

            try {
                $scheduler->awaitSuspension($handle);
            } catch (TaskCancelledException) {
                // expected
            }
            // A late deliver after the settled failure must be ignored,
            // not queued as a second fiber step.
            $handle->deliver('late');

            return 'ok';
        });

        self::assertSame(0, $code, 'a late deliver must not queue a second fiber step');
    }

    public function testFiberTaskFinishingTwiceDrainsNoStaleCallbacks(): void
    {
        $task = new FiberTask($this->scheduler, 97, 'twice', static fn (): int => 1);
        $task->finish(TaskState::Succeeded, 1, null);

        $fired = [];
        $task->addCompletionCallback(static function () use (&$fired): void {
            $fired[] = 'immediate';
        });
        self::assertSame(['immediate'], $fired, 'callbacks on a settled task fire immediately');

        $drained = $task->finish(TaskState::Cancelled, null, new TaskCancelledException('redo'));
        self::assertSame([], $drained, 'the immediate branch must not re-queue the callback');
    }

    public function testSuspensionHandleSpuriousWakesCannotHijackALaterAwait(): void
    {
        $scheduler = $this->scheduler;

        // A second deliver after settle must NOT queue a fiber step: a stale
        // step would resume the coroutine while it awaits something else and
        // corrupt the suspension protocol.
        $scheduler->run(function () use ($scheduler): string {
            $slow = $scheduler->spawn(function () use ($scheduler): string {
                $scheduler->sleep(0.01);

                return 'done';
            }, 'slow');
            $handle = $scheduler->beginSuspension('double-deliver probe');
            $handle->deliver('first');
            $handle->deliver('second');
            $first = $scheduler->awaitSuspension($handle);
            assert(is_string($first));

            $shared = $scheduler->await($slow);
            assert(is_string($shared));

            return $shared;
        });

        // Same protocol guarantee for a fail-after-deliver race.
        $code = $scheduler->run(function () use ($scheduler): string {
            $slow = $scheduler->spawn(function () use ($scheduler): string {
                $scheduler->sleep(0.01);

                return 'done';
            }, 'slow');
            $handle = $scheduler->beginSuspension('fail-late probe');
            $handle->deliver('first');
            $handle->fail(new TaskCancelledException('late failure'));
            $first = $scheduler->awaitSuspension($handle);
            assert(is_string($first));

            $shared = $scheduler->await($slow);
            assert(is_string($shared));

            return $shared;
        });

        // Same protocol guarantee for a deliver-after-fail race.
        $code = $scheduler->run(function () use ($scheduler): string {
            $slow = $scheduler->spawn(function () use ($scheduler): string {
                $scheduler->sleep(0.01);

                return 'done';
            }, 'slow');
            $handle = $scheduler->beginSuspension('deliver-late probe');
            $handle->fail(new TaskCancelledException('early failure'));

            try {
                $scheduler->awaitSuspension($handle);
            } catch (TaskCancelledException) {
                // expected
            }
            $handle->deliver('late');

            $shared = $scheduler->await($slow);
            assert(is_string($shared));

            return $shared;
        });

        self::assertSame(0, $code);
    }

    // ------------------------------------------------------------------
    // Clock + misc
    // ------------------------------------------------------------------

    public function testHrMonotonicClockNeverGoesBackwards(): void
    {
        $clock = new HrMonotonicClock();
        $first = $clock->nowNano();
        $second = $clock->nowNano();

        self::assertGreaterThanOrEqual($first, $second);
        self::assertInstanceOf(MonotonicClockInterface::class, $clock);
    }

    public function testSuspensionHandleSettlesAtMostOnceAndFiresHooks(): void
    {
        $hooks = [];
        $handle = new SuspensionHandle($this->scheduler, $this->createStubTask());
        $handle->onSettle(function () use (&$hooks): void {
            $hooks[] = 'pre';
        });

        self::assertFalse($handle->isSettled());
        $handle->deliver('value');
        self::assertTrue($handle->isSettled());

        $handle->onSettle(function () use (&$hooks): void {
            $hooks[] = 'post';
        });
        $handle->deliver('ignored');
        $handle->fail(new TaskCancelledException('ignored'));

        self::assertSame(['pre', 'post'], $hooks);
        self::assertSame('pre', $hooks[0], 'settle hooks run at most once each');
    }

    public function testTimeoutExceptionIsPartOfTheAsyncHierarchy(): void
    {
        $timeout = new AsyncTimeoutException('late');
        self::assertInstanceOf(AsyncException::class, $timeout);
        self::assertSame('late', $timeout->getMessage());
    }

    public function testTwoCoroutinesAwaitingOneTaskAreBothWoken(): void
    {
        $scheduler = $this->scheduler;
        $woke = [];

        $scheduler->run(function () use ($scheduler, &$woke): string {
            // The target must still be running while BOTH waiters park, so
            // two completion callbacks are registered before it settles.
            $target = $scheduler->spawn(function () use ($scheduler): string {
                $scheduler->sleep(0.005);

                return 'shared';
            }, 'shared');
            $a = $scheduler->spawn(static fn (): mixed => $scheduler->await($target), 'waiter-a');
            $b = $scheduler->spawn(static fn (): mixed => $scheduler->await($target), 'waiter-b');

            $woke[] = $scheduler->await($a);
            $woke[] = $scheduler->await($b);

            return 'ok';
        });

        self::assertSame(['shared', 'shared'], $woke, 'both completion callbacks must fire');
    }

    private function createStubTask(): FiberTask
    {
        return new FiberTask($this->scheduler, 1, 'stub', static fn (): null => null);
    }
}
