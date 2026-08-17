<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Manifest;

/**
 * The `nativephp.json` an app writes to tell the Electron runtime where its
 * entry points are.
 *
 * This is the Symfony side of upstream patch 0001 (see
 * ../../../upstream-patches/0001-manifest-declared-app-paths.patch), which replaces the
 * eight hardcoded Laravel literals in `electron-plugin/src/server/php.ts` with
 * values read from this file. Where that patch's defaults are Laravel's, the
 * defaults here are Symfony's — so an app that ships this manifest runs on an
 * *unpatched* runtime, and `RuntimePatcher` becomes unnecessary.
 *
 * The shape is fixed by the patch's `AppManifest` interface and is pinned by
 * ManifestTest, which parses the patch file itself: drift between the two is a
 * test failure rather than a boot failure.
 *
 * ## Why every key is emitted, even the empty ones
 *
 * The runtime merges a declared manifest over its Laravel defaults, one level
 * deep:
 *
 *     {...laravelManifest, ...declared, env: {...}, lifecycle: {...}, cacheEnv: {...}}
 *
 * An *absent* key therefore does not mean "this app has none of that" — it means
 * "use Laravel's". Omitting `seedDir` inherits `storage`; omitting `cacheEnv`
 * inherits the five `APP_*_CACHE` variables. So this writer always emits the full
 * key set, using an explicit empty/null value to mean "none". `ManifestTest`
 * asserts exactly that, comparing our key set against the patch's own default
 * object.
 */
final class Manifest
{
    /**
     * Doctrine Migrations is not part of Symfony, but it is what a Symfony app with
     * a database almost always uses, and `--allow-no-migration` keeps it quiet when
     * there is nothing to apply.
     */
    public const array DOCTRINE_MIGRATE = ['doctrine:migrations:migrate', '--no-interaction', '--allow-no-migration'];

    /**
     * @param string             $cli          CLI entry point relative to the app root. Symfony's is `bin/console`;
     *                                         the runtime also uses this string as the marker that tells it to prepend
     *                                         the bundled phar in a secure build, so it must match argv[0] exactly.
     * @param string             $router       `php -S` router script, relative to the app root. Unlike Laravel, Symfony
     *                                         ships no server router that sets `SCRIPT_FILENAME` the way the runtime
     *                                         needs, so the bundle installs its own into `public/`.
     * @param string             $docroot      cwd for the PHP dev server.
     * @param string             $devEnv       APP_ENV in development. Must be `dev`: Symfony 8 whitelists environment
     *                                         names in Kernel::getAllowedEnvs() and *throws* on Laravel's `local`,
     *                                         which makes this the one manifest key that is a hard boot failure.
     * @param string             $prodEnv      APP_ENV in a packaged build.
     * @param list<string>|null  $optimize     Pre-serve cache priming, or null for none — see toArray() for what null
     *                                         emits and why. `cache:warmup` is Symfony's equivalent of `artisan
     *                                         optimize` and is not merely an optimisation here: BuildCommand excludes
     *                                         `var/cache` from the package, so a freshly installed app has no warmed
     *                                         container at all. Doing it once at boot also surfaces a container
     *                                         compile error in the runtime's log instead of as a 500 on first paint.
     * @param list<string>|null  $migrate      Pre-serve, once per app version. Defaults to Doctrine's command; pass
     *                                         null for an app without Doctrine Migrations. A missing command is not
     *                                         fatal — the runtime logs the non-zero exit and serves anyway — but it
     *                                         does mean a stderr line on every version bump, hence the opt-out.
     * @param list<string>|null  $schedule     Run every 60 seconds, or null for none. Defaults to the bundle's own
     *                                         `native:schedule-tick` rather than symfony/scheduler's `messenger:consume`
     *                                         because the tick has to be a short-lived process, and because an app
     *                                         that defines its own `schedule:run` must not be hijacked by the runtime.
     * @param list<string>       $writableDirs Directories the runtime guarantees exist and are writable. Symfony will
     *                                         not boot without `var/cache` and `var/log`.
     * @param string|null        $seedDir      Directory copied into userData on first run. Symfony has no `storage`
     *                                         equivalent — nothing in a Symfony app root is user data — so this is
     *                                         null, and the null is emitted rather than omitted (see the class
     *                                         docblock: omission would inherit Laravel's `storage`).
     * @param array<string, string> $cacheEnv  Env var name => filename, resolved under userData for secure builds.
     *                                         Empty for Symfony; see toArray() for why that is safe.
     */
    public function __construct(
        public readonly string $cli = 'bin/console',
        public readonly string $router = 'public/nativephp-router.php',
        public readonly string $docroot = 'public',
        public readonly string $devEnv = 'dev',
        public readonly string $prodEnv = 'prod',
        public readonly ?array $optimize = ['cache:warmup'],
        public readonly ?array $migrate = self::DOCTRINE_MIGRATE,
        public readonly ?array $schedule = ['native:schedule-tick'],
        public readonly array $writableDirs = ['var/cache', 'var/log'],
        public readonly ?string $seedDir = null,
        public readonly array $cacheEnv = [],
    ) {
    }

    /**
     * The manifest as the runtime will read it.
     *
     * Three encodings here are deliberate and none of them is interchangeable,
     * because the patched runtime treats the three lifecycle keys differently:
     *
     *  - `optimize` and `migrate` are spread unguarded — `[cli, ...optimize!]` — so
     *    a JSON `null` becomes `...null` and throws inside serveApp, killing the
     *    boot before the PHP server starts. "No command" is therefore an empty
     *    list, which spreads to a bare `bin/console` invocation: it prints the
     *    command list, exits 0, and costs one short process per launch.
     *  - `schedule` *is* guarded (`if (!schedule) return null`), and `[]` is truthy
     *    in JavaScript, so the empty list would spawn a bare `bin/console` every
     *    60 seconds forever. "No command" there must be a JSON `null`.
     *
     * The asymmetry is a wart in patch 0001, not in Symfony; if the patch is
     * amended to guard all three, both cases collapse to `null`.
     *
     * @return array{
     *     cli: string,
     *     router: string,
     *     docroot: string,
     *     env: array{dev: string, prod: string},
     *     lifecycle: array{optimize: list<string>, migrate: list<string>, schedule: list<string>|null},
     *     writableDirs: list<string>,
     *     seedDir: string|null,
     *     cacheEnv: array<string, string>,
     * }
     */
    public function toArray(): array
    {
        return [
            'cli' => $this->cli,
            'router' => $this->router,
            'docroot' => $this->docroot,
            'env' => ['dev' => $this->devEnv, 'prod' => $this->prodEnv],
            'lifecycle' => [
                // Null uniformly for "this framework has no equivalent". An earlier
                // revision of patch 0001 spread optimize and migrate with a non-null
                // assertion, so null crashed the runtime before the PHP server started
                // and they had to be emitted as []. All three are guarded now, so the
                // asymmetry is gone.
                'optimize' => $this->optimize,
                'migrate' => $this->migrate,
                'schedule' => $this->schedule,
            ],
            'writableDirs' => $this->writableDirs,
            'seedDir' => $this->seedDir,
            'cacheEnv' => $this->cacheEnv,
        ];
    }

    /**
     * Pretty-printed JSON, newline-terminated — this file is committed to the
     * app's repository and diffed by humans.
     *
     * `cacheEnv` is forced to a JSON object because PHP cannot tell an empty map
     * from an empty list, and `"cacheEnv": []` would reach
     * `Object.entries(getManifest().cacheEnv)` as an array. That happens to
     * iterate to nothing today, but only by accident; the declared type is
     * `Record<string, string>` and the file should say so.
     *
     * Slashes are left unescaped so `public/nativephp-router.php` is readable.
     */
    public function toJson(): string
    {
        $shape = $this->toArray();
        /** @var array<string, mixed> $encodable */
        $encodable = $shape;
        $encodable['cacheEnv'] = [] === $this->cacheEnv ? new \stdClass() : $this->cacheEnv;

        return json_encode(
            $encodable,
            \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES,
        )."\n";
    }
}
