<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Updater;

/**
 * The updater is switched on but cannot be turned into a publish target.
 *
 * Thrown rather than shrugged off, because the alternative is a build that finishes,
 * reports success, publishes nothing, and leaves every installed copy of the app
 * checking an endpoint that will never have a release on it.
 */
final class UpdaterMisconfigured extends \RuntimeException
{
    /** @param list<string> $available */
    public static function noSuchProvider(string $name, array $available): self
    {
        return new self(sprintf(
            'native_desktop.updater is enabled but its default provider "%s" is not configured. %s',
            $name,
            [] === $available
                ? 'No providers are configured at all — add one under native_desktop.updater.providers.'
                : 'Configured providers: '.implode(', ', $available).'.',
        ));
    }

    public static function unsupportedDriver(string $driver, string $name): self
    {
        return new self(sprintf(
            'Updater provider "%s" asks for driver "%s", which electron-builder has no publisher for here. '.
            'Supported drivers are github, s3 and spaces — the same three the Electron runtime ships.',
            $name,
            $driver,
        ));
    }

    /** @param list<string> $keys */
    public static function missingKeys(string $driver, array $keys): self
    {
        return new self(sprintf(
            'The "%s" updater provider is missing %s. electron-builder cannot publish without %s.',
            $driver,
            implode(' and ', array_map(static fn (string $key): string => '"'.$key.'"', $keys)),
            1 === \count($keys) ? 'it' : 'them',
        ));
    }
}
