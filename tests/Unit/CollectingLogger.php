<?php

declare(strict_types=1);

/*
 * ZEF Framework — Shared test fixture: a collecting logger that records
 * warnings and errors so failure-fallback paths can be asserted
 * deterministically.
 */

namespace Zef\Test\Unit;

use Psr\Log\LoggerInterface;

/**
 * @internal
 */
final class CollectingLogger implements LoggerInterface
{
    /** @var list<string> */
    public array $warnings = [];

    /** @var list<string> */
    public array $errors = [];

    public function lastWarning(): ?string
    {
        return $this->warnings === [] ? null : $this->warnings[count($this->warnings) - 1];
    }

    /**
     * @param mixed[] $context
     */
    #[\Override]
    public function emergency(string|\Stringable $message, array $context = []): void {}

    /**
     * @param mixed[] $context
     */
    #[\Override]
    public function alert(string|\Stringable $message, array $context = []): void {}

    /**
     * @param mixed[] $context
     */
    #[\Override]
    public function critical(string|\Stringable $message, array $context = []): void {}

    /**
     * @param mixed[] $context
     */
    #[\Override]
    public function error(string|\Stringable $message, array $context = []): void
    {
        $this->errors[] = (string) $message;
    }

    /**
     * @param mixed[] $context
     */
    #[\Override]
    public function warning(string|\Stringable $message, array $context = []): void
    {
        $this->warnings[] = (string) $message;
    }

    /**
     * @param mixed[] $context
     */
    #[\Override]
    public function notice(string|\Stringable $message, array $context = []): void {}

    /**
     * @param mixed[] $context
     */
    #[\Override]
    public function info(string|\Stringable $message, array $context = []): void {}

    /**
     * @param mixed[] $context
     */
    #[\Override]
    public function debug(string|\Stringable $message, array $context = []): void {}

    /**
     * @param mixed[] $context
     */
    #[\Override]
    public function log(mixed $level, string|\Stringable $message, array $context = []): void {}
}
