<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.16.0 — Infrastructure layer (outbound adapters).
 * ZEF Maker: contract implemented by every scaffold generator so the
 * dispatcher stays fully typed (no object/mixed leakage into PHPStan max).
 */

namespace Zef\Framework\Console;

interface GeneratorInterface
{
    /**
     * @param list<string> $argv generator arguments (name + --flags)
     */
    public function generate(?string $rawName, array $argv = []): int;
}
