<?php

declare(strict_types=1);

namespace Native\Symfony\Event\PowerMonitor;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * The screen was locked. Runtime payload: none.
 */
final class ScreenLocked extends Event
{}
