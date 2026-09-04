<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Event\Windows;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * The window left full screen.
 *
 * Runtime payload: ["<id>"] — positional.
 *
 * The counterpart to WindowFullscreened, and fires on the same terms: Electron's
 * `leave-full-screen`, whoever caused it.
 */
final class WindowUnfullscreened extends Event
{
    public function __construct(public readonly string $id)
    {
    }
}
