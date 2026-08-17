<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Event\App;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched by the bundle itself once the runtime's POST /_native/api/booted
 * has been handled. The runtime never pushes this one.
 */
final class ApplicationBooted extends Event
{
}
