<?php

declare(strict_types=1);

namespace Native\Symfony\Event\MenuBar;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * The menu bar window was hidden. Runtime payload: none.
 */
final class MenuBarHidden extends Event
{}
