<?php

declare(strict_types=1);

/*
 * ZEF Framework — Coverage push #5 (v2.13.1): final corner branches —
 * router budget lowering, fallback guards, compiled-cache validation and
 * the job handler registration variants (JobInterface/JobHandlerInterface).
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Zef\Framework\Container\Container;
use Zef\Framework\Container\ServiceDefinition;
use Zef\Framework\Exception\InvalidConfigurationException;
use Zef\Framework\Http\RequestFactory;
use Zef\Framework\Http\Stream;
use Zef\Framework\Http\Uri;
use Zef\Framework\Job\InMemoryJobQueue;
use Zef\Framework\Job\InProcessJobWorker;
use Zef\Framework\Job\JobContext;
use Zef\Framework\Job\JobEnvelope;
use Zef\Framework\Job\JobHandlerInterface;
use Zef\Framework\Job\JobInterface;
use Zef\Framework\Job\JobResult;
use Zef\Framework\Policy\ArchitecturePolicy;
use Zef\Framework\Router\Router;

/**
 * @internal
 */
final class CornerBranchTest extends TestCase
{
    public function testRouterBudgetCannotGoBelowCurrentRouteCount(): void
    {
        $router = new Router();
        $router->add('GET', '/one', 'svc');
        $router->add('GET', '/two', 'svc');
        $this->expectException(\InvalidArgumentException::class);
        $router->setMaxRoutesBudget(1);
    }

    public function testRouterFallbackFrozenAndEmptyGuards(): void
    {
        $router = new Router();
        $this->expectException(\InvalidArgumentException::class);
        $router->fallback('');
    }

    public function testRouterFallbackAfterFreezeFails(): void
    {
        $router = new Router();
        $router->fallback('fb.svc');
        $router->freeze();
        $this->expectException(\LogicException::class);
        $router->fallback('other.svc');
    }

    public function testRouterFromCompiledArrayRequiresRoutes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Router::fromCompiledArray([]);
    }

    public function testRouterConstraintValidatorAccessor(): void
    {
        $router = new Router();
        self::assertSame([], $router->constraintValidator()->customConstraints());
        $router->addConstraint('ref', '/^R\d{4}$/');
        self::assertSame(['ref' => '/^R\d{4}$/'], $router->constraintValidator()->customConstraints());
    }

    public function testContainerRegisterDefinitionEnforcesBudget(): void
    {
        $container = new Container(false, new ArchitecturePolicy(0, 1));
        $container->registerDefinition(new ServiceDefinition('only.svc', static fn (): \stdClass => new \stdClass()));
        $this->expectException(InvalidConfigurationException::class);
        $container->registerDefinition(new ServiceDefinition('over.svc', static fn (): \stdClass => new \stdClass()));
    }

    public function testFactoryIgnoresNonStringServerValues(): void
    {
        $request = RequestFactory::fromServer([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_HOST' => 'localhost',
            'HTTP_X_NUMERIC' => 42,
            'REQUEST_TIME' => 12345,
        ]);
        self::assertFalse($request->hasHeader('X-Numeric'));
        self::assertSame('localhost', $request->getUri()->getHost());
    }

    public function testUriEncodesRawSpacesAndStrayPercentSigns(): void
    {
        $uri = new Uri('https://example.test/pa th%zz?q=a b%2fC');
        self::assertStringContainsString('%20', $uri->getPath());
        self::assertStringContainsString('%25ZZ', strtoupper($uri->getPath()));
        self::assertStringContainsString('%2F', strtoupper($uri->getQuery()));
        self::assertStringContainsString('a', $uri->getQuery());
    }

    public function testStreamStateQueriesAfterDetachAndZeroRead(): void
    {
        $stream = Stream::fromString('abc');
        $stream->detach();
        self::assertFalse($stream->isWritable());
        self::assertFalse($stream->isReadable());
        self::assertFalse($stream->isSeekable());
        self::assertTrue($stream->eof());
        self::assertSame('', Stream::fromString('abc')->read(0));
    }

    public function testContainerDecorationRewritesRegistry(): void
    {
        $container = new Container();
        $container->register('core.svc', static fn (): \ArrayObject => new \ArrayObject(['v' => 'base']), [], 'test');
        $container->decorate('core.svc', static function (ContainerInterface $c, mixed $inner): mixed {
            assert($inner instanceof \ArrayObject);
            $inner['decorated'] = true;

            return $inner;
        });
        $container->validateAndFreeze();
        $resolved = $container->get('core.svc');
        assert($resolved instanceof \ArrayObject);
        self::assertSame('base', $resolved['v']);
        self::assertTrue($resolved['decorated']);
    }

    public function testJobWorkerAcceptsJobInterfaceHandlers(): void
    {
        $queue = new InMemoryJobQueue();
        $queue->enqueue(new JobEnvelope('job-0000-0100', 'object.job', null, 0));
        $handler = new class implements JobInterface {
            public function handle(JobContext $context): string
            {
                return 'handled-by-job';
            }
        };
        $worker = new InProcessJobWorker($queue);
        $worker->register('object.job', $handler);
        $result = $worker->processOne();
        assert($result instanceof JobResult);
        self::assertSame('handled-by-job', $result->result);
    }

    public function testJobWorkerAcceptsInvokableHandlers(): void
    {
        $queue = new InMemoryJobQueue();
        $queue->enqueue(new JobEnvelope('job-0000-0101', 'invokable.job', null, 0));
        $handler = new class implements JobHandlerInterface {
            #[\Override]
            public function __invoke(JobEnvelope $job, JobContext $context): string
            {
                return 'invoked';
            }
        };
        $worker = new InProcessJobWorker($queue);
        $worker->register('invokable.job', $handler);
        $result = $worker->processOne();
        assert($result instanceof JobResult);
        self::assertSame('invoked', $result->result);
    }
}
