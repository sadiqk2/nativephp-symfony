<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Contract;

/**
 * Marker for app events that should also reach the front end.
 *
 * Laravel does this with a wildcard listener: EventWatcher registers
 * Event::listen('*'), inspects every dispatched object for broadcastOn() and a
 * 'nativephp' channel, and forwards matches to POST /api/broadcast. Symfony's
 * dispatcher has no wildcard hook, so the intent has to be declared on the
 * event instead of sniffed at dispatch time.
 *
 * The trade is worth stating plainly: this is more explicit than the Laravel
 * original but not a drop-in translation of it — an app ported across has to
 * add this interface to events that previously only declared a channel.
 *
 * @see \Native\Symfony\Desktop\EventBridge\RuntimeBroadcaster
 */
interface BroadcastsToRuntime
{
    /**
     * The event name the front end listens for via window.Native.on(). Return
     * the class name to mirror the runtime's own convention.
     */
    public function broadcastAs(): string;

    /** @return array<string, mixed> */
    public function broadcastPayload(): array;
}
