<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Event\Windows;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * The window was closed. The runtime has already removed it from its
 * window map by the time this arrives, so calls targeting this id are no-ops.
 *
 * Runtime payload: ["<id>"] — positional.
 */
final class WindowClosed extends Event
{
    public function __construct(public readonly string $id)
    {
    }
}
