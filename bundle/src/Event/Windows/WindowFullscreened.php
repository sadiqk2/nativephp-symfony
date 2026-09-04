<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Event\Windows;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * The window entered full screen.
 *
 * Runtime payload: ["<id>"] — positional.
 *
 * Fires however the change was made — the user's own green button or a call to
 * WindowManager::fullscreen() — because the runtime listens for Electron's
 * `enter-full-screen`, not for its own endpoint.
 */
final class WindowFullscreened extends Event
{
    public function __construct(public readonly string $id)
    {
    }
}
