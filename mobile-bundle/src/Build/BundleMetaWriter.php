<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Build;

use Symfony\Component\Filesystem\Filesystem;

/**
 * Writes `bundle_meta.json` — the file both hosts read before PHP exists.
 *
 * Its own class because two commands need it and neither owns it: `native:mobile:manifest`
 * refreshes the route list into an already-built project, and `native:mobile:build` writes
 * it alongside a fresh application archive. Staging has nothing to do with either.
 *
 * The guards are the whole point, and both defend against *silent* device-side failures
 * described in NATIVE-UI-CONTRACT.md §7b:
 *
 *  1. **`version` must be a non-empty JSON string.** `BootPlanner.swift` reads it as
 *     `meta["version"] as? String`, so a JSON number fails the cast and yields `""` — which
 *     is also the value a missing key produces, so an unversioned runtime dump then
 *     compares equal and silently overrides this build's route list. Android is more
 *     forgiving (`JSONObject.optString` coerces a number), which makes this an
 *     iOS-only breakage and therefore the kind that ships.
 *  2. **`native_routes` must always be present.** Android tolerates a missing
 *     `bundle_meta.json` and can boot `NATIVE_DIRECT` from the runtime dump alone; Swift's
 *     `plan()` returns `.webLegacy` immediately. An empty list is a valid answer — it means
 *     "no native screens, use the WebView" — but an absent key is not.
 *
 * Merging rather than overwriting: `version_code`, `runtime_mode` and `bifrost_app_id` may
 * have been written by an upstream build or a previous run, and dropping them changes
 * behaviour the route manifest has no business changing.
 */
final class BundleMetaWriter
{
    private readonly Filesystem $fs;

    public function __construct(?Filesystem $fs = null)
    {
        $this->fs = $fs ?? new Filesystem();
    }

    /**
     * @param array<string, mixed> $fragment {@see \Native\Symfony\Mobile\Ui\Routing\NativeRouteManifest::bundleMetaFragment()}
     * @param array<string, mixed> $extra    Build-owned fields, overridden by $fragment on conflict
     *
     * @return array{written: array<string, mixed>, replaced: list<string>, existed: bool}
     *
     * @throws \RuntimeException when the result would be a manifest the device silently ignores,
     *                          or when the file cannot be written
     */
    public function write(string $path, array $fragment, array $extra = []): array
    {
        $existing = $this->read($path);
        $merged = [...$existing ?? [], ...$extra, ...$fragment];

        $this->assertUsable($merged);

        $this->fs->mkdir(\dirname($path));
        $this->fs->dumpFile($path, json_encode($merged, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n");

        $replaced = array_values(array_filter(
            array_keys([...$extra, ...$fragment]),
            static fn (string $key): bool => null !== $existing
                && \array_key_exists($key, $existing)
                && $existing[$key] !== $merged[$key],
        ));

        return ['written' => $merged, 'replaced' => $replaced, 'existed' => null !== $existing];
    }

    /**
     * Decode an existing manifest, or null when there is none.
     *
     * An unparseable file is treated as absent rather than fatal: it is a build artifact, a
     * half-written one is plausible, and refusing to proceed would leave the project stuck
     * with no way forward but deleting the file by hand.
     *
     * @return array<string, mixed>|null
     */
    public function read(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode((string) file_get_contents($path), true);

        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @throws \RuntimeException
     */
    public function assertUsable(array $meta): void
    {
        if (!isset($meta['version']) || !\is_string($meta['version']) || '' === $meta['version']) {
            throw new \RuntimeException(sprintf(
                'bundle_meta.json needs a non-empty string version (got %s). iOS reads it with `as? String`, so a number compares as "" — which equals the missing-key fallback, and an unversioned runtime route dump then silently overrides this build.',
                get_debug_type($meta['version'] ?? null),
            ));
        }

        if (!\array_key_exists('native_routes', $meta) || !\is_array($meta['native_routes'])) {
            throw new \RuntimeException('bundle_meta.json must always carry a native_routes list, even an empty one: iOS falls straight back to the WebView path when the baked manifest has no route list.');
        }
    }
}
