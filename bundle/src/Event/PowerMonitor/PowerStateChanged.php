<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Event\PowerMonitor;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * The machine moved between mains and battery power.
 *
 * Runtime payload: {state: 'on-ac'|'on-battery'} — named.
 */
final class PowerStateChanged extends Event
{
    public function __construct(
        public readonly string $state,
    ) {
    }

    public function onBattery(): bool
    {
        return 'on-battery' === $this->state;
    }

    public function onMains(): bool
    {
        return 'on-ac' === $this->state;
    }
}
