<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Event\App;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * The OS asked the app to open a file (macOS `open-file`).
 *
 * Runtime payload: ["<path>"] — positional.
 */
final class OpenFile extends Event
{
    public function __construct(public readonly string $path)
    {
    }
}
