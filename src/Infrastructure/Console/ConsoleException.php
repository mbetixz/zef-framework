<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.16.0 — Infrastructure layer (outbound adapters).
 * ZEF Maker: marker interface for every scaffolding failure so the
 * CLI dispatcher can convert them into a uniform exit code 1.
 */

namespace Zef\Framework\Console;

interface ConsoleException extends \Throwable {}
