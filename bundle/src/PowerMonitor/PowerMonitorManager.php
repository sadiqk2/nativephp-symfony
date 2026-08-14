<?php

declare(strict_types=1);

namespace Native\Symfony\PowerMonitor;

use Native\Symfony\Contract\ClientInterface;
use Native\Symfony\Enums\IdleState;
use Native\Symfony\Enums\ThermalState;

/**
 * Power and idle state — 4 endpoints.
 *
 * The 8 power events flow whether or not these are ever called: the runtime
 * registers its listeners at module import time, not on demand.
 */
final class PowerMonitorManager
{
    public function __construct(private readonly ClientInterface $client)
    {
    }

    /** @param int $threshold Seconds of inactivity before the state counts as idle */
    public function idleState(int $threshold = 60): IdleState
    {
        $state = (string) $this->client
            ->get('power-monitor/get-system-idle-state', ['threshold' => $threshold])
            ->value('result', 'unknown');

        return IdleState::tryFrom($state) ?? IdleState::Unknown;
    }

    /** Seconds since the last user input. */
    public function idleTime(): int
    {
        return (int) $this->client->get('power-monitor/get-system-idle-time')->value('result', 0);
    }

    public function thermalState(): ThermalState
    {
        $state = (string) $this->client
            ->get('power-monitor/get-current-thermal-state')
            ->value('result', 'unknown');

        return ThermalState::tryFrom($state) ?? ThermalState::Unknown;
    }

    public function onBatteryPower(): bool
    {
        return (bool) $this->client->get('power-monitor/is-on-battery-power')->value('result', false);
    }
}
