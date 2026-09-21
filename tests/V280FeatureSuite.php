<?php

declare(strict_types=1);

/*
 * ZEF Framework — v2.8.0 feature suite (dedicated test file).
 *
 * The 13 sub-suites covering the v2.8.0 roadmap features were moved
 * verbatim out of tests/CliRunner.php so they live in their own visible,
 * independently runnable suite:
 *
 *     bin/zef --self-test=v280     only this suite (~103 assertions)
 *     bin/zef --self-test          the full suite (v2.7.0 baseline + v2.8.0)
 *
 * Assertion counters stay centralised in CliRunner; this class only
 * orchestrates the v2.8.0 checks and delegates the assertions.
 */

namespace Zef\Test;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zef\App\Bootstrap;
use Zef\Framework\Cache\InMemoryCache;
use Zef\Framework\Cache\InMemoryCacheStore;
use Zef\Framework\Cache\InMemoryLockStore;
use Zef\Framework\Cache\TaggableCache;
use Zef\Framework\Cache\TieredCache;
use Zef\Framework\Container\Container;
use Zef\Framework\Container\ServiceDefinition;
use Zef\Framework\Container\ServiceLifetime;
use Zef\Framework\Container\TaggedServiceLocator;
use Zef\Framework\CQRS\InMemoryIdempotencyStore;
use Zef\Framework\Exception\InvalidConfigurationException;
use Zef\Framework\Exception\RouteConstraintException;
use Zef\Framework\Http\ETagMiddleware;
use Zef\Framework\Http\ProblemDetails;
use Zef\Framework\Http\Response;
use Zef\Framework\Http\ServerRequest;
use Zef\Framework\Http\Uri;
use Zef\Framework\Job\CronExpression;
use Zef\Framework\Job\FixedIntervalSchedule;
use Zef\Framework\Job\InMemoryJobQueue;
use Zef\Framework\Job\Scheduler;
use Zef\Framework\Message\DeduplicatingMiddleware;
use Zef\Framework\Message\MessageContext;
use Zef\Framework\Message\MessageEnvelope;
use Zef\Framework\Message\MessageResult;
use Zef\Framework\MiddlewarePipeline;
use Zef\Framework\Observability\CounterMeter;
use Zef\Framework\Observability\HealthAggregator;
use Zef\Framework\Observability\HealthCheckResult;
use Zef\Framework\Observability\HealthIndicatorInterface;
use Zef\Framework\Observability\PrometheusRenderer;
use Zef\Framework\Resource\Cursor;
use Zef\Framework\Resource\PageRequest;
use Zef\Framework\Resource\PageSlice;
use Zef\Framework\Router\Router;
use Zef\Framework\Router\UrlGenerator;
use Zef\Framework\Security\AesGcmEncryptor;
use Zef\Framework\Security\Base32;
use Zef\Framework\Security\Totp;
use Zef\Framework\Validation\FieldRules;
use Zef\Framework\Validation\Validator;

final class V280FeatureSuite
{
    public function __construct(private readonly CliRunner $runner) {}

    /** Entry point invoked by CliRunner::run(). */
    public function run(): void
    {
        $this->testV280Features();
    }

    // ------------------------------------------------------------------
    // v2.8.0 feature suite — roadmap continuation (additive features).
    // ------------------------------------------------------------------

    private function testV280Features(): void
    {
        $this->v280ContainerTags();
        $this->v280RouteNaming();
        $this->v280Etag();
        $this->v280ProblemDetails();
        $this->v280Pagination();
        $this->v280Prometheus();
        $this->v280Health();
        $this->v280Cache();
        $this->v280Scheduler();
        $this->v280Security();
        $this->v280Validation();
        $this->v280MessageDedup();
        $this->v280HttpSurface();
    }

    private function v280ContainerTags(): void
    {
        $c = new Container();
        $c->registerDefinition(new ServiceDefinition(
            'svc.one',
            static fn (): \stdClass => new \stdClass(),
            [],
            'm',
            ServiceLifetime::SINGLETON,
            tags: ['group.a'],
        ));
        $c->registerDefinition(new ServiceDefinition(
            'svc.two',
            static fn (): \stdClass => new \stdClass(),
            [],
            'm',
            ServiceLifetime::SINGLETON,
            tags: ['group.a', 'group.b'],
        ));
        $c->registerDefinition(new ServiceDefinition(
            'svc.plain',
            static fn (): \stdClass => new \stdClass(),
            [],
            'm',
            ServiceLifetime::SINGLETON,
        ));
        $c->validateAndFreeze();
        $locator = new TaggedServiceLocator($c, $c->getRegistry());
        $this->ok($locator->idsFor('group.a') === ['svc.one', 'svc.two'], 'v280: tag index follows registration order');
        $this->ok($locator->hasTag('group.b') && !$locator->hasTag('group.nope'), 'v280: tag presence query');
        $this->ok(count($locator->resolveAll('group.a')) === 2, 'v280: resolveAll resolves tagged services');
        $this->ok($locator->resolveOne('group.b') instanceof \stdClass, 'v280: resolveOne returns the single service');
        $this->ok($locator->resolveOne('group.nope') === null, 'v280: resolveOne on unused tag returns null');
        $this->throws(InvalidConfigurationException::class, fn (): mixed => $locator->resolveOne('group.a'), 'v280: resolveOne on ambiguous tag throws');
        $this->throws(\InvalidArgumentException::class, fn (): array => $locator->idsFor('bad tag!'), 'v280: invalid tag charset rejected');

        $app = Bootstrap::createApp(false);
        $app->boot();

        /** @var TaggedServiceLocator $appLocator */
        $appLocator = $app->getContainer()->get(TaggedServiceLocator::class);
        $this->ok($appLocator->idsFor('health.indicator') === ['health.indicator.container'], 'v280: app container wires tagged locator with module tags');
    }

    private function v280RouteNaming(): void
    {
        $router = new Router();
        $router->add('GET', '/a/{id:int}', 'h.one', 'm', 0, 'a.detail');
        $router->add('GET', '/a', 'h.index', 'm', 0, 'a.index');
        $router->add('GET', '/c/{name}', 'h.name', 'm', 0, 'c.item');
        $router->freeze();
        $this->ok($router->patternFor('a.detail') === '/a/{id:int}', 'v280: patternFor reverse lookup');
        $this->ok($router->hasRouteName('a.index') && $router->routeNames()['a.index'] === '/a', 'v280: route name map exposed');
        $routerDup = new Router();
        $routerDup->add('GET', '/x', 'h', 'm', 0, 'dup.name');
        $this->throws(\InvalidArgumentException::class, fn () => $routerDup->add('GET', '/y', 'h', 'm', 0, 'dup.name'), 'v280: duplicate route name rejected');

        $generator = new UrlGenerator($router);
        $this->ok($generator->generate('a.index') === '/a', 'v280: URL generation for static route');
        $this->ok($generator->generate('a.detail', ['id' => 42]) === '/a/42', 'v280: URL generation substitutes params');
        $this->ok($generator->generate('c.item', ['name' => 'a b/']) === '/c/a%20b%2F', 'v280: URL generation rawencodes unsafe chars');
        $this->throws(\InvalidArgumentException::class, fn (): string => $generator->generate('a.detail'), 'v280: missing param throws');
        $this->throws(\InvalidArgumentException::class, fn (): string => $generator->generate('a.detail', ['id' => 1, 'extra' => 2]), 'v280: extra param throws');
        $this->throws(RouteConstraintException::class, fn (): string => $generator->generate('a.detail', ['id' => 'abc']), 'v280: constraint-violating param throws');

        $app = Bootstrap::createApp(false);
        $app->boot();
        $this->ok($app->getRouter()->hasRouteName('health.metrics'), 'v280: module route config carries names into router');
    }

    private function v280Etag(): void
    {
        $mw = new ETagMiddleware();
        $inner = new class implements RequestHandlerInterface {
            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, ['Content-Type' => 'text/plain'], 'stable-body');
            }
        };
        $direct = new MiddlewarePipeline([$mw], $inner);
        $r1 = $direct->handle(new ServerRequest('GET', new Uri('http://localhost/anything', ['localhost'])));
        $etag = $r1->getHeaderLine('ETag');
        $this->ok($etag !== '' && str_starts_with($etag, '"') && str_ends_with($etag, '"'), 'v280: 200 GET gets strong ETag');
        $conditional = new ServerRequest('GET', new Uri('http://localhost/anything', ['localhost']))
            ->withHeader('If-None-Match', $etag)
        ;
        $r2 = $direct->handle($conditional);
        $this->ok($r2->getStatusCode() === 304 && $r2->bodyString() === '' && $r2->getHeaderLine('ETag') === $etag, 'v280: If-None-Match match yields body-less 304');
        $weak = new ServerRequest('GET', new Uri('http://localhost/anything', ['localhost']))
            ->withHeader('If-None-Match', 'W/' . $etag . ', "other"')
        ;
        $this->ok($direct->handle($weak)->getStatusCode() === 304, 'v280: weak W/ ETag and lists still match');
        $lmFormat = 'D, d M Y H:i:s';
        $stamp = gmdate($lmFormat, 1700000000) . ' GMT';
        $lmHandler = new readonly class($stamp) implements RequestHandlerInterface {
            public function __construct(private string $lastModified) {}

            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, ['Last-Modified' => $this->lastModified], '');
            }
        };
        $lmPipeline = new MiddlewarePipeline([$mw], $lmHandler);
        $ims = new ServerRequest('GET', new Uri('http://localhost/x', ['localhost']))
            ->withHeader('If-Modified-Since', gmdate($lmFormat, 1700000001) . ' GMT')
        ;
        $this->ok($lmPipeline->handle($ims)->getStatusCode() === 304, 'v280: If-Modified-Since newer than Last-Modified yields 304');
        $imsOld = new ServerRequest('GET', new Uri('http://localhost/x', ['localhost']))
            ->withHeader('If-Modified-Since', gmdate($lmFormat, 1600000000) . ' GMT')
        ;
        $this->ok($lmPipeline->handle($imsOld)->getStatusCode() === 200, 'v280: If-Modified-Since older keeps 200');
        $post = (new ServerRequest('POST', new Uri('http://localhost/x', ['localhost'])));
        $this->ok($lmPipeline->handle($post)->getStatusCode() === 200, 'v280: conditional logic bypassed for POST');
        $this->ok(ETagMiddleware::ifNoneMatchMatches('*', '"abc"'), 'v280: If-None-Match wildcard matches');
    }

    private function v280ProblemDetails(): void
    {
        $problem = ProblemDetails::fromStatus(404, 'Widget not found.', '/widgets/9', ['widget_id' => 9]);
        $response = $problem->toResponse();
        $this->ok($response->getStatusCode() === 404, 'v280: problem details keeps status');
        $this->ok($response->getHeaderLine('Content-Type') === 'application/problem+json', 'v280: application/problem+json content type');
        $decoded = json_decode($response->bodyString(), true, 512, JSON_THROW_ON_ERROR);
        $this->ok(
            $decoded['type'] === 'about:blank' && $decoded['title'] === 'Not Found'
            && $decoded['status'] === 404 && $decoded['detail'] === 'Widget not found.'
            && $decoded['instance'] === '/widgets/9' && $decoded['widget_id'] === 9,
            'v280: RFC 9457 members + extensions serialized',
        );
        $this->throws(\InvalidArgumentException::class, fn (): ProblemDetails => new ProblemDetails(200, ''), 'v280: empty title rejected');
        $this->throws(\InvalidArgumentException::class, fn (): ProblemDetails => new ProblemDetails(404, 'x', 'about:blank', '', null, ['bad name!' => 1]), 'v280: invalid extension name rejected');
    }

    private function v280Pagination(): void
    {
        $pr = PageRequest::fromQuery(['page' => '3', 'per_page' => '10']);
        $this->ok($pr->offset === 20 && $pr->limit === 10, 'v280: page/per_page query normalization');
        $pr2 = PageRequest::fromQuery(['offset' => '5', 'limit' => '99999'], 20);
        $this->ok($pr2->limit === PageRequest::MAX_LIMIT, 'v280: limit hard-capped at 100');
        $pr3 = PageRequest::fromQuery(['limit' => 'bogus'], 25);
        $this->ok($pr3->limit === 25 && $pr3->offset === 0, 'v280: invalid values fall back to defaults');
        $this->throws(\InvalidArgumentException::class, fn (): PageRequest => PageRequest::limit(0), 'v280: zero limit rejected on direct construction');

        $slice = new PageSlice(['a', 'b', 'c'], 0, 3, 10);
        $this->ok($slice->hasNext() && $slice->nextOffset() === 3 && !$slice->hasPrevious(), 'v280: slice navigation meta');
        $meta = $slice->meta();
        $this->ok($meta === ['offset' => 0, 'limit' => 3, 'count' => 3, 'total' => 10, 'next_offset' => 3], 'v280: meta payload shape');

        $cursor = new Cursor(42)->encode('salt');
        $this->ok(Cursor::decode($cursor, 'salt')->offset === 42, 'v280: cursor round trip');
        $this->throws(\InvalidArgumentException::class, fn (): Cursor => Cursor::decode($cursor, 'other-salt'), 'v280: cursor tampered salt rejected');
        $tampered = new Cursor(43)->encode('salt');
        $this->ok($tampered !== $cursor, 'v280: distinct offsets produce distinct cursors');
        $this->throws(\InvalidArgumentException::class, fn (): Cursor => Cursor::decode('garbage!!', 'salt'), 'v280: malformed cursor rejected');
    }

    private function v280Prometheus(): void
    {
        $meter = new CounterMeter();
        $meter->increment('zef.demo.requests.total', 3, ['http.request.method' => 'GET']);
        $meter->observe('zef.demo.duration', 0.25, ['http.request.method' => 'GET']);
        $renderer = new PrometheusRenderer();
        $text = $renderer->render($meter, ['app' => 'zef']);
        $this->ok(str_contains($text, '# TYPE zef_demo_requests_total counter'), 'v280: counter TYPE line emitted');
        $this->ok(str_contains($text, 'zef_demo_requests_total{app="zef",http_request_method="GET"} 3.0'), 'v280: counter series with sanitized labels');
        $this->ok(str_contains($text, 'zef_demo_duration_sum') && str_contains($text, 'zef_demo_duration_count'), 'v280: observation series exposes _sum/_count');
        $malicious = new CounterMeter();
        $malicious->increment('x', 1, ['lbl"quote' => 'val\ue']);
        $out2 = $renderer->render($malicious);
        $this->ok(str_contains($out2, 'lbl_quote="val\\\ue"'), 'v280: label values escaped per exposition format');
        $empty = $renderer->render(new CounterMeter());
        $this->ok($empty === '', 'v280: empty snapshot renders empty document');
    }

    private function v280Health(): void
    {
        $up = new class implements HealthIndicatorInterface {
            #[\Override]
            public function name(): string
            {
                return 'demo-cache';
            }

            #[\Override]
            public function check(): HealthCheckResult
            {
                return HealthCheckResult::up();
            }
        };
        $down = new class implements HealthIndicatorInterface {
            #[\Override]
            public function name(): string
            {
                return 'demo-queue';
            }

            #[\Override]
            public function check(): HealthCheckResult
            {
                return HealthCheckResult::down('broker unreachable');
            }
        };
        $crashy = new class implements HealthIndicatorInterface {
            #[\Override]
            public function name(): string
            {
                return 'demo-db';
            }

            #[\Override]
            public function check(): HealthCheckResult
            {
                throw new \RuntimeException('boom');
            }
        };
        $ok = new HealthAggregator([$up]);
        $this->ok($ok->aggregate()['status'] === 'ok' && $ok->aggregate()['checks'][0]['status'] === 'up', 'v280: aggregator ok with healthy indicator');
        $mixed = new HealthAggregator([$up, $down, $crashy]);
        $summary = $mixed->aggregate();
        $this->ok($summary['status'] === 'degraded', 'v280: any failing indicator degrades aggregate');
        $byName = [];
        foreach ($summary['checks'] as $check) {
            $byName[$check['name']] = $check;
        }
        $this->ok($byName['demo-queue']['status'] === 'down' && $byName['demo-queue']['message'] === 'broker unreachable', 'v280: failure message carried through');
        $this->ok($byName['demo-db']['status'] === 'down' && str_contains($byName['demo-db']['message'], 'probe failure'), 'v280: crashing probe contained, not fatal');
    }

    private function v280Cache(): void
    {
        $now = 1_700_000_000;
        $clock = static function () use (&$now): int {
            return $now;
        };
        $locks = new InMemoryLockStore($clock);
        $this->ok($locks->acquire('res:build', 'owner-1', 30), 'v280: lock acquire succeeds');
        $this->ok(!$locks->acquire('res:build', 'owner-2', 30), 'v280: second owner blocked while leased');
        $this->ok($locks->acquire('res:build', 'owner-1', 30), 'v280: re-entrant acquire by same owner');
        $this->ok(!$locks->release('res:build', 'owner-2'), 'v280: foreign owner cannot release');
        $this->ok($locks->release('res:build', 'owner-1') && $locks->holder('res:build') === null, 'v280: owner release frees lock');
        $now += 31; // advance the shared test clock past the 30s lease
        $this->ok($locks->holder('k2') === null, 'v280: untouched key stays free');
        $locks->acquire('k3', 'a', 30);
        $now += 31;
        $this->ok($locks->holder('k3') === null && $locks->acquire('k3', 'b', 30), 'v280: expired lease is reclaimable');
        $locks->acquire('k4', 'a', 30);
        $now += 29;
        $this->ok($locks->refresh('k4', 'a', 30), 'v280: lease refresh by owner');
        $now += 29; // refreshed lease (at t+91) now expires at t+121 — still held at t+120
        $this->ok($locks->holder('k4') === 'a', 'v280: refresh extends the lease window');
        $this->throws(\InvalidArgumentException::class, fn (): bool => $locks->acquire('k', 'o', 0), 'v280: TTL bounds enforced');

        $inner = new InMemoryCache(new InMemoryCacheStore());
        $tagged = new TaggableCache($inner);
        $tagged->setWithTags('prod:1', 'chair', null, ['products', 'shop-1']);
        $tagged->setWithTags('prod:2', 'desk', null, ['products']);
        $this->ok($tagged->get('prod:1') === 'chair' && $tagged->tagsFor('prod:1') === ['products', 'shop-1'], 'v280: tagged set + reverse tag lookup');
        $deleted = $tagged->invalidateTag('products');
        $this->ok($deleted === 2 && !$tagged->has('prod:1') && !$tagged->has('prod:2'), 'v280: tag invalidation deletes members');
        $this->ok($tagged->invalidateTag('products') === 0, 'v280: re-invalidation is idempotent');
        $this->throws(\InvalidArgumentException::class, fn () => $tagged->setWithTags('k', 1, null, ['bad tag!']), 'v280: invalid tag charset rejected');
        $this->throws(\InvalidArgumentException::class, fn () => $tagged->set("\0zef-tag:spoof", 'x'), 'v280: reserved key prefix rejected');

        $l1 = new InMemoryCache(new InMemoryCacheStore());
        $l2 = new InMemoryCache(new InMemoryCacheStore());
        $tiered = new TieredCache($l1, $l2, 60);
        $tiered->set('warm', 'v', 3600);
        $this->ok($l1->has('warm') && $l2->has('warm'), 'v280: tiered write-through hits both tiers');
        $l1->delete('warm');
        $this->ok($tiered->get('warm') === 'v' && $l1->has('warm'), 'v280: L2 miss promotes back into L1');
        $tiered->delete('warm');
        $this->ok(!$tiered->has('warm') && !$l1->has('warm') && !$l2->has('warm'), 'v280: delete propagates to both tiers');
    }

    private function v280Scheduler(): void
    {
        $fixed = new FixedIntervalSchedule(600);
        $base = 1_800_000_000 * 1_000_000_000;
        $this->ok($fixed->nextRunAfter($base) === ($base + 600 * 1_000_000_000), 'v280: fixed interval is epoch aligned');

        $cron = CronExpression::parse('*/15 * * * *');
        $dailyMidnight = gmmktime(0, 0, 0, 9, 20, 2026) * 1_000_000_000;
        $this->ok($cron->nextRunAfter($dailyMidnight) === $dailyMidnight + 900 * 1_000_000_000, 'v280: cron steps aligned to wall clock');
        $this->throws(\InvalidArgumentException::class, fn (): CronExpression => CronExpression::parse('60 * * * *'), 'v280: out-of-range cron minute rejected');
        $this->throws(\InvalidArgumentException::class, fn (): CronExpression => CronExpression::parse('0 0 * *'), 'v280: wrong field count rejected');
        $feb29 = CronExpression::parse('30 2 29 2 *');
        $this->ok($feb29->describe() === '30 2 29 2 * (UTC)', 'v280: describe returns expression');

        $queue = new InMemoryJobQueue();
        $scheduler = new Scheduler($queue);
        $scheduler->register('demo.tick', ['n' => 1], new FixedIntervalSchedule(60));
        // Lazy anchor: first tick binds the cursor to the next epoch-aligned
        // boundary, then fires everything due up to (and including) the horizon.
        $this->ok($scheduler->tick($base + 60 * 1_000_000_000) === 1 && $queue->size() === 1, 'v280: first tick anchors schedule at next boundary');
        $this->ok($scheduler->tick($base + 180 * 1_000_000_000) === 2, 'v280: subsequent ticks catch up due fires');
        $this->ok($scheduler->nextRunOf('demo.tick') === $base + 240 * 1_000_000_000, 'v280: cursor advances past tick horizon');
        $this->ok($scheduler->tick($base + 181 * 1_000_000_000) === 0, 'v280: idempotent tick produces nothing new');
        $this->ok($queue->size() === 3, 'v280: queue holds every scheduled fire');
        $scheduler->unregister('demo.tick');
        $this->ok($scheduler->jobTypes() === [], 'v280: unregister removes schedule');
    }

    private function v280Security(): void
    {
        $key = random_bytes(32);
        $encryptor = new AesGcmEncryptor($key);
        $cipher = $encryptor->encrypt('secret-payload');
        $this->ok($encryptor->decrypt($cipher) === 'secret-payload', 'v280: AES-256-GCM round trip');
        $this->ok($encryptor->encrypt('secret-payload') !== $cipher, 'v280: random IV yields unique ciphertexts');
        $tampered = substr($cipher, 0, -3) . ($cipher[-3] === 'A' ? 'B' : 'A') . substr($cipher, -2);
        $this->throws(\RuntimeException::class, fn (): string => $encryptor->decrypt($tampered), 'v280: tampered ciphertext rejected');
        $other = new AesGcmEncryptor(random_bytes(32));
        $this->throws(\RuntimeException::class, fn (): string => $other->decrypt($cipher), 'v280: wrong key rejected');
        $this->throws(\InvalidArgumentException::class, fn (): AesGcmEncryptor => new AesGcmEncryptor('short-key'), 'v280: weak key length rejected');
        $hexKey = bin2hex(random_bytes(32));
        $hexEncryptor = new AesGcmEncryptor($hexKey);
        $this->ok($hexEncryptor->decrypt($hexEncryptor->encrypt('x')) === 'x', 'v280: hex-encoded keys accepted');

        $b32 = Base32::encode("corr\u{e9}ct!");
        $this->ok(Base32::decode(strtolower($b32) . '===') === "corr\u{e9}ct!", 'v280: base32 round trip tolerates case/padding');
        $this->throws(\InvalidArgumentException::class, fn (): string => Base32::decode('1!!!!'), 'v280: invalid base32 alphabet rejected');

        $totp = new Totp(30, 8, 'sha1');
        $secret = '12345678901234567890';
        $vectors = [
            [59, '94287082'], [1111111109, '07081804'], [1234567896, '89005924'],
            [2000000000, '69279037'], [20000000000, '65353130'],
        ];
        $allMatch = true;
        foreach ($vectors as [$time, $code]) {
            $allMatch = $allMatch && ($totp->at($secret, $time) === $code);
        }
        $this->ok($allMatch, 'v280: RFC 6238 Appendix B vectors (SHA-1, 8 digits)');
        $code = $totp->at($secret, 1111111109);
        $this->ok($totp->verify($secret, $code, 1111111109 + 15, 1), 'v280: TOTP verification tolerates clock drift');
        $this->ok(!$totp->verify($secret, $code, 1111111109 + 120, 1), 'v280: TOTP rejects codes beyond window');
        $this->ok(!$totp->verify($secret, 'abcdefgh', 1111111109), 'v280: non-numeric codes fail closed');
        $this->throws(\InvalidArgumentException::class, fn (): Totp => new Totp(30, 5), 'v280: digit count bounds enforced');
    }

    private function v280Validation(): void
    {
        $validator = new Validator();
        $validator->field('email')->required()->email()->maxLength(254);
        $validator->field('age')->typeInt()->min(0)->max(130)->nullable();
        $validator->field('slug')->pattern('/^[a-z0-9-]{1,32}$/');
        $validator->field('role')->required()->in(['admin', 'editor']);

        $good = $validator->validate(['email' => 'user@example.com', 'age' => '33', 'slug' => 'ok-1', 'role' => 'admin']);
        $this->ok($good->ok() && $good->errors === [], 'v280: valid payload passes');

        $bad = $validator->validate(['email' => 'nope', 'age' => 999, 'slug' => 'NOT OK', 'role' => 'ghost']);
        $this->ok(count($bad->errors) === 4, 'v280: one failure per violated rule');
        $this->ok(count($bad->errorsFor('email')) === 1 && $bad->firstMessage() !== null, 'v280: per-field error lookup');
        $this->ok(in_array('email', $validator->fieldNames(), true), 'v280: declared fields listed');
        $nullAge = $validator->validate(['email' => 'a@b.co', 'age' => null, 'slug' => 'x', 'role' => 'admin']);
        $this->ok($nullAge->ok(), 'v280: nullable fields skip chain for null');

        // ReDoS policy: the oversized subject is rejected by the length guard
        // BEFORE the catastrophic regex ever executes (fails as a rule violation,
        // never as a hang).
        $rules = new FieldRules('x');
        $failures = $rules->pattern('/^([a-z]+)+$/D')->validate(str_repeat('a', 5000));
        $this->ok(count($failures) === 1, 'v280: ReDoS-guarded pattern rejects oversized subject without executing');
        $this->throws(\InvalidArgumentException::class, fn (): FieldRules => new FieldRules('y')->pattern('/' . str_repeat('a', 3000) . '/'), 'v280: oversized pattern rejected at registration');
    }

    private function v280MessageDedup(): void
    {
        $store = new InMemoryIdempotencyStore();
        $handlerCalls = 0;
        $mw = new DeduplicatingMiddleware($store, 600);
        $envelope = new MessageEnvelope('msg-123456789', 'demo.event', ['n' => 1]);
        $context = new MessageContext();
        $next = function (MessageEnvelope $m, MessageContext $c) use (&$handlerCalls): MessageResult {
            ++$handlerCalls;

            return new MessageResult($m->messageId, true);
        };
        $r1 = $mw->process($envelope, $context, $next);
        $r2 = $mw->process($envelope, $context, $next);
        $this->ok($handlerCalls === 1, 'v280: duplicate delivery bypasses handler chain');
        $this->ok($r1->accepted && $r2->messageId === $r1->messageId && $r2 === $r1, 'v280: cached result replayed for duplicates');
    }

    private function v280HttpSurface(): void
    {
        // The two new HTTP surfaces of the demo app must behave correctly
        // while the original 11 routes stay byte-identical (checked elsewhere).
        $app = Bootstrap::createApp(false);
        $app->boot();
        $aggregate = $app->handle(new ServerRequest('GET', new Uri('http://localhost/health', ['localhost'])));
        $decoded = json_decode($aggregate->bodyString(), true, 512, JSON_THROW_ON_ERROR);
        $this->ok($aggregate->getStatusCode() === 200 && $decoded['status'] === 'ok' && $decoded['checks'][0]['name'] === 'container', 'v280: /health aggregate reflects tagged indicators');
        $metrics = $app->handle(new ServerRequest('GET', new Uri('http://localhost/metrics', ['localhost'])));
        $this->ok($metrics->getStatusCode() === 200 && str_contains($metrics->bodyString(), '# TYPE'), 'v280: /metrics emits Prometheus exposition');
        $named = new UrlGenerator($app->getRouter())->generate('health.metrics');
        $this->ok($named === '/metrics', 'v280: named route generates demo URL');
    }

    private function ok(bool $cond, string $label): void
    {
        $this->runner->ok($cond, $label);
    }

    private function throws(string $class, callable $fn, string $label): void
    {
        $this->runner->throws($class, $fn, $label);
    }
}
