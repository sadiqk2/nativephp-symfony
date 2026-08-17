<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Event\MenuBar;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * The tray was created.
 *
 * Fires only on FIRST creation: calling menu-bar/create again destroys the
 * existing tray and deliberately suppresses this event.
 *
 * Runtime payload: none.
 */
final class MenuBarCreated extends Event
{}
