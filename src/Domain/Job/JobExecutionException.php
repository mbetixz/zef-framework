<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Job;

class JobExecutionException extends \RuntimeException
{
    /** Corrupt stored payload: the row exists but its JSON document is unparsable. */
    public static function corruptPayload(\JsonException $previous): self
    {
        return new self('Stored job payload is not valid JSON.', 0, $previous);
    }
}
