<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Event\Windows;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * The window became visible.
 *
 * Runtime payload: ["<id>"] — positional.
 */
final class WindowShown extends Event
{
    public function __construct(public readonly string $id)
    {
    }
}
