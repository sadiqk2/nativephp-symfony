<?php

declare(strict_types=1);

namespace App\Controller;

use App\Native\NativeEvent;
use App\Native\WindowManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Channel B: the two endpoints the runtime posts to (requirements 5 and 6).
 */
final class NativeApiController
{
    public function __construct(
        private readonly WindowManager $windows,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * The entire app-startup contract. The runtime never opens a window itself —
     * it calls this and the app decides. Called at the end of runtime boot and
     * again on macOS `activate` with no visible windows, so it must be
     * idempotent; window/open already is.
     */
    #[Route('/_native/api/booted', name: 'native_booted', methods: ['POST'])]
    public function booted(): JsonResponse
    {
        $this->logger->info('NativePHP runtime booted; opening main window');

        $this->windows->open(id: 'main', url: '/', width: 1000, height: 720);

        return new JsonResponse(['success' => true]);
    }

    /**
     * Reverse channel. Reproduces the Laravel controller's two behaviours:
     *
     *  1. `...$payload` spreads positionally for list payloads and as NAMED
     *     arguments for string-keyed ones. Both shapes are used by the runtime
     *     (WindowResized is a list, ProcessExited is an object) — getting this
     *     wrong breaks about half of the 44 events.
     *  2. Unknown event names still dispatch, under their given name. Global
     *     shortcuts, menu items and notifications all let the app choose the
     *     name pushed back, so this path is load-bearing.
     */
    #[Route('/_native/api/events', name: 'native_events', methods: ['POST'])]
    public function events(Request $request): JsonResponse
    {
        /** @var array{event?: string, payload?: array<mixed>} $body */
        $body = json_decode((string) $request->getContent(), true) ?: [];

        // Names arrive with inconsistent leading backslashes depending on which
        // TS module pushed them. Normalise before doing anything.
        $name = ltrim((string) ($body['event'] ?? ''), '\\');
        $payload = $body['payload'] ?? [];

        if (!\is_array($payload)) {
            $payload = [$payload];
        }

        $this->logger->info('native-event {event}', ['event' => $name, 'payload' => $payload]);

        if ('' === $name) {
            return new JsonResponse(['success' => false], 400);
        }

        if (class_exists($name)) {
            /** @var object $event */
            $event = new $name(...$payload);
            $this->dispatcher->dispatch($event);
        } else {
            $this->dispatcher->dispatch(new NativeEvent($name, $payload), 'native.'.$name);
        }

        return new JsonResponse(['success' => true]);
    }
}
