<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Event\Windows;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * The window was restored from maximized.
 *
 * Runtime payload: ["<id>"] — positional.
 */
final class WindowUnmaximized extends Event
{
    public function __construct(public readonly string $id)
    {
    }
}
