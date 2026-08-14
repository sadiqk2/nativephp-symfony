<?php

declare(strict_types=1);

namespace Native\Symfony\Event\Windows;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * The window was hidden.
 *
 * Runtime payload: ["<id>"] — positional.
 */
final class WindowHidden extends Event
{
    public function __construct(public readonly string $id)
    {
    }
}
