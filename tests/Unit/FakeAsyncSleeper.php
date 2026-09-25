<?php

declare(strict_types=1);

/*
 * ZEF Framework — test fixture: recording sleeper that advances the linked
 * FakeAsyncClock, so scheduler idle-waits make time pass deterministically
 * without any real sleeping.
 */

namespace Zef\Test\Unit;

use Zef\Framework\Runtime\SleeperInterface;

final class FakeAsyncSleeper implements SleeperInterface
{
    /**
     * @var list<int>
     */
    public array $sleepsMs = [];

    public function __construct(private readonly FakeAsyncClock $clock) {}

    #[\Override]
    public function sleepMilliseconds(int $ms): void
    {
        $this->sleepsMs[] = $ms;
        $this->clock->advanceMilliseconds($ms);
    }
}
