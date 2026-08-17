<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Event\Windows;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * The window gained focus.
 *
 * Runtime payload: ["<id>"] — positional.
 */
final class WindowFocused extends Event
{
    public function __construct(public readonly string $id)
    {
    }
}
