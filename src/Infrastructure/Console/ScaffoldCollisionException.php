<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.16.0 — Infrastructure layer (outbound adapters).
 * ZEF Maker: a scaffold target already exists on disk (never overwritten).
 */

namespace Zef\Framework\Console;

final class ScaffoldCollisionException extends \RuntimeException implements ConsoleException {}
