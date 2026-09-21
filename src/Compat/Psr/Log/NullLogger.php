<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Compat layer (PSR conditional shims — zero-composer fallback)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Psr\Log;

if (!class_exists(NullLogger::class)) {
    class NullLogger implements LoggerInterface
    {
        #[\Override]
        public function emergency(string|\Stringable $message, array $context = []): void {}
        #[\Override]
        public function alert(string|\Stringable $message, array $context = []): void {}
        #[\Override]
        public function critical(string|\Stringable $message, array $context = []): void {}
        #[\Override]
        public function error(string|\Stringable $message, array $context = []): void {}
        #[\Override]
        public function warning(string|\Stringable $message, array $context = []): void {}
        #[\Override]
        public function notice(string|\Stringable $message, array $context = []): void {}
        #[\Override]
        public function info(string|\Stringable $message, array $context = []): void {}
        #[\Override]
        public function debug(string|\Stringable $message, array $context = []): void {}
        #[\Override]
        public function log($level, string|\Stringable $message, array $context = []): void {}
    }
}
