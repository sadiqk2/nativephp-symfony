<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Api;

use Native\Symfony\Mobile\Bridge\BridgeInterface;

final class PushNotifications
{
    public function __construct(private readonly BridgeInterface $bridge)
    {
    }

    /**
     * The APNs or FCM token for this install.
     *
     * Null before the user grants permission, and it rotates — re-read it on every
     * launch rather than storing it once.
     */
    public function token(): ?string
    {
        $result = $this->bridge->call('PushNotification.GetToken');
        $token = $result['token'] ?? null;

        return \is_string($token) && '' !== $token ? $token : null;
    }

    /** @return array<string, mixed> Authorisation state; may prompt the user on first call */
    public function checkPermission(): array
    {
        return $this->bridge->call('PushNotification.CheckPermission') ?? [];
    }

    public function isAuthorised(): bool
    {
        $permission = $this->checkPermission();

        return (bool) ($permission['granted'] ?? $permission['authorized'] ?? false);
    }

    public function clearBadge(): bool
    {
        return $this->bridge->dispatch('PushNotification.ClearBadge');
    }
}
