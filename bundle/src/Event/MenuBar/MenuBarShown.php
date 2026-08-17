<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Event\MenuBar;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * The menu bar window became visible. Runtime payload: none.
 */
final class MenuBarShown extends Event
{}
