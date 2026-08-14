<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Build;

/**
 * The two mobile targets, and the paths the native projects insist on.
 *
 * None of these paths is a preference. The Kotlin and Swift hosts open them by literal
 * name, so getting one wrong produces an app that builds and then fails at boot with
 * nothing useful in the log:
 *
 *  - `laravel_bundle.zip` — `LaravelEnvironment.kt`: `private const val BUNDLE_ZIP = "laravel_bundle.zip"`,
 *    read out of the APK's `assets/`. The name is Laravel's and cannot be changed from the
 *    PHP side; renaming it means patching the Kotlin, which is a bigger change than it is worth.
 *  - `app.zip` under `NativePHP/` on iOS — `AppUpdateManager.swift` reports "No bundled app.zip found".
 *  - `bundle_meta.json` beside the zip on both — `BootPlanner.kt` reads it from `assets/`,
 *    `BootPlanner.swift` from the bundle.
 *
 * The install command copies both projects into the application, so these are paths inside
 * the user's own project, not inside a vendor directory.
 */
enum MobilePlatform: string
{
    case Android = 'android';
    case Ios = 'ios';

    public static function tryFromName(?string $name): ?self
    {
        return self::tryFrom(strtolower(trim((string) $name)));
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_map(static fn (self $p): string => $p->value, self::cases());
    }

    /** Root of the copied native project, as `native:mobile:install` writes it. */
    public function projectPath(string $projectDir): string
    {
        return rtrim($projectDir, '/').'/nativephp/'.$this->value;
    }

    /**
     * The directory that ends up inside the app package, holding the app bundle and its metadata.
     *
     * Android: Gradle packs `app/src/main/assets` into the APK verbatim.
     * iOS: the `NativePHP/` group is copied into the app bundle's resources.
     */
    public function assetsPath(string $projectDir): string
    {
        return $this->projectPath($projectDir).match ($this) {
            self::Android => '/app/src/main/assets',
            self::Ios => '/NativePHP',
        };
    }

    public function bundleZipPath(string $projectDir): string
    {
        return $this->assetsPath($projectDir).'/'.match ($this) {
            self::Android => 'laravel_bundle.zip',
            self::Ios => 'app.zip',
        };
    }

    public function bundleMetaPath(string $projectDir): string
    {
        return $this->assetsPath($projectDir).'/bundle_meta.json';
    }

    /**
     * A file the host reads to decide whether the packaged bundle differs from the
     * extracted one. Android reads `.version` from *inside* the extracted tree (so it is
     * staged into the zip); iOS reads `bundled.version` next to the zip.
     */
    public function bundledVersionPath(string $projectDir): ?string
    {
        return match ($this) {
            self::Android => null,
            self::Ios => $this->assetsPath($projectDir).'/bundled.version',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Android => 'Android',
            self::Ios => 'iOS',
        };
    }
}
