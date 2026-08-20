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
 * Retargeting those paths is necessary and not sufficient. In persistent mode the
 * hosts never execute a file per request at all: `php_bridge.c` and `PHP.c` build the
 * per-request PHP as a C string literal and hand it to `zend_eval_string`, and that
 * literal names Laravel's classes —
 *
 *   $__response = \Native\Mobile\Runtime::dispatch(\Illuminate\Http\Request::capture());
 *
 * — as do the boot check (`class_exists('Native\Mobile\Runtime') && ::isBooted()`),
 * the console entry point (`::artisan()`) and the teardown (`::shutdown()`). None of
 * those classes exist in a Symfony application, so the boot check fails, the host tears
 * the interpreter down, and any request that got past it 500s. The C sources are copied
 * into the application and compiled there, so they are patched here too — onto
 * {@see MobileRuntime}, whose static entry points exist for exactly this.
 *
 * Idempotent, and it throws rather than silently skipping — a missed path means the
 * app launches to a blank screen with no diagnostic, which is far worse than a
 * failed install.
 */
final class MobileRuntimePatcher
{
    /** Where our shim lives, relative to the app root. */
    public const SHIM_DIR = 'vendor/native-symfony/mobile-bundle/src/Resources/bootstrap';

    /**
     * The PHP the hosts evaluate, and what it has to become.
     *
     * Written as it appears in the C sources, where a PHP namespace separator is an
     * escaped backslash. The quoted form on its own line is the one inside
     * `class_exists('...')`, which escapes twice over.
     *
     * @var array<string, string>
     */
    private const HOST_EVAL_REPLACEMENTS = [
        // Request::capture() reads the superglobals the host's own preamble filled in;
        // this reads the same ones, through the factory the shims use.
        '\\\\Illuminate\\\\Http\\\\Request::capture()' => '\\\\Native\\\\Symfony\\\\Mobile\\\\Runtime\\\\ServerRequestFactory::fromServer($_SERVER)[0]',
        "'Native\\\\\\\\Mobile\\\\\\\\Runtime'" => "'Native\\\\\\\\Symfony\\\\\\\\Mobile\\\\\\\\Runtime\\\\\\\\MobileRuntime'",
        '\\\\Native\\\\Mobile\\\\Runtime' => '\\\\Native\\\\Symfony\\\\Mobile\\\\Runtime\\\\MobileRuntime',
    ];

    /** The C sources carrying those literals, relative to each copied project. */
    public const HOST_EVAL_SOURCES = [
        'android' => ['app/src/main/cpp/php_bridge.c', 'app/src/main/cpp/PHP.c'],
        'ios' => ['Include/Bridge/PHP.c'],
    ];

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

        return [
            ...$this->patchFile($bridge, 'android', 'Android PHPBridge.kt'),
            ...$this->patchHostEvaluations($projectPath, 'android'),
        ];
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

        return [...$applied, ...$this->patchHostEvaluations($projectPath, 'ios')];
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

        $decoded = json_decode((string) file_get_contents($bundleMetaPath), true);

        // `?: []` here would turn a malformed or truncated manifest into an empty one and
        // then write it back, silently dropping the version and bundle identity the hosts
        // compare to decide whether to re-extract — a device left running the previous
        // build with nothing to explain it. A scalar was worse still: assigning an offset
        // to one is a TypeError mid-install.
        if (!\is_array($decoded)) {
            throw MobilePatchFailed::unreadableBundleMeta($bundleMetaPath);
        }

        /** @var array<string, mixed> $meta */
        $meta = $decoded;

        if ('web' === ($meta['entry_mode'] ?? null)) {
            return false;
        }

        $meta['entry_mode'] = 'web';
        $meta['native_routes'] = [];

        // THROW_ON_ERROR because json_encode returns false on failure, and writing false
        // truncates the manifest to nothing — the one outcome worse than not writing.
        $json = json_encode($meta, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);

        if (false === @file_put_contents($bundleMetaPath, $json)) {
            throw MobilePatchFailed::writeFailed($bundleMetaPath);
        }

        return true;
    }

    /**
     * Whether an installed project's compiled-in PHP has been retargeted.
     *
     * Reported by `native:mobile:doctor`, because this is the failure that looks like
     * nothing: the bootstrap paths can be perfectly patched and the app still dies on
     * every request, since in persistent mode the hosts evaluate their own literal
     * instead of running a file.
     *
     * @return bool|null null when the project has none of these sources to check
     */
    public function hostEvaluationsRetargeted(string $projectPath, string $platform): ?bool
    {
        $seen = false;

        foreach (self::HOST_EVAL_SOURCES[$platform] ?? [] as $relative) {
            $path = rtrim($projectPath, '/').'/'.$relative;

            if (!is_file($path)) {
                continue;
            }

            $seen = true;
            $source = (string) file_get_contents($path);

            if (str_contains($source, '\\\\Native\\\\Mobile\\\\Runtime')
                || str_contains($source, "'Native\\\\\\\\Mobile\\\\\\\\Runtime'")
                || str_contains($source, '\\\\Illuminate\\\\')
            ) {
                return false;
            }
        }

        return $seen ? true : null;
    }

    /**
     * Rewrite the PHP the host compiles into itself.
     *
     * A plain substitution, deliberately: the literals are split across C string
     * fragments and reflowed differently in each host, so anchoring on a block would
     * break on the next upstream reindent, while the class names are stable and appear
     * nowhere else in these files. What is checked afterwards is the outcome rather
     * than the edit — no reference to the Laravel runtime may survive in a file that
     * had one, because a single missed site is a screen that 500s on a device.
     *
     * Not strict about the files themselves: `PHP.c` on Android carries none of these
     * literals today, and an upstream version that drops one of the call sites is not
     * a reason to fail an install.
     *
     * @return list<string>
     */
    private function patchHostEvaluations(string $projectPath, string $platform): array
    {
        $applied = [];

        foreach (self::HOST_EVAL_SOURCES[$platform] as $relative) {
            $path = rtrim($projectPath, '/').'/'.$relative;

            if (!is_file($path)) {
                continue;
            }

            $original = (string) file_get_contents($path);
            $text = $original;
            $sites = 0;

            foreach (self::HOST_EVAL_REPLACEMENTS as $from => $to) {
                $count = substr_count($text, $from);

                if ($count > 0) {
                    $text = str_replace($from, $to, $text);
                    $sites += $count;
                }
            }

            if (0 === $sites) {
                if (str_contains($original, 'MobileRuntime::dispatch')) {
                    $applied[] = sprintf('%s %s: host dispatch — already applied', $platform, basename($path));
                }

                continue;
            }

            // The escaped forms only: an ordinary C comment mentioning the class — and
            // there is one, explaining what a failed boot looks like — is not a call site.
            if (str_contains($text, '\\\\Native\\\\Mobile\\\\Runtime')
                || str_contains($text, "'Native\\\\\\\\Mobile\\\\\\\\Runtime'")
                || str_contains($text, '\\\\Illuminate\\\\')
            ) {
                throw MobilePatchFailed::laravelEvalSurvived($path);
            }

            if (false === @file_put_contents($path, $text)) {
                throw MobilePatchFailed::writeFailed($path);
            }

            $applied[] = sprintf('%s %s: host eval → MobileRuntime (%d sites)', $platform, basename($path), $sites);
        }

        return $applied;
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

        // Checked, because this class promises to throw rather than skip and an ignored
        // write is a skip with a success message attached.
        if ($text !== $original && false === @file_put_contents($path, $text)) {
            throw MobilePatchFailed::writeFailed($path);
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
