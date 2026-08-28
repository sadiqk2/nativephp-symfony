<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Api;

use Native\Symfony\Mobile\Bridge\BridgeInterface;

final class PushNotifications
{
    /**
     * The event name upstream's enrollment sends. The hosts use it only as a label to
     * echo back, so nothing here has to be able to load it.
     */
    public const TOKEN_GENERATED = 'Native\\Mobile\\Events\\PushNotification\\TokenGenerated';

    public function __construct(private readonly BridgeInterface $bridge)
    {
    }

    /**
     * Ask for permission to send push notifications, and enrol with APNs or FCM.
     *
     * The one call here that prompts the user. Asynchronous: a true means the prompt was
     * requested, and the token arrives later as the event named by $event — which is
     * also the only way to learn it on a first launch, since {@see self::token()} is
     * null until enrolment completes.
     *
     * @param string|null $id Echoed back in the event so a listener can tell which
     *                        enrolment answered. Generated when absent, as upstream
     *                        does, rather than left off the wire
     */
    public function enroll(?string $id = null, string $event = self::TOKEN_GENERATED): bool
    {
        return $this->bridge->dispatch('PushNotification.RequestPermission', [
            'id' => $id ?? bin2hex(random_bytes(8)),
            'event' => $event,
        ]);
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

    /** @return array<string, mixed> Authorisation state, read without prompting — that is enroll() */
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
