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
    /**
     * A single haptic buzz.
     *
     * No duration, because neither host has one: Android hardcodes
     * `VibrationEffect.createOneShot(200, DEFAULT_AMPLITUDE)` and iOS plays
     * `kSystemSoundID_Vibrate`, which has no length at all. This used to take
     * milliseconds and send them as `duration` — a parameter both hosts ignore, so the
     * argument was documented, accepted, and silently dropped on the way out.
     */
    public function vibrate(): bool
    {
        return $this->bridge->dispatch('Device.Vibrate');
    }

    /**
     * Flip the torch. There is no way to ask for a particular state: both hosts read no
     * parameters and toggle whatever the torch is currently doing, so a `$on` argument
     * would be accepted and dropped. Read `state` off the reply to learn where it landed.
     */
    public function toggleFlashlight(): bool
    {
        return $this->bridge->dispatch('Device.ToggleFlashlight');
    }
}
