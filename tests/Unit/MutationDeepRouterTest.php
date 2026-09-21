<?php

declare(strict_types=1);

/*
 * Zef Framework v2.14.1 — Mutation deep-dive round 2: router + emitter.
 *
 * Kills escaped mutants in Router (budget boundaries, method normalization,
 * group merge/rollback, sort fields, HEAD fallback, 405/400 semantics,
 * reverse routing trim, compiled restore) and ResponseEmitter (body
 * suppression boundaries, close semantics, unreadable streams).
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Exception\InvalidConfigurationException;
use Zef\Framework\Exception\MethodNotAllowedException;
use Zef\Framework\Exception\RouteConstraintException;
use Zef\Framework\Exception\RouteNotFoundException;
use Zef\Framework\Http\Response;
use Zef\Framework\Http\Stream;
use Zef\Framework\ResponseEmitter;
use Zef\Framework\Router\Router;

/**
 * @internal
 */
final class MutationDeepRouterTest extends TestCase
{
    // ------------------------------------------------------------------
    // Router — budget + registration guards
    // ------------------------------------------------------------------

    public function testBudgetBoundaries(): void
    {
        $router = new Router();
        $router->setMaxRoutesBudget(2);
        self::assertSame(2, $router->getMaxRoutesBudget());

        try {
            $router->setMaxRoutesBudget(0);
            self::fail('Budget 0 must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Route budget must be >= 1.', $e->getMessage());
        }
        $router->add('GET', '/one', 'h1');
        $router->add('GET', '/two', 'h2');

        try {
            $router->setMaxRoutesBudget(1);
            self::fail('Budget below the current route count must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Route budget cannot be lower than current route count.', $e->getMessage());
        }
        $router->setMaxRoutesBudget(2); // exactly the current count is fine
        self::assertSame(2, $router->getMaxRoutesBudget());

        try {
            $router->add('GET', '/third', 'h3');
            self::fail('Route beyond the budget must be rejected.');
        } catch (InvalidConfigurationException) {
            self::addToAssertionCount(1);
        }
    }

    public function testMethodNormalizationAndValidation(): void
    {
        $router = new Router();
        $router->add('  get  ', '/a', 'h-get');
        $hit = $router->match(' GeT ', '/a');
        self::assertSame('h-get', $hit['handler']);

        try {
            $router->add('NOT A VERB', '/b', 'h');
            self::fail('Non-token HTTP methods must be rejected.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    public function testPatternMustStartWithSlash(): void
    {
        $router = new Router();

        try {
            $router->add('GET', 'no-slash', 'h');
            self::fail("Patterns without a leading '/' must be rejected.");
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString("must begin with '/'", $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // Router — sort fields decide precedence
    // ------------------------------------------------------------------

    public function testStaticRouteWinsOverDynamicRegardlessOfRegistrationOrder(): void
    {
        $router = new Router();
        $router->add('GET', '/users/{id}', 'h-dynamic'); // registered first
        $router->add('GET', '/users/new', 'h-static');
        self::assertSame('h-static', $router->match('GET', '/users/new')['handler']);
        self::assertSame(['id' => '7'], $router->match('GET', '/users/7')['params']);
    }

    public function testConstrainedRouteWinsOverUnconstrainedWithEqualStatics(): void
    {
        $router = new Router();
        $router->add('GET', '/pay/{id:int}', 'h-constrained');
        $router->add('GET', '/pay/{id}', 'h-free');
        self::assertSame('h-constrained', $router->match('GET', '/pay/55')['handler']);
        self::assertSame('h-free', $router->match('GET', '/pay/abc')['handler'], 'Constraint failure falls through to the free route.');
    }

    public function testDefaultPriorityIsZeroAndOrderBreaksTies(): void
    {
        // Default priority must be exactly 0 — a param that satisfies two
        // different constraints must pick the priority-0 route, not the -1 one.
        $router = new Router();
        $router->addConstraint('loose', '/^[a-z0-9-]+$/');
        $router->add('GET', '/r/{x:loose}', 'h-loose', priority: -1);
        $router->add('GET', '/r/{y:slug}', 'h-slug'); // default priority 0
        self::assertSame('h-slug', $router->match('GET', '/r/abc-123')['handler'], 'Default priority 0 must rank above an explicit -1.');

        // And, with EQUAL sort fields (both constrained), an explicit
        // priority-0 route registered first keeps sequence precedence over a
        // default-priority route — proving the default is 0, not 1.
        $router2 = new Router();
        $router2->addConstraint('loose', '/^[a-z0-9-]+$/');
        $router2->add('GET', '/u/{x:slug}', 'h-first-registered', priority: 0);
        $router2->add('GET', '/u/{y:loose}', 'h-second-registered'); // default priority 0
        self::assertSame('h-first-registered', $router2->match('GET', '/u/abc-123')['handler'], 'Default priority is exactly 0 (sequence decides between equal priorities).');
    }

    public function testGroupPriorityAddsToRoutePriority(): void
    {
        $router = new Router();
        $router->group(['prefix' => '/api', 'priority' => 5], static function (Router $r): void {
            $r->add('GET', '/inner/{id:int}', 'h-grouped', priority: 1); // 5 + 1 = 6
        });
        $router->add('GET', '/api/inner/{id}', 'h-plain', priority: 5);
        self::assertSame('h-grouped', $router->match('GET', '/api/inner/9')['handler'], 'Group priority must ADD to the route priority (6 > 5).');
    }

    public function testNestedGroupPriorityMergeAndNullInheritance(): void
    {
        $router = new Router();
        $router->group(['prefix' => '/o', 'priority' => 2], static function (Router $r): void {
            // Inner group without priority inherits the parent's priority.
            $r->group(['prefix' => '/i'], static function (Router $r2): void {
                $r2->add('GET', '/x', 'h-inherited');
            });
            $r->group(['prefix' => '/j', 'priority' => 3], static function (Router $r2): void {
                $r2->add('GET', '/y', 'h-summed'); // 2 + 3 = 5
            });
        });
        $router->add('GET', '/o/x2', 'h-zero', priority: 4);
        // Route h-inherited (priority 2 + 0 inherited = 2) vs h-zero (4): h-zero wins on '/o/x2'.
        self::assertSame('h-zero', $router->match('GET', '/o/x2')['handler']);
        $routes = $router->getRoutes();
        $priorities = [];
        foreach ($routes as $route) {
            assert(is_array($route) && is_string($route['handler'] ?? null) && is_int($route['priority'] ?? null));
            $priorities[$route['handler']] = $route['priority'];
        }
        self::assertSame(2, $priorities['h-inherited'], 'A nested group without priority inherits the parent priority.');
        self::assertSame(5, $priorities['h-summed'], 'Nested group priorities are summed.');
    }

    public function testGroupStackIsRolledBackWhenTheCallbackThrows(): void
    {
        $router = new Router();

        try {
            $router->group(['prefix' => '/leak'], static function (): never {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            self::addToAssertionCount(1);
        }
        $router->add('GET', '/x', 'h-after-throw');
        self::assertSame('/x', $router->match('GET', '/x')['pattern'], 'The group prefix must not leak after a failed group callback.');
    }

    public function testGroupAttributeValidation(): void
    {
        $router = new Router();
        $cases = [
            [['prefix' => 'no-slash'], "must start with '/'"],
            [['prefix' => '/trailing/'], "must start with '/'"],
            [['name' => 123], 'name prefix must be a string'],
            [['middleware' => 'auth'], 'middleware must be a list'],
            [['middleware' => ['']], 'non-empty service IDs'],
            [['middleware' => [123]], 'non-empty service IDs'],
            [['priority' => '5'], 'priority must be an int or null'],
        ];
        foreach ($cases as [$attributes, $fragment]) {
            try {
                // @phpstan-ignore argument.type (invalid group attributes are the scenario under test)
                $router->group($attributes, static function (): void {});
                self::fail('Expected group validation failure for ' . json_encode($attributes));
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($fragment, $e->getMessage());
            }
        }
        // Valid edge cases must pass.
        $router->group(['prefix' => '/ok', 'name' => 'ok.', 'middleware' => ['auth-svc'], 'priority' => 2], static function (): void {});
        self::addToAssertionCount(1);
    }

    // ------------------------------------------------------------------
    // Router — match semantics
    // ------------------------------------------------------------------

    public function testHeadFallsBackToGet(): void
    {
        $router = new Router();
        $router->add('GET', '/only-get', 'h-get');
        self::assertSame('h-get', $router->match('HEAD', '/only-get')['handler']);
    }

    public function testMethodNotAllowedReportsAllAllowedMethodsIncludingHead(): void
    {
        $router = new Router();
        $router->add('GET', '/x', 'h-get');
        $router->add('POST', '/x', 'h-post');

        try {
            $router->match('DELETE', '/x');
            self::fail('Expected 405.');
        } catch (MethodNotAllowedException $e) {
            self::assertSame(['GET', 'HEAD', 'POST'], $e->allowedMethods, 'GET routes must also advertise HEAD.');
            self::assertSame('DELETE', $e->method);
        }
    }

    public function testRouteConstraintFailureIsReportedAs400(): void
    {
        $router = new Router();
        $router->add('GET', '/num/{id:int}', 'h');

        try {
            $router->match('GET', '/num/abc');
            self::fail('Expected constraint failure.');
        } catch (RouteConstraintException $e) {
            self::assertSame('id', $e->param);
            self::assertSame('int', $e->type);
            self::assertSame('abc', $e->value);
        }
    }

    public function testFirstConstraintFailureWinsWhenSeveralRoutesFail(): void
    {
        $router = new Router();
        $router->add('GET', '/multi/{x:int}', 'h-int');
        $router->add('GET', '/multi/{y:alpha}', 'h-alpha');

        try {
            $router->match('GET', '/multi/!');
            self::fail('Expected constraint failure.');
        } catch (RouteConstraintException $e) {
            self::assertSame('x', $e->param, 'The FIRST failing constraint is reported.');
        }
    }

    public function testUnknownPathThrowsRouteNotFound(): void
    {
        $router = new Router();
        $router->add('GET', '/known', 'h');
        $this->expectException(RouteNotFoundException::class);
        $router->match('GET', '/unknown');
    }

    public function testDuplicateDetectionVariants(): void
    {
        $router = new Router();
        $router->add('GET', '/dup/{id:int}', 'h1');

        try {
            $router->add('GET', '/dup/{other:int}', 'h2');
            self::fail('Signature collision must be detected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('collides with', $e->getMessage());
        }

        try {
            $router->add('GET', '/params/{a}/{a}', 'h3');
            self::fail('Duplicate parameter names must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString("Duplicate route parameter 'a'", $e->getMessage());
        }

        try {
            $router->add('GET', '/bad/{x:nope}', 'h4');
            self::fail('Unknown constraint names must be rejected.');
        } catch (InvalidConfigurationException) {
            self::addToAssertionCount(1);
        }
    }

    public function testReverseRoutingTrimsNames(): void
    {
        $router = new Router();
        $router->add('GET', '/home', 'h', name: '  home.index  ');
        self::assertTrue($router->hasRouteName('home.index'));
        self::assertTrue($router->hasRouteName('  home.index  '));
        self::assertSame('/home', $router->patternFor('home.index'));
        self::assertSame('/home', $router->patternFor('  home.index  '));
        self::assertSame(['home.index' => '/home'], $router->routeNames());

        try {
            $router->patternFor('missing');
            self::fail('Unknown route names must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString("Unknown route name 'missing'", $e->getMessage());
        }

        try {
            $router->add('GET', '/home2', 'h2', name: 'home.index');
            self::fail('Duplicate route names must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString("Duplicate route name 'home.index'", $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // Router — fallback + compiled round-trip
    // ------------------------------------------------------------------

    public function testFallbackSemantics(): void
    {
        $router = new Router();
        $router->add('GET', '/real', 'h-real');
        self::assertFalse($router->hasFallback());

        try {
            $router->matchOrFallback('GET', '/missing');
            self::fail('Without a fallback, 404 must propagate.');
        } catch (RouteNotFoundException) {
            self::addToAssertionCount(1);
        }
        $router->fallback('h-fallback');
        self::assertTrue($router->hasFallback());
        $hit = $router->matchOrFallback('GET', '/missing');
        self::assertTrue($hit['fallback']);
        self::assertSame('h-fallback', $hit['handler']);
        self::assertSame('*fallback*', $hit['pattern']);
        $direct = $router->matchOrFallback('GET', '/real');
        self::assertFalse($direct['fallback']);
        // 405 semantics survive the fallback.
        $this->expectException(MethodNotAllowedException::class);
        $router->matchOrFallback('POST', '/real');
    }

    public function testFallbackHandlerIdMustBeNonEmpty(): void
    {
        $router = new Router();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Fallback handler service ID must not be empty.');
        $router->fallback('');
    }

    public function testExportAndRestoreRoundTrip(): void
    {
        $router = new Router();
        $router->setMaxRoutesBudget(9);
        $router->addConstraint('digits', '/^\d+$/');
        $router->add('GET', '/item/{id:digits}', 'h-item', module: 'shop', priority: 3, name: 'item.show');
        $router->add('GET', '/cart', 'h-cart');
        $router->fallback('h-fallback');
        $export = $router->exportRoutes();
        self::assertSame(9, $export['maxRoutesBudget']);
        self::assertSame('h-fallback', $export['fallback']);
        self::assertArrayHasKey('digits', $export['constraints']);

        $restored = Router::fromCompiledArray($export);
        self::assertTrue($restored->isFrozen());
        self::assertSame('h-item', $restored->match('GET', '/item/42')['handler']);
        self::assertSame('/item/{id:digits}', $restored->patternFor('item.show'));
        self::assertTrue($restored->hasFallback());
        self::assertSame(9, $restored->getMaxRoutesBudget());
        // Restored constraints still validate.
        $this->expectException(RouteConstraintException::class);
        $restored->match('GET', '/item/not-digits');
    }

    public function testRestoreDefaultsForMissingBudgetAndSequence(): void
    {
        $router = new Router();
        $router->add('GET', '/a', 'h-a');
        $export = $router->exportRoutes();
        unset($export['maxRoutesBudget'], $export['sequence'], $export['nameIndex'], $export['fallback']);
        $restored = Router::fromCompiledArray($export);
        self::assertSame(1, $restored->getMaxRoutesBudget(), 'Missing budget defaults to the route count.');
        self::assertFalse($restored->hasFallback());
        $this->expectException(\InvalidArgumentException::class);
        $restored->patternFor('anything');
    }

    public function testRestoreRejectsBudgetZeroAndMalformedData(): void
    {
        Router::fromCompiledArray(['routes' => [], 'maxRoutesBudget' => 0]);
        self::addToAssertionCount(1);

        try {
            Router::fromCompiledArray([]);
            self::fail('Missing routes list must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Compiled route data is missing the routes list.', $e->getMessage());
        }
        $router = new Router();
        $router->add('GET', '/a', 'h-a');
        $export = $router->exportRoutes();
        $export['maxRoutesBudget'] = 0;
        $restored = Router::fromCompiledArray($export);
        self::assertSame(1, $restored->getMaxRoutesBudget(), 'A zero budget is clamped to 1 on restore.');
    }

    public function testGetRoutesAreSortedByPriorityThenStaticCount(): void
    {
        $router = new Router();
        $router->add('GET', '/a/{id}', 'h-dyn'); // 1 static, 0 constrained
        $router->add('GET', '/b', 'h-static-low', priority: -2);
        $router->add('GET', '/c', 'h-static-high', priority: 4);
        $routes = $router->getRoutes();
        self::assertSame(['h-static-high', 'h-dyn', 'h-static-low'], array_column($routes, 'handler'));
    }

    // ------------------------------------------------------------------
    // ResponseEmitter
    // ------------------------------------------------------------------

    public function testEmitterWritesBodyForRegularStatuses(): void
    {
        $emitter = new ResponseEmitter();
        foreach ([200, 201, 404, 500, 599] as $status) {
            \ob_start();
            $emitter->emit(new Response($status, [], 'body-' . $status));
            self::assertSame('body-' . $status, \ob_get_clean(), "Status {$status} must emit the body.");
        }
    }

    public function testEmitterSuppressesBodyForBodylessStatuses(): void
    {
        $emitter = new ResponseEmitter();
        foreach ([204, 205, 304] as $status) {
            \ob_start();
            $emitter->emit(new Response($status, [], 'suppressed'));
            self::assertSame('', \ob_get_clean(), "Status {$status} must not emit a body.");
        }
        foreach ([100, 150, 199] as $status) {
            \ob_start();
            $emitter->emit(new Response($status, [], 'suppressed'));
            self::assertSame('', \ob_get_clean(), "Informational status {$status} must not emit a body.");
        }
    }

    public function testEmitterSuppressAndCloseFlags(): void
    {
        $emitter = new ResponseEmitter();
        $body = Stream::fromString('payload');
        \ob_start();
        $emitter->emit(new Response(200, [], $body), closeBody: false, suppressBody: true);
        self::assertSame('', \ob_get_clean(), 'suppressBody must silence the output.');
        self::assertTrue($body->isReadable(), 'Without closeBody the stream stays open.');

        \ob_start();
        $emitter->emit(new Response(200, [], $body), closeBody: true, suppressBody: true);
        \ob_end_clean();
        self::assertFalse($body->isReadable(), 'closeBody must close the stream even when the body is suppressed.');
    }

    public function testEmitterRejectsUnreadableBodies(): void
    {
        $emitter = new ResponseEmitter();
        $body = Stream::fromString('data');
        $body->close();
        \ob_start();

        try {
            $emitter->emit(new Response(200, [], $body));
            self::fail('Unreadable bodies must fail.');
        } catch (\RuntimeException $e) {
            self::assertSame('Response body stream is not readable.', $e->getMessage());
        } finally {
            \ob_end_clean();
        }
    }

    public function testEmitterStreamsLargeBodiesInChunks(): void
    {
        $emitter = new ResponseEmitter();
        $payload = str_repeat('x', 20 * 8192 + 7);
        \ob_start();
        $emitter->emit(new Response(200, [], Stream::fromString($payload)));
        self::assertSame($payload, \ob_get_clean());
    }
}
