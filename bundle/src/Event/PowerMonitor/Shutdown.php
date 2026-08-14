<?php

declare(strict_types=1);

namespace Native\Symfony\Event\PowerMonitor;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * The OS is shutting down. Runtime payload: none.
 */
final class Shutdown extends Event
{}
