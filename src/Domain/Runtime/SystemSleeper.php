<?php

declare(strict_types=1);

/*
 * ZEF Framework — Domain default sleeper: usleep() system primitive.
 *
 * Logic moved verbatim from Adapters/Runtime/BlockingSleeper
 * (behaviour-preserving: the >0 guard plus the function_exists fallback
 * for environments without usleep). Purity here follows the framework's
 * enforced standard — no cross-layer class references; usleep() is a
 * language built-in, the same category as the getenv()/microtime() calls
 * the Domain layer already performs (see SecurityPolicy).
 */

namespace Zef\Framework\Runtime;

final class SystemSleeper implements SleeperInterface
{
    public function sleepMilliseconds(int $ms): void
    {
        if ($ms > 0 && function_exists('usleep')) {
            usleep($ms * 1000);
        }
    }
}
