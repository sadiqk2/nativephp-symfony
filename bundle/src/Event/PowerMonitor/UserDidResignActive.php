<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Event\PowerMonitor;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * The user became inactive (macOS). Runtime payload: none.
 */
final class UserDidResignActive extends Event
{}
