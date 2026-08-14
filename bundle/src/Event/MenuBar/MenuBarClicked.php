<?php

declare(strict_types=1);

namespace Native\Symfony\Event\MenuBar;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * The tray icon was clicked.
 *
 * Runtime payload: {combo, bounds, position} — named.
 */
final class MenuBarClicked extends Event
{
    public function __construct(
        public readonly array $combo,
        public readonly array $bounds,
        public readonly array $position,
    ) {
    }
}
