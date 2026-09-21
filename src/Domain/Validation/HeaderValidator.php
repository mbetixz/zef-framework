<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Validation;

use Zef\Framework\Exception\InvalidHeaderException;

final class HeaderValidator
{
    public function assertName(string $name): void
    {
        if ($name === '' || preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $name) !== 1) {
            throw new InvalidHeaderException("Invalid header name '{$name}'.");
        }
    }

    /**
     * Bug fix #3: tightened to RFC 9110 — rejects control chars except HTAB.
     */
    public function assertValue(string $name, string $value): void
    {
        // RFC 9110 §5.5: field-value = *( field-content )
        // field-content = field-vchar [ 1*( SP / HTAB / field-vchar ) field-vchar ]
        // field-vchar = VCHAR / obs-text  (0x21-0x7E, 0x80-0xFF)
        // HTAB (0x09) is allowed; all other control chars are not.
        if (preg_match('/[^\t\x20-\x7E\x80-\xFF]/', $value) === 1) {
            throw new InvalidHeaderException("Invalid header value for '{$name}'.");
        }
    }
}
