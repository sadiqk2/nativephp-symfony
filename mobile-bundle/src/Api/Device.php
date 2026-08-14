<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Api;

use Native\Symfony\Mobile\Bridge\BridgeInterface;

final class Device
{
    public function __construct(private readonly BridgeInterface $bridge)
    {
    }

    /** @return array<string, mixed> Model, manufacturer, OS version, screen metrics */
    public function info(): array
    {
        return $this->bridge->call('Device.GetInfo') ?? [];
    }

    /**
     * A stable per-install identifier.
     *
     * Not a hardware id on either platform — it resets on reinstall, by design.
     * Do not use it as a licence key or a user identity.
     */
    public function id(): ?string
    {
        $result = $this->bridge->call('Device.GetId');

        return isset($result['id']) ? (string) $result['id'] : null;
    }

    /** @return array<string, mixed> Level, charging state, and where available, health */
    public function batteryInfo(): array
    {
        return $this->bridge->call('Device.GetBatteryInfo') ?? [];
    }

    /** @param int $milliseconds Duration; iOS ignores it and uses a system haptic */
    public function vibrate(int $milliseconds = 250): bool
    {
        return $this->bridge->dispatch('Device.Vibrate', ['duration' => $milliseconds]);
    }

    public function toggleFlashlight(?bool $on = null): bool
    {
        return $this->bridge->dispatch('Device.ToggleFlashlight', null === $on ? [] : ['on' => $on]);
    }
}
