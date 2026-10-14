<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Api;

use Native\Symfony\Mobile\Bridge\BridgeInterface;

final class System
{
    public function __construct(private readonly BridgeInterface $bridge)
    {
    }

    /** 'light' or 'dark'. Falls back to light when the bridge is unavailable. */
    public function appearance(): string
    {
        $result = $this->bridge->call('System.GetAppearance');

        return 'dark' === ($result['appearance'] ?? null) ? 'dark' : 'light';
    }

    public function isDarkMode(): bool
    {
        return 'dark' === $this->appearance();
    }

    /** Send the app to the background. Android honours this; iOS forbids it. */
    public function minimize(): bool
    {
        return $this->bridge->dispatch('System.MinimizeApp');
    }

    /** Open this app's page in the OS settings — the only route to a denied permission. */
    public function openAppSettings(): bool
    {
        return $this->bridge->dispatch('System.OpenAppSettings');
    }

    /** @param string $color A hex colour for the window background behind the WebView */
    public function setBackground(string $color): bool
    {
        return $this->bridge->dispatch('UI.SetBackground', ['color' => $color]);
    }

    /** 'portrait' or 'landscape'. Falls back to portrait when unknown or unavailable. */
    public function orientation(): string
    {
        $result = $this->bridge->call('System.GetOrientation');

        return \in_array($result['orientation'] ?? null, ['portrait', 'landscape'], true)
            ? $result['orientation']
            : 'portrait';
    }

    public function isPortrait(): bool
    {
        return 'portrait' === $this->orientation();
    }

    public function isLandscape(): bool
    {
        return 'landscape' === $this->orientation();
    }
}
