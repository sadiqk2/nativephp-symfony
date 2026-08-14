<?php

declare(strict_types=1);

namespace Native\Symfony\Http;

use Native\Symfony\Event\NativeEvent;
use Native\Symfony\EventBridge\EventFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Contract requirement 6: the reverse channel. 44 known event types plus the
 * caller-named ones.
 */
final class EventsController
{
    public function __construct(
        private readonly EventFactory $factory,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $body = json_decode((string) $request->getContent(), true);

        if (!\is_array($body) || !isset($body['event']) || !\is_string($body['event'])) {
            return new JsonResponse(['success' => false, 'error' => 'Missing event name.'], 400);
        }

        $payload = $body['payload'] ?? [];

        // A scalar payload is not something the runtime sends today, but the
        // spread would fatal on one, so normalise instead of trusting the shape.
        if (!\is_array($payload)) {
            $payload = [$payload];
        }

        $event = $this->factory->create($body['event'], $payload);

        if (!$event instanceof NativeEvent) {
            // Typed events dispatch under their class name, so #[AsEventListener]
            // on a typed parameter is all a listener needs.
            $this->dispatcher->dispatch($event);

            return new JsonResponse(['success' => true]);
        }

        // Caller-named events have no class to key on, so they go out twice:
        //
        //   - under "native.{name}", for a listener that wants one specific
        //     action (a menu item, a shortcut) with no filtering;
        //   - under NativeEvent::class, for a catch-all — invaluable while
        //     porting, since it shows every event the runtime is really sending.
        //
        // Symfony notifies only the listeners registered for the name passed to
        // dispatch(), so a single call cannot serve both. Registering for both
        // names would run a listener twice; register for one.
        $this->dispatcher->dispatch($event, $event->dispatchName());
        $this->dispatcher->dispatch($event, NativeEvent::class);

        return new JsonResponse(['success' => true]);
    }
}
