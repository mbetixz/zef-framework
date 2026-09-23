<?php

declare(strict_types=1);

/*
 * ZEF Framework — Domain port: in-process sleeper primitive.
 *
 * Issue #36 exit ramp (Guild Action Item 1). The KernelSleeper carve-out
 * existed because the Application-layer consumers (BatchSpanProcessor,
 * InProcessJobWorker) referenced the Adapters-layer BlockingSleeper
 * directly. This port inverts that dependency: consumers now depend on
 * the Domain contract and receive an implementation through their
 * constructors, with SystemSleeper as the in-process default.
 * BlockingSleeper stays in Adapters untouched as the static public API.
 */

namespace Zef\Framework\Runtime;

interface SleeperInterface
{
    /**
     * Block the current process for the given duration. Non-positive
     * values are a no-op (mirrors BlockingSleeper semantics).
     */
    public function sleepMilliseconds(int $ms): void;
}
