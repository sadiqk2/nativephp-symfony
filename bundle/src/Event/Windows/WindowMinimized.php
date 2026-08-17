<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Event\Windows;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * The window was minimized.
 *
 * Runtime payload: ["<id>"] — positional.
 */
final class WindowMinimized extends Event
{
    public function __construct(public readonly string $id)
    {
    }
}
