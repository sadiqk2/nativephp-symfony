<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Shortcut;

use Native\Symfony\Desktop\Contract\ClientInterface;

/**
 * System-wide keyboard shortcuts — 3 endpoints.
 *
 * The event name is chosen by the caller and pushed back with payload `[key]`, so
 * shortcuts arrive as a NativeEvent under whatever name you register, not as a
 * fixed class. This is the clearest case of that path being load-bearing rather
 * than a fallback.
 *
 * Note the runtime discards globalShortcut.register()'s return value and always
 * answers 200 — so a shortcut another application already owns registers
 * "successfully" and never fires. Confirm with isRegistered() when it matters.
 */
final class GlobalShortcutManager
{
    public function __construct(private readonly ClientInterface $client)
    {
    }

    /**
     * @param string $accelerator An Electron accelerator, e.g. 'CommandOrControl+Shift+K'
     * @param string $event       The event name to dispatch when it fires
     */
    public function register(string $accelerator, string $event): void
    {
        $this->client->post('global-shortcuts', ['key' => $accelerator, 'event' => $event]);
    }

    /**
     * Register and confirm the OS actually granted it.
     *
     * @return bool False when another application already owns the accelerator
     */
    public function registerChecked(string $accelerator, string $event): bool
    {
        $this->register($accelerator, $event);

        return $this->isRegistered($accelerator);
    }

    /** Note: a DELETE carrying a body, which is unusual but is what the runtime wants. */
    public function unregister(string $accelerator): void
    {
        $this->client->delete('global-shortcuts', ['key' => $accelerator]);
    }

    public function isRegistered(string $accelerator): bool
    {
        return (bool) $this->client
            ->get('global-shortcuts/'.rawurlencode($accelerator))
            ->value('isRegistered', false);
    }
}
