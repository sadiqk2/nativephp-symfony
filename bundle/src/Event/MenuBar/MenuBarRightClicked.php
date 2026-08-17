<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Event\MenuBar;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * The tray icon was right-clicked. The runtime also hides the menu bar window
 * and pops the context menu itself unless created with onlyShowContextMenu.
 *
 * Runtime payload: {combo, bounds} — named.
 */
final class MenuBarRightClicked extends Event
{
    public function __construct(
        public readonly array $combo,
        public readonly array $bounds,
    ) {
    }
}
