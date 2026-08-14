<?php

declare(strict_types=1);

namespace Native\Symfony\Event\Windows;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * The window was resized *by the user*.
 *
 * Not emitted for resizes the app requests itself: the runtime listens for
 * Electron's `resized`, which does not fire for setSize(). Verified empirically
 * — see SPIKE-RESULTS.md finding 5.
 *
 * Runtime payload: ["<id>", <width>, <height>] — positional.
 */
final class WindowResized extends Event
{
    public function __construct(
        public readonly string $id,
        public readonly int $width,
        public readonly int $height,
    ) {
    }
}
