<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 * v2.21.0: no longer final so ConfigValidationException can extend it
 * (BC-safe: nothing changes for existing subclasses/callers).
 */

namespace Zef\Framework\Exception;

class InvalidConfigurationException extends \RuntimeException {}
