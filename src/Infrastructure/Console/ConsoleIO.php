<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.16.0 — Infrastructure layer (outbound adapters).
 * ZEF Maker: thin output port. Every message written through here is also
 * recorded in an in-memory log so tests can assert CLI output without
 * spawning subprocesses or capturing global streams.
 */

namespace Zef\Framework\Console;

final class ConsoleIO
{
    /** @var list<string> */
    private array $outLog = [];

    /** @var list<string> */
    private array $errLog = [];

    /**
     * @param resource $out output stream (defaults to the real STDOUT)
     * @param resource $err error stream (defaults to the real STDERR)
     */
    public function __construct(private $out = STDOUT, private $err = STDERR) {}

    public function out(string $message = ''): void
    {
        fwrite($this->out, $message . "\n");
        $this->outLog[] = $message;
    }

    public function err(string $message = ''): void
    {
        fwrite($this->err, $message . "\n");
        $this->errLog[] = $message;
    }

    /** @return list<string> */
    public function outLog(): array
    {
        return $this->outLog;
    }

    /** @return list<string> */
    public function errLog(): array
    {
        return $this->errLog;
    }
}
