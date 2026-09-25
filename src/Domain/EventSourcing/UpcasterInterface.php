<?php

declare(strict_types=1);

/*
 * ZEF Framework — Event Sourcing (Domain layer: ports, contracts, value objects)
 * Added in v2.23.0 (Event Sourcing Hardening: upcasting for payload schema
 * evolution during replay).
 */

namespace Zef\Framework\EventSourcing;

/**
 * Transforms a stored event from an older schema shape into the shape the
 * current aggregate code understands (upcasting).
 *
 * Contract:
 * - {@see eventTypes()} declares the event types the upcaster transforms;
 *   the {@see EventUpcaster} registry chains upcasters per type in
 *   registration order, so multi-step migrations (v1→v2→v3) compose
 *   naturally;
 * - {@see upcast()} MUST be pure: same input, same output, no side effects.
 *   It may rename the event or reshape `payload` / `metadata`, but MUST
 *   preserve stream identity — event id, aggregate coordinates, version,
 *   global sequence and recorded-at are frozen; the registry enforces this
 *   and rejects identity-mutating upcasters as a corruption guard;
 * - the returned event must satisfy the same grammar as any stored event
 *   (it is re-validated by the {@see StoredEvent} constructor on return).
 */
interface UpcasterInterface
{
    /**
     * @return list<string> event types transformed by this upcaster
     */
    public function eventTypes(): array;

    /**
     * @param StoredEvent $event the committed event in its stored shape
     *
     * @return StoredEvent the event in the shape current code expects
     */
    public function upcast(StoredEvent $event): StoredEvent;
}
