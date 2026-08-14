<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Runtime;

/**
 * Retargets the hardcoded bootstrap paths in the copied Android and iOS projects.
 *
 * The native hosts execute PHP by absolute path:
 *
 *   PHPBridge.kt      "${getLaravelPath()}/vendor/nativephp/mobile/bootstrap/android/native.php"
 *   NativePHPApp.swift appPath + "/vendor/nativephp/mobile/bootstrap/ios/native.php"
 *
 * Those paths point into another vendor's package, so a Symfony app has to redirect
 * them at its own shim. Exactly the same problem as desktop's hardcoded router
 * script, with the same solution and the same escape hatch: `native:install` copies
 * both projects into the application's own nativephp/android and nativephp/ios, so
 * this patches a local copy and upstream is untouched.
 *
 * Idempotent, and it throws rather than silently skipping — a missed path means the
 * app launches to a blank screen with no diagnostic, which is far worse than a
 * failed install.
 */
final class MobileRuntimePatcher
{
    /** Where our shim lives, relative to the app root. */
    public const SHIM_DIR = 'vendor/native-symfony/mobile-bundle/src/Resources/bootstrap';

    public function __construct(private readonly string $shimDir = self::SHIM_DIR)
    {
    }

    /**
     * @return list<string> What changed, for reporting
     *
     * @throws MobilePatchFailed
     */
    public function patchAndroid(string $projectPath): array
    {
        $bridge = rtrim($projectPath, '/').'/app/src/main/java/com/nativephp/mobile/bridge/PHPBridge.kt';

        return $this->patchFile($bridge, 'android', 'Android PHPBridge.kt');
    }

    /** @return list<string> */
    public function patchIos(string $projectPath): array
    {
        $applied = [];
        $root = rtrim($projectPath, '/').'/NativePHP';

        foreach (['NativePHPApp.swift', 'AppUpdateManager.swift'] as $file) {
            $path = $root.'/'.$file;

            if (!is_file($path)) {
                // AppUpdateManager references mobile-lite and may legitimately be
                // absent depending on the upstream version.
                continue;
            }

            $applied = [...$applied, ...$this->patchFile($path, 'ios', 'iOS '.$file, strict: 'NativePHPApp.swift' === $file)];
        }

        if ([] === $applied) {
            throw MobilePatchFailed::noIosSources($projectPath);
        }

        return $applied;
    }

    /**
     * Force the WebView render path.
     *
     * A Symfony app registers no Route::native patterns, so BootPlanner would fall
     * back to WEB_LEGACY anyway — but relying on a fallback is fragile. Writing
     * entry_mode explicitly makes the intent survive an upstream change to the
     * default.
     */
    public function forceWebEntryMode(string $bundleMetaPath): bool
    {
        if (!is_file($bundleMetaPath)) {
            return false;
        }

        /** @var array<string, mixed> $meta */
        $meta = json_decode((string) file_get_contents($bundleMetaPath), true) ?: [];

        if ('web' === ($meta['entry_mode'] ?? null)) {
            return false;
        }

        $meta['entry_mode'] = 'web';
        $meta['native_routes'] = [];

        file_put_contents($bundleMetaPath, json_encode($meta, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

        return true;
    }

    /** @return list<string> */
    private function patchFile(string $path, string $platform, string $label, bool $strict = true): array
    {
        if (!is_file($path)) {
            if ($strict) {
                throw MobilePatchFailed::missingFile($path);
            }

            return [];
        }

        $original = (string) file_get_contents($path);
        $text = $original;
        $applied = [];

        // Both hosts spell the path the same way inside their own string syntax, so
        // one replacement per script name covers Kotlin and Swift alike.
        foreach (['native.php', 'persistent.php', 'artisan.php'] as $script) {
            $from = "/vendor/nativephp/mobile/bootstrap/{$platform}/{$script}";
            $to = '/'.trim($this->shimDir, '/').'/'.$this->shimName($script);

            if (str_contains($text, $from)) {
                $count = substr_count($text, $from);
                $text = str_replace($from, $to, $text);
                $applied[] = sprintf('%s: %s → %s%s', $label, $script, $this->shimName($script), $count > 1 ? " ({$count} sites)" : '');

                continue;
            }

            if (str_contains($text, $to)) {
                $applied[] = sprintf('%s: %s — already applied', $label, $script);
            }

            // mobile-lite paths and absent scripts are not errors: not every host
            // file references all three.
        }

        if ([] === $applied && $strict) {
            throw MobilePatchFailed::hunkDidNotMatch($label, $path);
        }

        if ($text !== $original) {
            file_put_contents($path, $text);
        }

        return $applied;
    }

    /**
     * Our shim has no `artisan.php`: the console entry point is bin/console, and a
     * Symfony app has no artisan. Map it onto the console shim instead so a native
     * call for it does not resolve to a missing file.
     */
    private function shimName(string $script): string
    {
        return match ($script) {
            'artisan.php' => 'console.php',
            default => $script,
        };
    }
}
