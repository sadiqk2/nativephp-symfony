<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\EventBridge;

use Native\Symfony\Desktop\Contract\BroadcastsToRuntime;
use Native\Symfony\Desktop\Contract\ClientInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * App -> runtime -> every renderer.
 *
 * POST /api/broadcast reaches the front end only; it never comes back to PHP.
 * The page receives it through window.Native.on().
 */
final class RuntimeBroadcaster
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly ClientInterface $client,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function broadcast(BroadcastsToRuntime $event): void
    {
        if (!$this->client->isAvailable()) {
            return;
        }

        try {
            $this->client->post('broadcast', [
                // The runtime's own events go out with a leading backslash and
                // the preload strips it before comparing, so either form works
                // on the wire. Match upstream's shape for consistency.
                'event' => '\\'.ltrim($event->broadcastAs(), '\\'),
                'payload' => $event->broadcastPayload(),
            ]);
        } catch (\Throwable $e) {
            // A failed broadcast must not take down whatever dispatched it.
            $this->logger->error('Failed to broadcast {event} to the runtime: {error}', [
                'event' => $event->broadcastAs(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
