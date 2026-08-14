<?php

declare(strict_types=1);

namespace Native\Symfony\EventBridge;

use Native\Symfony\Contract\BroadcastsToRuntime;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Decorates the application dispatcher so any BroadcastsToRuntime event reaches
 * the front end automatically, without the app calling the broadcaster itself.
 *
 * This is the closest available equivalent to Laravel's EventWatcher, which
 * registers Event::listen('*') and inspects every dispatched object. Symfony has
 * no wildcard listener, and registering a listener per class would need a
 * compiler pass that cannot see runtime-defined classes — so the inspection
 * happens here instead, at the one point every dispatch passes through.
 *
 * Note it implements the *component* EventDispatcherInterface, not just the
 * contract. The compiled container registers every listener and subscriber by
 * calling addListener()/addSubscriber() on the `event_dispatcher` service, so a
 * decorator that only implements dispatch() breaks the container at build time
 * with "Attempted to call an undefined method named addListener". Everything
 * other than dispatch() is a straight delegation.
 *
 * Cost of the approach, stated plainly: one instanceof per dispatched event.
 * That is cheaper than Laravel's version, which runs a closure with a
 * method_exists() and an in_array() on every event in the application.
 */
final class BroadcastingDispatcher implements EventDispatcherInterface
{
    public function __construct(
        private readonly EventDispatcherInterface $inner,
        private readonly RuntimeBroadcaster $broadcaster,
    ) {
    }

    public function dispatch(object $event, ?string $eventName = null): object
    {
        $dispatched = $this->inner->dispatch($event, $eventName);

        // After the inner dispatch: listeners may enrich the payload, and one may
        // legitimately stop the event, in which case the front end should not
        // hear about it either.
        if ($dispatched instanceof BroadcastsToRuntime && !$this->isStopped($dispatched)) {
            $this->broadcaster->broadcast($dispatched);
        }

        return $dispatched;
    }

    public function addListener(string $eventName, callable|array $listener, int $priority = 0): void
    {
        $this->inner->addListener($eventName, $listener, $priority);
    }

    public function addSubscriber(\Symfony\Component\EventDispatcher\EventSubscriberInterface $subscriber): void
    {
        $this->inner->addSubscriber($subscriber);
    }

    public function removeListener(string $eventName, callable|array $listener): void
    {
        $this->inner->removeListener($eventName, $listener);
    }

    public function removeSubscriber(\Symfony\Component\EventDispatcher\EventSubscriberInterface $subscriber): void
    {
        $this->inner->removeSubscriber($subscriber);
    }

    public function getListeners(?string $eventName = null): array
    {
        return $this->inner->getListeners($eventName);
    }

    public function getListenerPriority(string $eventName, callable|array $listener): ?int
    {
        return $this->inner->getListenerPriority($eventName, $listener);
    }

    public function hasListeners(?string $eventName = null): bool
    {
        return $this->inner->hasListeners($eventName);
    }

    private function isStopped(object $event): bool
    {
        return $event instanceof Event && $event->isPropagationStopped();
    }
}
