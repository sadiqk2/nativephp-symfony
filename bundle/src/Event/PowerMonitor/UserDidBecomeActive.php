<?php

declare(strict_types=1);

namespace Native\Symfony\Event\PowerMonitor;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * The user became active (macOS). Runtime payload: none.
 */
final class UserDidBecomeActive extends Event
{}
