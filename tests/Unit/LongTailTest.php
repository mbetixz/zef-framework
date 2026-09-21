<?php

declare(strict_types=1);

/*
 * ZEF Framework — Coverage push #5 (v2.13.1): long tail — origin & client
 * address policies, security policy invariants, constraint validator, event
 * dispatcher lifecycle, service/job envelope guards, config aggregator and
 * observability rendering.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Zef\Framework\Config\ConfigAggregator;
use Zef\Framework\Config\ConfigProviderInterface;
use Zef\Framework\Container\ServiceDefinition;
use Zef\Framework\Container\ServiceDefinition as ZefServiceDefinition;
use Zef\Framework\Event\EventContext;
use Zef\Framework\Event\EventDispatcher;
use Zef\Framework\Event\EventDispatchException;
use Zef\Framework\Exception\InvalidConfigurationException;
use Zef\Framework\Http\ServerRequest;
use Zef\Framework\Http\Uri;
use Zef\Framework\Job\JobEnvelope;
use Zef\Framework\Observability\PrometheusRenderer;
use Zef\Framework\Observability\Telemetry;
use Zef\Framework\Security\ClientAddressResolver;
use Zef\Framework\Security\OriginPolicy;
use Zef\Framework\Security\SecurityPolicy;
use Zef\Framework\Validation\RouteConstraintValidator;

/**
 * @internal
 */
final class LongTailTest extends TestCase
{
    // ------------------------------------------------------------------
    // OriginPolicy
    // ------------------------------------------------------------------

    public function testOriginPolicyNormalizesOrigins(): void
    {
        self::assertSame('null', OriginPolicy::normalizeOrigin('null'));
        self::assertSame('https://example.test', OriginPolicy::normalizeOrigin('HTTPS://Example.Test:443'));
        self::assertSame('http://example.test:8080', OriginPolicy::normalizeOrigin('http://example.test:8080'));
        self::assertSame('http://[::1]:9090', OriginPolicy::normalizeOrigin('http://[::1]:9090'));
        self::assertSame('http://[::1]', OriginPolicy::normalizeOrigin('http://[::1]:80'));
    }

    public function testOriginPolicyRejectsMalformedOrigins(): void
    {
        foreach ([
            "http://evil.test\r\nX: y",
            'ftp://example.test',
            'http://user:pass@example.test',
            'http://example.test?query=1',
            'http://example.test#frag',
            'http://example.test/path',
            'http://bad_host',
            'http://example.test:0',
            'not a url',
        ] as $bad) {
            try {
                OriginPolicy::normalizeOrigin($bad);
                self::fail("Expected rejection for '{$bad}'.");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testOriginPolicyAssertAllowed(): void
    {
        OriginPolicy::assertAllowed(null, ['https://example.test']);
        OriginPolicy::assertAllowed('', ['https://example.test']);
        OriginPolicy::assertAllowed('https://example.test', ['https://example.test']);
        $this->expectException(InvalidConfigurationException::class);
        OriginPolicy::assertAllowed('https://other.test', ['https://example.test']);
    }

    public function testClientAddressResolverPrefersRightmostUntrusted(): void
    {
        $untrusted = $this->requestWith(['REMOTE_ADDR' => '203.0.113.9']);
        self::assertSame('203.0.113.9', ClientAddressResolver::resolve($untrusted, ['10.0.0.1']));

        $proxied = $this->requestWith(['REMOTE_ADDR' => '10.0.0.1'], [
            'X-Forwarded-For' => '203.0.113.1, 10.0.0.2, 198.51.100.7',
        ]);
        self::assertSame('198.51.100.7', ClientAddressResolver::resolve($proxied, ['10.0.0.1', '10.0.0.2']));

        $allTrusted = $this->requestWith(['REMOTE_ADDR' => '10.0.0.1'], [
            'X-Forwarded-For' => '10.0.0.2, 10.0.0.3',
        ]);
        self::assertSame('10.0.0.1', ClientAddressResolver::resolve($allTrusted, ['10.0.0.1', '10.0.0.2', '10.0.0.3']));

        $invalidCandidate = $this->requestWith(['REMOTE_ADDR' => '10.0.0.1'], [
            // Rightmost candidate is malformed: parsing stops there (fail
            // closed) and the connection's own remote address wins.
            'X-Forwarded-For' => '203.0.113.5, not-an-ip',
        ]);
        self::assertSame('10.0.0.1', ClientAddressResolver::resolve($invalidCandidate, ['10.0.0.1']));

        $invalidRemote = $this->requestWith(['REMOTE_ADDR' => 'garbage']);
        self::assertSame('0.0.0.0', ClientAddressResolver::resolve($invalidRemote, []));

        $noRemote = $this->requestWith([]);
        self::assertSame('0.0.0.0', ClientAddressResolver::resolve($noRemote, []));
    }

    // ------------------------------------------------------------------
    // SecurityPolicy invariants
    // ------------------------------------------------------------------

    public function testSecurityPolicyRejectsInvalidConfiguration(): void
    {
        $cases = [
            static fn (): SecurityPolicy => new SecurityPolicy(rateLimitMaxRequests: 0),
            static fn (): SecurityPolicy => new SecurityPolicy(rateLimitWindowSeconds: 0),
            static fn (): SecurityPolicy => new SecurityPolicy(rateLimitMaxKeys: 0),
            static fn (): SecurityPolicy => new SecurityPolicy(csrfTokenBytes: 8),
            static fn (): SecurityPolicy => new SecurityPolicy(csrfEnabled: true, csrfSecret: 'short'),
            static fn (): SecurityPolicy => new SecurityPolicy(csrfCookieName: 'bad name!'),
            static fn (): SecurityPolicy => new SecurityPolicy(csrfHeaderName: 'bad header!'),
            static fn (): SecurityPolicy => new SecurityPolicy(csrfSameSite: 'Weird'),
            static fn (): SecurityPolicy => new SecurityPolicy(csrfSecureCookie: false, csrfSameSite: 'None'),
            static fn (): SecurityPolicy => new SecurityPolicy(allowedOrigins: ['no-scheme']),
        ];
        foreach ($cases as $index => $case) {
            try {
                $case();
                self::fail("Expected rejection for invalid policy case {$index}.");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $policy = new SecurityPolicy(allowedOrigins: ['https://Example.Test:443']);
        self::assertSame(['https://example.test'], $policy->allowedOrigins);
    }

    public function testConstraintValidatorGuards(): void
    {
        $validator = new RouteConstraintValidator();
        $this->expectException(InvalidConfigurationException::class);
        $validator->addCustom('bad-name', '/[a-z]+/');
    }

    public function testConstraintValidatorRejectsBrokenRegexAndReDoS(): void
    {
        $validator = new RouteConstraintValidator();

        try {
            $validator->addCustom('broken', '/([a-z]+/');
            self::fail('Expected broken regex rejection.');
        } catch (InvalidConfigurationException) {
            self::addToAssertionCount(1);
        }
        $this->expectException(InvalidConfigurationException::class);
        $validator->addCustom('nested', '/(a+)+$/');
    }

    public function testConstraintValidatorTestsBuiltInsAndUnknown(): void
    {
        $validator = new RouteConstraintValidator();
        $this->expectException(InvalidConfigurationException::class);
        $validator->assertKnown('nonexistent');
    }

    public function testConstraintValidatorAssertAndTest(): void
    {
        $validator = new RouteConstraintValidator();
        self::assertTrue($validator->test('id', 'int', '42'));
        self::assertFalse($validator->test('id', 'int', 'xx'));
        $this->expectException(InvalidConfigurationException::class);
        $validator->test('id', 'missing', '42');
    }

    // ------------------------------------------------------------------
    // EventDispatcher
    // ------------------------------------------------------------------

    public function testEventDispatcherLifecycleAndPriorities(): void
    {
        $dispatcher = new EventDispatcher();
        self::assertFalse($dispatcher->isFrozen());
        $seen = [];
        $dispatcher->listen(\stdClass::class, static function (object $event) use (&$seen): void {
            $seen[] = 'low';
        }, 10);
        $dispatcher->listen(\stdClass::class, static function (object $event) use (&$seen): void {
            $seen[] = 'high';
        }, 100);
        $returned = $dispatcher->dispatch(new \stdClass());
        self::assertInstanceOf(\stdClass::class, $returned);
        self::assertSame(['high', 'low'], $seen);
        self::assertSame(2, count($dispatcher->registrations()));
        $dispatcher->freeze();
        self::assertTrue($dispatcher->isFrozen());
        $this->expectException(\LogicException::class);
        $dispatcher->listen(\stdClass::class, static function (object $event): void {});
    }

    public function testEventDispatcherWrapsListenerFailures(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->listen(\stdClass::class, static function (object $event): never {
            throw new \RuntimeException('listener exploded');
        });
        $this->expectException(EventDispatchException::class);
        $dispatcher->dispatch(new \stdClass());
    }

    public function testEventDispatcherFreezeGuardsDispatchPaths(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->listen(\stdClass::class, static function (object $event): void {});
        $context = new EventContext('event-0000001', 0);
        $result = $dispatcher->dispatchWithContext(new \stdClass(), $context);
        self::assertInstanceOf(\stdClass::class, $result);
    }

    // ------------------------------------------------------------------
    // ServiceDefinition + JobEnvelope guards
    // ------------------------------------------------------------------

    public function testServiceDefinitionRejectsInvalidConfiguration(): void
    {
        $cases = [
            static fn (): ServiceDefinition => new ZefServiceDefinition('', static fn (): \stdClass => new \stdClass()),
            static fn (): ServiceDefinition => new ZefServiceDefinition('svc', 'not-callable'),
        ];
        foreach ($cases as $case) {
            try {
                $case();
                self::fail('Expected service definition rejection.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $definition = new ZefServiceDefinition('svc', static fn (): \stdClass => new \stdClass());
        self::assertSame('svc', $definition->id);
        self::assertSame('singleton', $definition->lifetime);
    }

    public function testJobEnvelopeGuards(): void
    {
        $envelope = new JobEnvelope('job-0000-0009', 'mail.send', ['x' => 1], 0);
        self::assertSame(1, $envelope->attempt);

        try {
            new JobEnvelope('short', 'mail.send', null, 0);
            self::fail('Expected short job ID rejection.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        $this->expectException(\InvalidArgumentException::class);
        new JobEnvelope('job-0000-0009', 'mail send', null, 0);
    }

    public function testJobEnvelopeHeaderAndAttemptGuards(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new JobEnvelope('job-0000-0009', 'mail.send', null, 0, 0, 0);
    }

    public function testJobEnvelopeHeaderValidation(): void
    {
        $envelope = new JobEnvelope('job-0000-0009', 'mail.send', null, 0, 0, 1, null, null, ['reply.to' => 'me@example.test']);
        self::assertSame('me@example.test', $envelope->headers['reply.to']);
        $this->expectException(\InvalidArgumentException::class);
        new JobEnvelope('job-0000-0009', 'mail.send', null, 0, 0, 1, null, null, ['bad name!' => 'x']);
    }

    public function testJobEnvelopeNextAttempt(): void
    {
        $envelope = new JobEnvelope('job-0000-0009', 'mail.send', null, 0);
        $next = $envelope->nextAttempt(250);
        self::assertSame(2, $next->attempt);
        self::assertGreaterThan(0, $next->availableAtUnixNano);
    }

    // ------------------------------------------------------------------
    // ConfigAggregator
    // ------------------------------------------------------------------

    public function testConfigAggregatorMergesProvidersInOrder(): void
    {
        $aggregator = new ConfigAggregator();
        $aggregator->addProvider(new class implements ConfigProviderInterface {
            #[\Override]
            public function getModuleName(): string
            {
                return 'alpha';
            }

            /**
             * @return array<string, mixed>
             */
            #[\Override]
            public function getConfig(): array
            {
                return ['settings' => ['a' => 1, 'shared' => 'alpha']];
            }
        });
        $aggregator->addProvider(new class implements ConfigProviderInterface {
            #[\Override]
            public function getModuleName(): string
            {
                return 'beta';
            }

            /**
             * @return array<string, mixed>
             */
            #[\Override]
            public function getConfig(): array
            {
                return ['settings' => ['shared' => 'beta']];
            }
        });
        $merged = $aggregator->merge();
        self::assertIsArray($merged['beta']);
        self::assertIsArray($merged['alpha']);
        self::assertIsArray($merged['beta']['settings']);
        self::assertIsArray($merged['alpha']['settings']);
        self::assertSame('beta', $merged['beta']['settings']['shared']);
        self::assertSame(1, $merged['alpha']['settings']['a']);
        self::assertSame(1, $aggregator->get('alpha.settings.a'));
        self::assertSame('beta', $aggregator->get('beta.settings.shared'));
        self::assertSame('fallback', $aggregator->get('missing.key', 'fallback'));
        self::assertSame(2, count($aggregator->providers()));
        self::assertArrayHasKey('beta', $aggregator->moduleDefinitions());
    }

    // ------------------------------------------------------------------
    // Observability rendering
    // ------------------------------------------------------------------

    public function testPrometheusRendererRendersCountersAndHistograms(): void
    {
        $telemetry = Telemetry::fromEnvironment(null, false);
        $telemetry->recordLog('INFO', 'unit.event', ['event.name' => 'unit.event']);
        $telemetry->meter()->increment('zef.unit.counter', 3, ['route' => '/x']);
        $telemetry->meter()->observe('zef.unit.duration', 0.25, ['route' => '/x']);
        $body = new PrometheusRenderer()->render($telemetry->meter(), ['service' => 'zef-test']);
        self::assertStringContainsString('# TYPE zef_unit_counter counter', $body);
        self::assertStringContainsString('zef_unit_counter{route="/x",service="zef-test"} 3.0', $body);
        self::assertStringContainsString('zef_unit_duration_sum', $body);
        self::assertStringContainsString('zef_unit_duration_count', $body);
    }

    public function testTelemetrySpanLifecycle(): void
    {
        $telemetry = Telemetry::fromEnvironment(null, false);
        self::assertFalse($telemetry->isEnabled());
        self::assertTrue($telemetry->isInMemoryExporter());
        $span = $telemetry->startSpan('unit.span', ['k' => 'v']);
        $span->addEvent('unit.event', ['step' => '1']);
        $span->end();
        $child = $telemetry->startSpan('unit.child', [], $span->getContext());
        $child->setStatus('ERROR', 'UnitTest');
        $child->end();
        self::assertNull($telemetry->extract('garbage'));
        self::assertNull($telemetry->extract(''));
    }

    public function testTelemetryExtractsValidTraceParent(): void
    {
        $telemetry = Telemetry::fromEnvironment(null, false);
        $parent = $telemetry->extract('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01');
        self::assertNotNull($parent);
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $parent->traceId);
    }

    // ------------------------------------------------------------------
    // ClientAddressResolver
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $server
     * @param array<string, string> $headers
     */
    private function requestWith(array $server, array $headers = []): ServerRequestInterface
    {
        return new ServerRequest('GET', new Uri('http://localhost/'), $server, [], [], [], null, $headers);
    }
}
