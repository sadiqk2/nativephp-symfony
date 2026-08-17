<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Event\PowerMonitor;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * CPU speed limit changed (thermal or power throttling).
 *
 * Runtime payload: {limit} — named.
 */
final class SpeedLimitChanged extends Event
{
    public function __construct(
        public readonly int $limit,
    ) {
    }
}
