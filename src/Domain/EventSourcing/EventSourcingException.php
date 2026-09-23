<?php

declare(strict_types=1);

/*
 * ZEF Framework — Event Sourcing (Domain layer: ports, contracts, value objects)
 * Added in v2.19.0 (Event Sourcing: event store, projections, snapshots,
 * transactional outbox built on the Database Core port).
 */

namespace Zef\Framework\EventSourcing;

/**
 * Base exception for every Event Sourcing failure.
 *
 * Extends RuntimeException so infrastructure adapters (PDO failures wrapped
 * as DatabaseException) can be chained while user code catches a single
 * type for the whole zone.
 */
class EventSourcingException extends \RuntimeException {}
