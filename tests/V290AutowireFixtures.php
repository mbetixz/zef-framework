<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.9.0 — Autowiring suite fixtures.
 *
 * Concrete classes are required by the autowire engine; they exercise the
 * attribute set (#[Inject], #[Value], #[Target]) plus variadic collection,
 * transitive chains, and error shapes. Fixture-only code — never registered
 * in the demo application, so the HTTP surface stays byte-identical.
 */

namespace Zef\Test;

use Zef\Framework\Autowiring\Inject;
use Zef\Framework\Autowiring\Target;
use Zef\Framework\Autowiring\Value;

interface V290HandlerInterface
{
    public function name(): string;
}

final class V290MailHandler implements V290HandlerInterface
{
    #[\Override]
    public function name(): string
    {
        return 'mail';
    }
}

final class V290AuditHandler implements V290HandlerInterface
{
    #[\Override]
    public function name(): string
    {
        return 'audit';
    }
}

final class V290QueueHandler implements V290HandlerInterface
{
    #[\Override]
    public function name(): string
    {
        return 'queue';
    }
}

final class V290CronHandler implements V290HandlerInterface
{
    #[\Override]
    public function name(): string
    {
        return 'cron';
    }
}

final class V290Pipeline
{
    /**
     * @var list<V290HandlerInterface>
     */
    public readonly array $handlers;

    public function __construct(
        V290HandlerInterface ...$handlers,
    ) {
        $this->handlers = $handlers;
    }
}

final class V290ScalarPipeline
{
    /** @param list<string> $tags */
    public function __construct(
        #[Value('v290.tags')]
        public readonly array $tags,
    ) {}
}

final class V290SecretKey
{
    public function material(): string
    {
        return 'secret-material';
    }
}

final class V290Vault
{
    public function __construct(
        #[Inject(V290SecretKey::class)]
        public readonly V290SecretKey $key,
    ) {}
}

final class V290NamedDep
{
    public function __construct(
        #[Inject('v290.connection')]
        public readonly \stdClass $connection,
    ) {}
}

final class V290HttpConfig
{
    public function __construct(
        #[Value('v290.http.host')]
        public readonly string $host,
        #[Value('v290.http.port')]
        public readonly int $port,
        #[Value('v290.http.rate')]
        public readonly float $rate,
        #[Value('v290.http.debug')]
        public readonly bool $debug,
        #[Value('v290.http.allowed')]
        public readonly array $allowed,
        public readonly int $timeout = 30,
        public readonly ?string $label = null,
    ) {}
}

interface V290RepoInterface
{
    public function find(string $id): string;
}

final class V290DbRepo implements V290RepoInterface
{
    #[\Override]
    public function find(string $id): string
    {
        return "row-{$id}";
    }
}

final class V290RepoConsumer
{
    public function __construct(
        public readonly V290RepoInterface $repo,
    ) {}
}

interface V290CacheInterface {}

final class V290ArrayCache implements V290CacheInterface {}

final class V290CacheUser
{
    public function __construct(
        #[Target(V290ArrayCache::class)]
        public readonly V290CacheInterface $cache,
    ) {}
}

final class V290Leaf
{
    public function seed(): string
    {
        return 'leaf';
    }
}

final class V290Middle
{
    public function __construct(
        public readonly V290Leaf $leaf,
    ) {}
}

final class V290Root
{
    public function __construct(
        public readonly V290Middle $middle,
    ) {}
}

final class V290CycleA
{
    public function __construct(public readonly V290CycleB $b) {}
}

final class V290CycleB
{
    public function __construct(public readonly V290CycleA $a) {}
}

final class V290UnionDep
{
    public function __construct(
        public readonly int|string $value,
    ) {}
}

abstract class V290AbstractBase {}

final class V290OptionalMiddle
{
    public function __construct(
        public readonly V290SecretKey $key,
        public readonly int $retries = 5,
        public readonly ?V290Leaf $leaf = null,
    ) {}
}

final class V290UnboundOptional
{
    public function __construct(
        public readonly ?V290CacheInterface $cache = null,
    ) {}
}

final class V290BoomService
{
    public function __construct(
        #[Inject('v290.boom')]
        public readonly \stdClass $boom,
    ) {}
}

final class V290AlphaConsumer
{
    public function __construct(
        public readonly V290BetaOne $one,
        public readonly V290BetaTwo $two,
    ) {}
}

final class V290BetaOne
{
    public function tag(): string
    {
        return 'beta-one';
    }
}

final class V290BetaTwo
{
    public function tag(): string
    {
        return 'beta-two';
    }
}
