<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Ui\Routing;

/**
 * Exports the declared patterns in the two shapes the device-side `BootPlanner` reads.
 *
 * This is the whole reason a native route needs to be *declared* rather than just handled:
 * the boot decision happens before PHP exists on that launch. Both platforms read two
 * sources and prefer the fresher one (`BootPlanner.kt` / `BootPlanner.swift`):
 *
 *  1. **Baked** — `bundle_meta.json` in the app assets, written at build time:
 *     `{"version": "…", "entry_mode": "auto"|"web", "native_routes": ["/", "/items/{id}"], …}`
 *  2. **Runtime dump** — `storage/framework/native_routes.json`, rewritten on every PHP boot
 *     so hot-reloading a new screen into the app does not need a rebuild:
 *     `{"version": "…", "routes": ["/", "/items/{id}"]}`
 *
 * Note the key names differ between the two files — `native_routes` baked, `routes` at
 * runtime. That is upstream's inconsistency and both readers depend on it.
 *
 * Three failure modes here are silent, and each one costs a fallback to the WebView rather
 * than an error anybody sees:
 *
 *  - **The versions must be equal as strings.** The runtime dump is used only when its
 *    `version` matches the baked `version`; iOS additionally reads both through
 *    `as? String`, so a JSON *number* version fails the cast, compares as `""`, and the
 *    dump is ignored. Hence {@see version()} is a string and this class refuses an empty one.
 *  - **iOS needs the baked manifest to exist at all.** Android tolerates a missing
 *    `bundle_meta.json` and can boot native off the runtime dump alone; Swift's `plan()`
 *    returns `.webLegacy` immediately. So `native_routes` must always be baked — never rely
 *    on the dump as the only source.
 *  - **The dump's directory may not exist.** Upstream writes into Laravel's
 *    `storage/framework`, which is always present; a Symfony app has no such directory at
 *    that path, and `file_put_contents` into a missing one just fails. {@see writeRuntimeDump()}
 *    creates it and throws on failure rather than leaving the device on a stale baked list.
 */
final class NativeRouteManifest
{
    /**
     * Where the runtime dump has to land, relative to the app's persisted-storage root.
     *
     * Both platforms hard-code this tail (Android under
     * `app_storage/persisted_data/`, iOS under Application Support), so it is not a
     * configurable path — it is part of the contract with the native side.
     */
    public const RUNTIME_DUMP_RELATIVE_PATH = 'storage/framework/native_routes.json';

    /** Key the baked manifest uses for the pattern list. */
    public const BAKED_ROUTES_KEY = 'native_routes';

    /** Key the runtime dump uses for the same list. */
    public const RUNTIME_ROUTES_KEY = 'routes';

    public const ENTRY_MODE_AUTO = 'auto';

    public const ENTRY_MODE_WEB = 'web';

    public function __construct(
        private readonly NativeRouteRegistry $registry,
        private readonly string $version,
    ) {
        // An empty version is not harmless: it compares equal to the default `optString`
        // fallback on both platforms, so an unversioned runtime dump would be preferred over
        // a correctly versioned bake and could downgrade the pattern list on device.
        if ('' === $this->version) {
            throw new \InvalidArgumentException('A native-route manifest needs a non-empty version — it is what pairs the runtime dump with the baked manifest.');
        }
    }

    public function version(): string
    {
        return $this->version;
    }

    /**
     * The runtime dump, written to {@see RUNTIME_DUMP_RELATIVE_PATH} on every boot.
     *
     * @return array{version: string, routes: list<string>}
     */
    public function runtimeDump(): array
    {
        return [
            'version' => $this->version,
            self::RUNTIME_ROUTES_KEY => $this->registry->patterns(),
        ];
    }

    /**
     * The fields the build step merges into `bundle_meta.json`.
     *
     * Only these three: the rest of that file (version_code, runtime_mode, bifrost_app_id)
     * belongs to the build command, which owns the bundle. Returning a fragment rather than
     * the whole file keeps this class out of that decision.
     *
     * `entry_mode` is a build-time escape hatch — only the exact string `web` forces the
     * legacy path, anything else (including a missing key) means "decide from the patterns".
     *
     * @return array{version: string, entry_mode: string, native_routes: list<string>}
     */
    public function bundleMetaFragment(bool $forceWebEntry = false): array
    {
        return [
            'version' => $this->version,
            'entry_mode' => $forceWebEntry ? self::ENTRY_MODE_WEB : self::ENTRY_MODE_AUTO,
            self::BAKED_ROUTES_KEY => $this->registry->patterns(),
        ];
    }

    /**
     * Write the runtime dump under `$storageRoot`, creating its directory.
     *
     * @return string The absolute path written
     *
     * @throws \RuntimeException when the directory cannot be created or the file cannot be written
     */
    public function writeRuntimeDump(string $storageRoot): string
    {
        $path = rtrim($storageRoot, '/').'/'.self::RUNTIME_DUMP_RELATIVE_PATH;
        $dir = \dirname($path);

        if (!is_dir($dir) && !@mkdir($dir, 0o777, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Cannot create native-route manifest directory "%s".', $dir));
        }

        $json = json_encode($this->runtimeDump(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);

        if (false === @file_put_contents($path, $json)) {
            throw new \RuntimeException(sprintf('Cannot write native-route manifest "%s".', $path));
        }

        return $path;
    }

    /**
     * Replay the device's source-selection rule against two already-decoded manifests.
     *
     * Not used on device — it exists so a `doctor`-style command, or a test, can answer
     * "which pattern list would this app actually boot with?" without an emulator. Mirrors
     * `freshestPatterns()`: the runtime dump wins only when its version matches the baked
     * one, otherwise the bake stands; null means no manifest at all, which is WEB_LEGACY.
     *
     * @param array<string, mixed>|null $bundleMeta  Decoded `bundle_meta.json`
     * @param array<string, mixed>|null $runtimeDump Decoded `native_routes.json`
     *
     * @return list<string>|null
     */
    public static function effectivePatterns(?array $bundleMeta, ?array $runtimeDump): ?array
    {
        $baked = $bundleMeta[self::BAKED_ROUTES_KEY] ?? null;
        $baked = \is_array($baked) ? array_values(array_filter($baked, \is_string(...))) : null;

        $bakedVersion = isset($bundleMeta['version']) && \is_string($bundleMeta['version']) ? $bundleMeta['version'] : '';
        $runtimeVersion = isset($runtimeDump['version']) && \is_string($runtimeDump['version']) ? $runtimeDump['version'] : '';

        if (null !== $runtimeDump && $runtimeVersion === $bakedVersion) {
            $routes = $runtimeDump[self::RUNTIME_ROUTES_KEY] ?? null;

            if (\is_array($routes)) {
                return array_values(array_filter($routes, \is_string(...)));
            }
        }

        return $baked;
    }
}
