<?php

declare(strict_types=1);

namespace App\Event;

use Native\Symfony\Desktop\Contract\BroadcastsToRuntime;

/**
 * An application event that also reaches every open window's JavaScript.
 *
 * Dispatched normally through the Symfony dispatcher. The bundle decorates
 * `event_dispatcher` and forwards anything implementing BroadcastsToRuntime to
 * POST /api/broadcast, which the runtime fans out to every renderer — so the
 * inspector window updates when a note is saved in the main window, with no
 * polling and no socket of our own.
 *
 * Laravel does this by sniffing every dispatched object for a `nativephp`
 * broadcast channel. Symfony has no wildcard listener, so the intent is declared
 * on the event instead. More explicit, and one instanceof per dispatch.
 */
final class NoteSaved implements BroadcastsToRuntime
{
    public function __construct(
        public readonly string $title,
        public readonly int $total,
    ) {
    }

    public function broadcastAs(): string
    {
        // The name the front end listens for:
        //   Native.on('App\\Event\\NoteSaved', …)
        // Mirroring the runtime's own class-name convention keeps one rule in the
        // JavaScript instead of two.
        return self::class;
    }

    public function broadcastPayload(): array
    {
        return ['title' => $this->title, 'total' => $this->total];
    }
}
