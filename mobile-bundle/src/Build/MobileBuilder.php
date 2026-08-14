<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Build;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

/**
 * Stages a Symfony application into the shape the mobile hosts unpack at boot.
 *
 * Mobile differs from desktop in what "packaging" means. There is no electron-builder and
 * no `extraResources`: the whole application is zipped into one archive that ships inside
 * the APK's `assets/` or the iOS bundle, and the host extracts it on first launch
 * (`LaravelEnvironment.kt`, `AppUpdateManager.swift`). So the pipeline is the desktop one
 * minus the packager and plus a zip — and the parts that were hard-won on desktop carry
 * over unchanged:
 *
 *  - **`env_keep` beats `env_remove`.** Laravel's own cleanup list globs `*_SECRET`, which
 *    is harmless there (its key is `APP_KEY`) and fatal here: it matches Symfony's
 *    `APP_SECRET`, which `framework.yaml` reads as `%env(APP_SECRET)%`, so the app fails at
 *    container build with `EnvNotFoundException` — inside the packaged app, where nobody
 *    sees it. See M3-RESULTS.md finding 1. The keep list exists so that a user adding
 *    `*_SECRET` themselves, which is a reasonable thing to want, cannot break their app.
 *  - **Excluded directories are never descended into**, rather than copied and deleted.
 *  - **The developer's own `.env` is never touched** — only the staged copy.
 *
 * One mobile-specific addition: the staged `.env` must carry `NATIVEPHP_APP_VERSION` *and*
 * `NATIVEPHP_APP_VERSION_CODE`. Both hosts build a composite `"{version}b{code}"` identity
 * and re-extract the bundle whenever it differs from what is already extracted; iOS reads
 * that pair out of `.env` in preference to anything else (`AppUpdateManager.getVersionFromZip`),
 * and a missing code yields `…b0` against the metadata's `…b1`, so the app re-extracts
 * several hundred megabytes on *every* cold boot. That is the same trap Android's own
 * comment describes.
 *
 * **Verification status.** Staging, `.env` cleaning, the version files, the zip and the
 * metadata merge are all covered by tests and run in this environment. Nothing downstream
 * of them is: no APK has been assembled, no archive exported, and no packaged bundle has
 * been extracted by a host, because there is no Android SDK and no Xcode here. Whether a
 * device accepts these artifacts is untested. {@see Toolchain}, {@see BuildPlan}.
 */
final class MobileBuilder
{
    /**
     * fnmatch patterns, relative to the project root, never staged.
     *
     * Inherited from the desktop defaults, with mobile's own additions: `nativephp` holds
     * the native projects themselves (staging them would nest the build inside its own
     * output), and `public/build`/`node_modules` are front-end assets that the WebView
     * serves from the compiled output rather than the sources.
     */
    public const DEFAULT_EXCLUDE = [
        '.git', '.github', '.idea', '.vscode', '.gitignore', '.editorconfig',
        'nativephp', 'node_modules', 'var/cache', 'var/log',
        'tests', 'phpunit.xml', 'phpunit.xml.dist', '.env.test',
        '.php-cs-fixer*', 'phpstan*', 'rector.php',
        'auth.json', '.env.local', '.env.*.local',
        '*.sqlite', '*.sqlite-shm', '*.sqlite-wal',
    ];

    /** Directories Symfony will not boot without, recreated in the staged copy. */
    public const DEFAULT_KEEP = ['var/cache', 'var/log'];

    /**
     * `.env` keys stripped from the staged copy.
     *
     * Named prefixes, never `*_SECRET` or `*_KEY`. Those globs are Laravel's and they take
     * `APP_SECRET` with them.
     */
    public const DEFAULT_ENV_REMOVE = [
        'AWS_*', 'AZURE_*', 'GITHUB_*', 'DO_SPACES_*',
        'BIFROST_*', 'NATIVEPHP_APPLE_*', 'NATIVEPHP_AZURE_*',
        'STRIPE_*', 'MAILER_DSN', 'SENTRY_*',
    ];

    /** Keys that survive whatever `envRemove` says. This list wins. */
    public const DEFAULT_ENV_KEEP = ['APP_SECRET'];

    /** The `.version` marker's value for a build that should always re-extract. */
    public const DEBUG_VERSION = 'DEBUG';

    private readonly Filesystem $fs;

    /** @var list<string> */
    private readonly array $excludePatterns;

    /**
     * @param string                $sourcePath      The application root
     * @param string                $stagePath       Where the copy is assembled; excluded from its own staging
     *                                               automatically when it sits inside $sourcePath
     * @param list<string>          $excludePatterns fnmatch patterns relative to $sourcePath
     * @param list<string>          $keepDirectories directories that must exist in the bundle
     * @param array<string, string> $envDefaults     `.env` values forced in the staged copy
     * @param list<string>          $envRemove       `.env` keys stripped (fnmatch)
     * @param list<string>          $envKeep         `.env` keys never stripped (fnmatch); wins over $envRemove
     */
    public function __construct(
        private readonly string $sourcePath,
        private readonly string $stagePath,
        array $excludePatterns = self::DEFAULT_EXCLUDE,
        private readonly array $keepDirectories = self::DEFAULT_KEEP,
        private readonly array $envDefaults = ['APP_ENV' => 'prod', 'APP_DEBUG' => '0'],
        private readonly array $envRemove = self::DEFAULT_ENV_REMOVE,
        private readonly array $envKeep = self::DEFAULT_ENV_KEEP,
    ) {
        $this->fs = new Filesystem();

        // The stage directory conventionally lives under var/, which is inside the source
        // tree. Copying it into itself is an unbounded recursion that only shows up on a
        // second build, when the directory is no longer empty — so exclude it by path
        // rather than trusting the caller to remember.
        $nested = $this->relativeToSource($stagePath);
        $this->excludePatterns = null === $nested ? $excludePatterns : [...$excludePatterns, $nested, $nested.'/*'];
    }

    public function sourcePath(string $path = ''): string
    {
        return '' === $path ? $this->sourcePath : Path::join($this->sourcePath, $path);
    }

    /** The staged application root — the directory that becomes the zip. */
    public function stagePath(string $path = ''): string
    {
        return '' === $path ? $this->stagePath : Path::join($this->stagePath, $path);
    }

    /** @return list<string> */
    public function excludePatterns(): array
    {
        return $this->excludePatterns;
    }

    /**
     * Copy the application into the stage directory, skipping excluded paths.
     *
     * @param (callable(int): void)|null $onProgress Called every 500 files
     *
     * @return int Files copied
     */
    public function stageApplication(?callable $onProgress = null): int
    {
        $this->fs->remove($this->stagePath());
        $this->fs->mkdir($this->stagePath());

        $copied = 0;

        $directories = new \RecursiveDirectoryIterator(
            $this->sourcePath(),
            \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS,
        );

        $filtered = new \RecursiveCallbackFilterIterator(
            $directories,
            fn (\SplFileInfo $current): bool => !$this->isExcluded($this->relative($current->getPathname())),
        );

        foreach (new \RecursiveIteratorIterator($filtered, \RecursiveIteratorIterator::SELF_FIRST) as $item) {
            /** @var \SplFileInfo $item */
            $target = Path::join($this->stagePath(), $this->relative($item->getPathname()));

            if ($item->isDir()) {
                $this->fs->mkdir($target);

                continue;
            }

            $this->fs->copy($item->getPathname(), $target, true);

            // bin/console has to stay runnable: the hosts invoke it through the console shim.
            if ('Windows' !== \PHP_OS_FAMILY) {
                $this->fs->chmod($target, fileperms($item->getPathname()) & 0o777);
            }

            ++$copied;

            if (null !== $onProgress && 0 === $copied % 500) {
                $onProgress($copied);
            }
        }

        $this->keepRequiredDirectories();

        return $copied;
    }

    /**
     * A zip stores no empty directories usefully and the hosts do not create them, so each
     * required directory gets a placeholder file to carry it through the archive.
     */
    public function keepRequiredDirectories(): void
    {
        foreach ($this->keepDirectories as $directory) {
            $this->fs->dumpFile(Path::join($this->stagePath(), $directory, '.nativephp-keep'), '');
        }
    }

    /**
     * Strip secrets from the staged `.env`, force production values, and stamp the version.
     *
     * @param string     $version     Non-empty; also written into the metadata
     * @param int|string $versionCode Build number. Written even though it is redundant with
     *                                `.version`, because iOS prefers the `.env` pair.
     */
    public function cleanEnvironmentFile(string $version, int|string $versionCode = 1): void
    {
        $envPath = $this->stagePath('.env');

        $defaults = [
            ...$this->envDefaults,
            'NATIVEPHP_APP_VERSION' => $version,
            'NATIVEPHP_APP_VERSION_CODE' => (string) $versionCode,
        ];

        $kept = [];

        foreach (is_file($envPath) ? file($envPath, \FILE_IGNORE_NEW_LINES) ?: [] : [] as $line) {
            $trimmed = trim($line);

            if ('' === $trimmed || str_starts_with($trimmed, '#')) {
                continue;
            }

            $key = strstr($trimmed, '=', true);

            if (false === $key) {
                continue;
            }

            // The keep list is checked first and wins. See the class docblock: this is the
            // difference between a packaged app that boots and one that does not.
            if ($this->matchesAny($key, $this->envKeep)) {
                $kept[] = $trimmed;

                continue;
            }

            if ($this->matchesAny($key, $this->envRemove) || \array_key_exists($key, $defaults)) {
                continue;
            }

            $kept[] = $trimmed;
        }

        foreach ($defaults as $key => $value) {
            $kept[] = "{$key}={$value}";
        }

        $this->fs->dumpFile($envPath, implode("\n", $kept)."\n");
    }

    /**
     * The composite identity both hosts compare against what they have already extracted.
     *
     * `DEBUG` is special-cased by upstream on both platforms: it means "always re-extract",
     * which is what a development build wants.
     */
    public static function versionId(string $version, int|string $versionCode = 1): string
    {
        return self::DEBUG_VERSION === $version ? self::DEBUG_VERSION : sprintf('%sb%s', $version, $versionCode);
    }

    /** Write `.version` inside the staged tree, where Android's extractor reads it. */
    public function writeVersionFile(string $version, int|string $versionCode = 1): string
    {
        $path = $this->stagePath('.version');

        $this->fs->dumpFile($path, self::versionId($version, $versionCode)."\n");

        return $path;
    }

    /**
     * The CA bundle. Without it the app has no working outbound TLS.
     *
     * Unlike desktop, the mobile PHP is compiled into the host and its ini is not ours to
     * set, so this is staged into the app tree for the application to point at
     * (`curl.cainfo` / `openssl.cafile` via `ini_set`, or a client option). It is *not*
     * automatically wired up — that claim would need a device to verify.
     */
    public function installCertificateAuthority(?string $source = null): bool
    {
        $source ??= $this->sourcePath('vendor/nativephp/php-bin/cacert.pem');

        if (!is_file($source)) {
            return false;
        }

        $this->fs->copy($source, $this->stagePath('cacert.pem'), true);

        return true;
    }

    /**
     * Zip the staged tree to `$zipPath`, replacing anything there.
     *
     * Paths inside the archive are relative to the stage root, because that is what the
     * hosts extract into their app directory: an extra leading directory strands the whole
     * application one level too deep, and the symptom on device is a blank screen.
     *
     * @return int Entries written
     *
     * @throws \RuntimeException when ext-zip is missing or the archive cannot be written
     */
    public function createBundleArchive(string $zipPath): int
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('Building a mobile bundle needs ext-zip; the application archive is a zip both hosts extract at boot.');
        }

        $this->fs->mkdir(\dirname($zipPath));
        $this->fs->remove($zipPath);

        $zip = new \ZipArchive();

        if (true !== $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
            throw new \RuntimeException(sprintf('Cannot create the application archive "%s".', $zipPath));
        }

        $entries = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->stagePath(), \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $local = str_replace('\\', '/', substr($item->getPathname(), \strlen($this->stagePath()) + 1));

            if ($item->isDir()) {
                $zip->addEmptyDir($local);

                continue;
            }

            $zip->addFile($item->getPathname(), $local);
            ++$entries;
        }

        if (!$zip->close()) {
            throw new \RuntimeException(sprintf('Cannot finalise the application archive "%s".', $zipPath));
        }

        return $entries;
    }

    /**
     * Merge a manifest fragment into `bundle_meta.json`. {@see BundleMetaWriter} for the
     * two guards that make this more than a file write.
     *
     * @param array<string, mixed> $fragment
     * @param array<string, mixed> $extra
     *
     * @return array{written: array<string, mixed>, replaced: list<string>, existed: bool}
     *
     * @throws \RuntimeException when the result would be a manifest the device silently ignores
     */
    public function writeBundleMeta(string $path, array $fragment, array $extra = []): array
    {
        return (new BundleMetaWriter($this->fs))->write($path, $fragment, $extra);
    }

    /**
     * `sdk.dir` for Gradle, which does not read ANDROID_HOME from the environment reliably.
     *
     * Upstream writes the same file. Untested here: no SDK exists to point at.
     */
    public function writeAndroidLocalProperties(string $projectDir, string $sdkPath): string
    {
        $path = MobilePlatform::Android->projectPath($projectDir).'/local.properties';

        // Gradle's properties format treats a backslash as an escape, so a Windows path has
        // to be written with forward slashes.
        $this->fs->dumpFile($path, 'sdk.dir='.str_replace('\\', '/', $sdkPath)."\n");

        return $path;
    }

    /**
     * A minimal `exportOptions.plist` for `xcodebuild -exportArchive`.
     *
     * `development` only, with automatic signing. Anything else (`app-store`,
     * `ad-hoc`, a named provisioning profile) needs a real Apple Developer account and a
     * keychain identity, neither of which can be exercised here — writing that flow blind
     * would be guesswork dressed as support. Point `--export-options` at your own plist for
     * a distribution build.
     */
    public function writeExportOptions(string $path, string $method = 'development', ?string $teamId = null): string
    {
        $entries = ['method' => $method, 'signingStyle' => 'automatic'];

        if (null !== $teamId) {
            $entries['teamID'] = $teamId;
        }

        $body = '';

        foreach ($entries as $key => $value) {
            $body .= sprintf("    <key>%s</key>\n    <string>%s</string>\n", $key, htmlspecialchars($value, \ENT_XML1));
        }

        $plist = <<<PLIST
            <?xml version="1.0" encoding="UTF-8"?>
            <!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
            <plist version="1.0">
            <dict>
            {$body}</dict>
            </plist>

            PLIST;

        $this->fs->dumpFile($path, $plist);

        return $path;
    }

    /**
     * `composer install --no-dev` in the staged copy, so the developer's own vendor/ is
     * untouched.
     *
     * Returned as a plan rather than executed, so the caller streams it and so the
     * decision is visible under `--dry-run`.
     */
    public function productionDependenciesCommand(): PlannedCommand
    {
        return new PlannedCommand(
            ['composer', 'install', '--no-dev', '--no-interaction', '--optimize-autoloader', '--no-progress'],
            $this->stagePath(),
            'Reinstall dependencies without dev packages',
            timeout: 900,
        );
    }

    /**
     * Warm the production cache in the staged copy.
     *
     * Worth more on mobile than on desktop: the packaged PHP cannot load opcache
     * (M3-RESULTS.md finding 2 — the static build refuses dynamic extensions), so every
     * request in a shipped app pays full compile cost and an unwarmed container is paid for
     * on the very first screen.
     */
    public function cacheWarmupCommand(): PlannedCommand
    {
        return new PlannedCommand(
            ['php', 'bin/console', 'cache:warmup', '--env=prod', '--no-debug'],
            $this->stagePath(),
            'Warm the production cache',
            timeout: 600,
        );
    }

    private function relative(string $absolute): string
    {
        return str_replace('\\', '/', substr($absolute, \strlen($this->sourcePath()) + 1));
    }

    /** The stage path expressed relative to the source, or null when it is outside it. */
    private function relativeToSource(string $path): ?string
    {
        $source = rtrim(str_replace('\\', '/', $this->sourcePath), '/').'/';
        $candidate = str_replace('\\', '/', $path);

        return str_starts_with($candidate, $source) ? trim(substr($candidate, \strlen($source)), '/') : null;
    }

    private function isExcluded(string $relativePath): bool
    {
        return $this->matchesAny($relativePath, $this->excludePatterns);
    }

    /** @param list<string> $patterns */
    private function matchesAny(string $subject, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $subject)) {
                return true;
            }
        }

        return false;
    }
}
