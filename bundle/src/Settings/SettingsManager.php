<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Settings;

use Native\Symfony\Desktop\Contract\ClientInterface;

/**
 * The runtime's key/value store — 4 endpoints, backed by electron-store (a JSON
 * file under userData). Survives app restarts; unrelated to remembered window
 * geometry, which lives in its own files.
 *
 * Every write fires a SettingChanged event, **including writes made from here**.
 * A listener that writes on change will loop.
 *
 * Dots are object paths, not part of the key. electron-store uses dot-prop, so
 * set('a.b', 1) stores {"a":{"b":1}} — get('a.b') reads it back correctly, but the
 * SettingChanged event reports the *root* key ('a'), because the runtime's store
 * watcher diffs top-level keys only. Observed: setting `diagnostics.ran` produced
 * an event for `diagnostics`. Use a flat separator such as ':' if you need the
 * event key to match the key you wrote.
 */
final class SettingsManager
{
    public function __construct(private readonly ClientInterface $client)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->client->get('settings/'.rawurlencode($key))->value('value');

        return $value ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->client->post('settings/'.rawurlencode($key), ['value' => $value]);
    }

    public function forget(string $key): void
    {
        $this->client->delete('settings/'.rawurlencode($key));
    }

    public function clear(): void
    {
        $this->client->delete('settings');
    }
}
