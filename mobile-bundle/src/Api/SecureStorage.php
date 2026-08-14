<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Api;

use Native\Symfony\Mobile\Bridge\BridgeInterface;

/**
 * The iOS Keychain and the Android Keystore.
 *
 * Values are bound to the app and the device: they survive app updates, and do not
 * survive a reinstall or a move to another device. Suitable for tokens, not for
 * anything the user would be upset to lose.
 */
final class SecureStorage
{
    public function __construct(private readonly BridgeInterface $bridge)
    {
    }

    public function get(string $key): ?string
    {
        $result = $this->bridge->call('SecureStorage.Get', ['key' => $key]);

        if (null === $result) {
            return null;
        }

        // The native side reports absence and failure differently; both mean "no
        // value" to a caller, but only failure is worth a log upstream.
        $value = $result['value'] ?? null;

        return \is_string($value) ? $value : null;
    }

    public function set(string $key, string $value, ?string $accessibility = null): bool
    {
        $payload = ['key' => $key, 'value' => $value];

        if (null !== $accessibility) {
            $payload['accessibility'] = $accessibility;
        }

        $result = $this->bridge->call('SecureStorage.Set', $payload);

        return (bool) ($result['success'] ?? false);
    }

    public function delete(string $key): bool
    {
        $result = $this->bridge->call('SecureStorage.Delete', ['key' => $key]);

        return (bool) ($result['success'] ?? false);
    }

    public function has(string $key): bool
    {
        return null !== $this->get($key);
    }
}
