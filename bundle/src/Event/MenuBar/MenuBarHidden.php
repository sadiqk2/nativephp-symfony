<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Event\MenuBar;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * The menu bar window was hidden. Runtime payload: none.
 */
final class MenuBarHidden extends Event
{}
