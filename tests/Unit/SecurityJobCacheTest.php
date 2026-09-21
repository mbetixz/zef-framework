<?php

declare(strict_types=1);

/*
 * ZEF Framework — Native PHPUnit coverage for security, job-queue, cache
 * and admission-control infrastructure.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Cache\InMemoryCache;
use Zef\Framework\Cache\InMemoryCacheStore;
use Zef\Framework\Cache\TaggableCache;
use Zef\Framework\Job\CronExpression;
use Zef\Framework\Job\InMemoryJobQueue;
use Zef\Framework\Job\InProcessJobWorker;
use Zef\Framework\Job\JobContext;
use Zef\Framework\Job\JobEnvelope;
use Zef\Framework\Job\RetryPolicy;
use Zef\Framework\Resource\InMemoryAdmissionController;
use Zef\Framework\Resource\ResourceBudget;
use Zef\Framework\Security\AesGcmEncryptor;
use Zef\Framework\Security\ApcuRateLimiter;
use Zef\Framework\Security\InMemoryRateLimiter;
use Zef\Framework\Security\SecurityPolicy;

/**
 * @internal
 */
final class SecurityJobCacheTest extends TestCase
{
    // ------------------------------------------------------------------
    // Encryption (AES-256-GCM)
    // ------------------------------------------------------------------

    public function testAesGcmEncryptorRoundTripAndTamperDetection(): void
    {
        $key = base64_encode(random_bytes(32));
        $encryptor = new AesGcmEncryptor($key);

        $cipher = $encryptor->encrypt('hunter2-secret');
        self::assertNotSame('hunter2-secret', $cipher);
        self::assertSame('hunter2-secret', $encryptor->decrypt($cipher));

        // Flip a byte in the middle (inside the authenticated payload) —
        // GCM must detect the tamper and refuse to decrypt.
        $middle = (int) (strlen($cipher) / 2);
        $tampered = $cipher;
        $tampered[$middle] = $tampered[$middle] === 'A' ? 'B' : 'A';

        $this->expectException(\Throwable::class);
        $encryptor->decrypt($tampered);
    }

    public function testAesGcmEncryptorRejectsShortKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AesGcmEncryptor(base64_encode('too-short'));
    }

    // ------------------------------------------------------------------
    // Rate limiters
    // ------------------------------------------------------------------

    public function testInMemoryRateLimiterWindowsAndKeyCap(): void
    {
        $limiter = new InMemoryRateLimiter();
        for ($i = 0; $i < 3; ++$i) {
            $decision = $limiter->check('ip:1', 3, 60);
            self::assertTrue($decision->allowed);
        }
        $blocked = $limiter->check('ip:1', 3, 60);
        self::assertFalse($blocked->allowed);
        self::assertTrue($limiter->check('ip:2', 3, 60)->allowed);
    }

    public function testApcuRateLimiterRequiresExtension(): void
    {
        // The runtime refuses to silently degrade when APCu is absent:
        // the limiter is fail-closed by construction.
        if (\extension_loaded('apcu') || \extension_loaded('apcu-bc')) {
            self::markTestSkipped('apcu is loaded; the guard path cannot be exercised');
        }
        $this->expectException(\RuntimeException::class);
        new ApcuRateLimiter();
    }

    public function testSecurityPolicyValidatesCsrfConfiguration(): void
    {
        $policy = new SecurityPolicy(csrfEnabled: false, csrfTokenBytes: 16);
        self::assertFalse($policy->rateLimitEnabled);
        self::assertSame(16, $policy->csrfTokenBytes);

        $this->expectException(\InvalidArgumentException::class);
        new SecurityPolicy(csrfTokenBytes: 8);
    }

    // ------------------------------------------------------------------
    // Jobs: CronExpression, JobContext, worker, queues, retry
    // ------------------------------------------------------------------

    public function testCronExpressionParseNextRunAndDescribe(): void
    {
        $cron = CronExpression::parse('*/5 * * * *');
        $base = strtotime('2026-01-01T00:00:00+00:00');
        $next = $cron->nextRunAfter($base * 1_000_000_000);
        self::assertSame(($base + 300) * 1_000_000_000, $next);
        self::assertTrue($cron->matchesUtc($base + 300));
        // Matching is minute-granular: second 301 still falls in minute 5.
        self::assertTrue($cron->matchesUtc($base + 301));
        self::assertFalse($cron->matchesUtc($base + 60));
        self::assertNotSame('', $cron->describe());

        $this->expectException(\InvalidArgumentException::class);
        CronExpression::parse('not-a-cron');
    }

    public function testJobContextDeadlineCancellationAndTimeout(): void
    {
        $ctx = new JobContext('job-ctx-0001', 1, null, null, ['attempt' => 1]);
        self::assertFalse($ctx->isCancelled());
        self::assertNull($ctx->deadlineUnixNano());

        $timed = $ctx->withDeadlineMs(50);
        self::assertNotNull($timed->deadlineUnixNano());
        self::assertFalse($timed->isTimedOut());

        $expired = new JobContext('job-ctx-0002', 1, null, null, [], deadlineUnixNano: (int) ((microtime(true) - 10) * 1_000_000_000));
        self::assertTrue($expired->isTimedOut());
        $cancelled = $ctx->cancel();
        self::assertTrue($cancelled->isCancelled());
        $this->expectException(\Throwable::class);
        $cancelled->throwIfCancelled();
    }

    public function testInProcessJobWorkerProcessesRetriesAndFreezeGuards(): void
    {
        $queue = new InMemoryJobQueue();
        $worker = new InProcessJobWorker($queue, new RetryPolicy(maxAttempts: 3, initialDelayMs: 0), pollIntervalMs: 0);
        self::assertFalse($worker->isFrozen());

        $attempts = 0;
        $worker->register('flaky', static function (JobEnvelope $job, JobContext $context) use (&$attempts): string {
            ++$attempts;
            if ($attempts < 2) {
                throw new \RuntimeException('transient');
            }

            return 'recovered';
        });
        $queue->enqueue(new JobEnvelope('job-0000-0001', 'flaky', ['x' => 1], 0));

        $first = $worker->processOne();
        self::assertNotNull($first);
        self::assertFalse($first->completed);
        self::assertTrue($first->willRetry);

        $second = $worker->processOne();
        self::assertNotNull($second);
        self::assertTrue($second->completed);
        self::assertSame('recovered', $second->result);
        self::assertSame(2, $attempts);
        self::assertSame(2, $second->attempt);

        $worker->freeze();
        self::assertTrue($worker->isFrozen());

        $this->expectException(\LogicException::class);
        $worker->register('late', static fn (JobEnvelope $job, JobContext $context): string => 'nope');
    }

    public function testInProcessJobWorkerRejectsBadPollInterval(): void
    {
        $queue = new InMemoryJobQueue();
        $this->expectException(\InvalidArgumentException::class);
        new InProcessJobWorker($queue, pollIntervalMs: -1);
    }

    // ------------------------------------------------------------------
    // Cache: TaggableCache + InMemoryCacheStore
    // ------------------------------------------------------------------

    public function testTaggableCacheSetGetInvalidateAndClear(): void
    {
        $cache = new TaggableCache(new InMemoryCache(new InMemoryCacheStore()));

        $cache->setWithTags('product:1', ['name' => 'Mouse'], null, ['catalog', 'peripherals']);
        $cache->setWithTags('product:2', ['name' => 'Keyboard'], 60, ['catalog']);
        $cache->set('plain', 42);

        self::assertSame(['name' => 'Mouse'], $cache->get('product:1'));
        self::assertSame(42, $cache->get('plain'));
        self::assertTrue($cache->has('product:2'));
        self::assertSame(['catalog', 'peripherals'], $cache->tagsFor('product:1'));

        $removed = $cache->invalidateTag('catalog');
        self::assertSame(2, $removed);
        self::assertNull($cache->get('product:1'));
        self::assertNull($cache->get('product:2'));
        self::assertSame(42, $cache->get('plain'));

        $cache->delete('plain');
        self::assertFalse($cache->has('plain'));
        $cache->set('x', 1);
        $cache->clear();
        self::assertNull($cache->get('x'));
    }

    // ------------------------------------------------------------------
    // Admission control
    // ------------------------------------------------------------------

    public function testAdmissionControllerAdmitsRejectsQueuesAndSnapshots(): void
    {
        $controller = new InMemoryAdmissionController(new ResourceBudget(1, 1));

        $first = $controller->admit();
        self::assertTrue($first->admitted);
        self::assertFalse($controller->admit()->admitted);

        $queued = $controller->queue();
        self::assertTrue($queued->admitted);
        self::assertFalse($controller->queue()->admitted);

        $controller->complete();
        $controller->dequeue();
        $snapshot = $controller->snapshot();
        self::assertSame(1, $snapshot->completed);

        $this->expectException(\InvalidArgumentException::class);
        new ResourceBudget(0, 10);
    }
}
