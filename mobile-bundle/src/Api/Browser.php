<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Api;

use Native\Symfony\Mobile\Bridge\BridgeInterface;

final class Browser
{
    public function __construct(private readonly BridgeInterface $bridge)
    {
    }

    /** Hand the URL to the OS — leaves the app. */
    public function open(string $url): bool
    {
        return $this->bridge->dispatch('Browser.Open', ['url' => $url]);
    }

    /** An in-app browser (SFSafariViewController / Custom Tabs) — the app stays open. */
    public function openInApp(string $url): bool
    {
        return $this->bridge->dispatch('Browser.OpenInApp', ['url' => $url]);
    }

    /**
     * An authentication session (ASWebAuthenticationSession / Custom Tabs auth).
     *
     * Shares cookies with the system browser, which is what makes an existing SSO
     * session usable — the in-app browser deliberately does not.
     */
    public function openAuth(string $url, ?string $callbackScheme = null): bool
    {
        $payload = ['url' => $url];

        if (null !== $callbackScheme) {
            $payload['callbackScheme'] = $callbackScheme;
        }

        return $this->bridge->dispatch('Browser.OpenAuth', $payload);
    }
}
