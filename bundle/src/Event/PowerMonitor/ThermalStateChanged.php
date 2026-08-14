<?php

declare(strict_types=1);

namespace Native\Symfony\Event\PowerMonitor;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Runtime payload: {state} — named. One of unknown|nominal|fair|serious|critical.
 */
final class ThermalStateChanged extends Event
{
    public function __construct(
        public readonly string $state,
    ) {
    }
}
