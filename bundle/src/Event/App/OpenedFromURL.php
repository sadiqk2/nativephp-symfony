<?php

declare(strict_types=1);

namespace Native\Symfony\Event\App;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * The app was launched or focused via its deep-link scheme.
 *
 * The one event the runtime pushes in BOTH payload shapes: macOS `open-url`
 * sends ["<url>"] (positional) while the Windows/Linux `second-instance` path
 * sends {"url": "<url>"} (named). Naming this constructor parameter `url` is
 * what makes a single class satisfy both — PHP's argument unpacking treats a
 * string-keyed array as named arguments.
 */
final class OpenedFromURL extends Event
{
    public function __construct(public readonly string $url)
    {
    }
}
