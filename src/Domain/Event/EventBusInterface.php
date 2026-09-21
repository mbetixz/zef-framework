<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Event;

use Psr\EventDispatcher\EventDispatcherInterface;

interface EventBusInterface extends EventDispatcherInterface
{
    public function listen(string $eventClass, callable $listener, int $priority = 0): void;

    public function subscribe(EventSubscriberInterface $subscriber): void;

    public function dispatchWithContext(object $event, EventContext $context): object;

    /** @return list<EventRegistration> */
    public function registrations(): array;

    public function freeze(): void;
}
