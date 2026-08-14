<?php

declare(strict_types=1);

namespace App\Event;

/**
 * The names this app's menu items and global shortcuts travel under.
 *
 * A menu item's `event` is just a string on the wire: the runtime sends it back
 * verbatim when the item is clicked, and the bundle turns it into a
 * {@see \Native\Symfony\Event\NativeEvent} carrying that name. Nothing forces the
 * name to be a class — and deliberately so, because a name arriving from the
 * runtime is never used to instantiate anything (see the bundle's security notes).
 *
 * An enum instead of loose strings for the ordinary reason: the item that sends
 * the name and the listener that handles it are in different files, and a typo
 * between them is a menu item that silently does nothing.
 */
enum MenuAction: string
{
    case About = 'App\Menu\About';
    case NewNote = 'App\Menu\NewNote';
    case CopyLatest = 'App\Menu\CopyLatest';
    case Seed = 'App\Menu\Seed';
    case OpenInspector = 'App\Menu\OpenInspector';
    case MobileScreens = 'App\Menu\MobileScreens';
}
