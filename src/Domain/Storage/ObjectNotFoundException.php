<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.30.0 — Domain layer (ports, contracts, value objects)
 * Ecosystem Ports: thrown by get()/stat()-style reads on absent keys.
 */

namespace Zef\Framework\Storage;

final class ObjectNotFoundException extends StorageException
{
    public static function forKey(string $key): self
    {
        return new self("Object '{$key}' does not exist.");
    }
}
