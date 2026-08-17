# Getting started — desktop

From `composer require` to a Symfony application in a native window.

You need PHP 8.3+, Symfony 7 or 8, Node 20+ (the runtime is an Electron project and is
built with npm), and a working `git`. Nothing about your application changes: it is an
ordinary Symfony app served by PHP's built-in server, and Electron points a `BrowserWindow`
at it.

---

## 1. Install the bundle

```bash
composer require native-symfony/desktop-bundle:^0.1
```

**Not on Packagist yet**, so that resolves only once Composer knows where to find it: clone
this repository and add a path repository pointing at `bundle/`, with `"symlink": true` (a
copy goes stale and edits to the bundle appear to do nothing). The exact block is in the
[root README](../README.md#0-install-the-packages), and [`demo/composer.json`](../demo/composer.json)
is a working example. Everything after this step is unaffected by how the package arrived.

Flex registers the bundle for you: it derives candidate class names from the PSR-4 namespace,
and `Native\Symfony\Desktop\` yields `Native\Symfony\Desktop\NativeDesktopBundle`. That is
what the namespace is shaped for — the bundle used to be `Native\Symfony\`, where the
candidates are `SymfonyBundle` and `NativeSymfonyBundle` and the real class matched neither,
so every application had to register it by hand.

Without Flex, add it yourself:

```php
// config/bundles.php
return [
    // …
    Native\Symfony\Desktop\NativeDesktopBundle::class => ['all' => true],
];
```

## 2. Import the bundle's routes

`native:install` (step 4) writes this file for you, so you can skip ahead — it is documented
here because it is worth knowing what it does and why, and because an application that is not
Flex-shaped has to write it by hand.

Symfony bundles cannot register routes on their own. The runtime posts to two fixed paths,
`/_native/api/booted` and `/_native/api/events`, and until they are imported the app boots
into a window that never opens and receives no events:

```yaml
# config/routes/native_desktop.yaml
native_desktop:
    resource: '@NativeDesktopBundle/src/Resources/config/routes.php'
    type: php
```

The installer only ever creates this file; it will not rewrite one you have edited unless you
pass `--force`, and if there is no `config/routes/` directory it warns rather than writing
somewhere nothing loads.

Verify with `bin/console debug:router | grep _native` — you want
`native_desktop_booted` and `native_desktop_events`.

## 3. Implement `AppBootstrapper`

The runtime finishes its own boot and then POSTs `/_native/api/booted`. Whatever that
handler does *is* your application's startup. Nothing appears on screen unless you ask
for it:

```php
<?php

namespace App\Native;

use Native\Symfony\Desktop\Contract\AppBootstrapper;
use Native\Symfony\Desktop\Window\WindowManager;

final class Bootstrapper implements AppBootstrapper
{
    public function __construct(private readonly WindowManager $windows)
    {
    }

    public function boot(): void
    {
        $this->windows->open('main')
            ->url('/')
            ->size(1000, 720)
            ->title('My App')
            ->rememberState()
            ->open();
    }
}
```

You do not wire anything. The bundle calls `registerForAutoconfiguration()` on
`AppBootstrapper` and a compiler pass aliases the interface to your single implementation.
If you register two, the pass fails at compile time with both class names.

`/booted` can fire more than once — macOS posts it again on `activate` when no windows are
visible — so `boot()` must be idempotent. `window/open` is idempotent by id (an existing id
is shown and focused, nothing is created), so a `boot()` that only opens windows needs no
guard of its own.

If no bootstrapper is registered, `BootedController` logs a warning naming the missing
contract and answers 500. The runtime swallows that response, so the log line is the only
symptom you will get.

## 4. Install the Electron runtime into your project

```bash
git clone --depth 1 https://github.com/NativePHP/desktop /tmp/np-desktop
bin/console native:install --source=/tmp/np-desktop/resources/electron
```

This copies `resources/electron` to `your-project/nativephp/electron`, patches it, installs
the router script into `public/nativephp-router.php`, runs `npm install` (this downloads
Electron — expect minutes), and rebuilds the plugin from the patched TypeScript. Add
`--skip-npm` to copy and patch only, `--force` to overwrite an existing install, `-v` to see
npm's output.

The bundle does not vendor the runtime. Requiring `nativephp/desktop` would pull
`illuminate/contracts`, `laravel/prompts` and `spatie/laravel-package-tools` into a Symfony
application, so you point at a checkout instead.

### Why the runtime needs patching

The runtime resolves the PHP side of the app through ten hardcoded Laravel string
literals in one TypeScript file: the CLI name `artisan` (six call sites), the `php -S`
router script inside `vendor/laravel/framework`, an unconditional copy of a `storage/`
directory, `APP_ENV=local` (which Symfony 8 rejects outright — `getAllowedEnvs()` throws),
`schedule:run`, and the `optimize` and `migrate --force` a packaged app runs at every
launch. Those last two exist in no Symfony application, and their failure is not quiet:
each logs a stack trace on every boot, and because the runtime only records its
`optimized_version` when the call *succeeds*, it retried them forever. They are skipped —
the build already ships a warmed production cache, and Doctrine migrations belong to the
app rather than to the runtime. `RuntimePatcher` rewrites them in **your** copy: upstream's
`native:install --publish` already mirrors the whole Electron project into the app's own
directory and the runtime prefers that copy, so this is a local edit rather than a fork.
The patcher is idempotent and fails loudly when a hunk's target has moved upstream, because
silently skipping one produces an app that serves Laravel's router path and 404s everything.

The permanent fix is a manifest file — see [`native:manifest`](#7-optional-write-a-manifest)
— which is patch `0001` in [`../upstream-patches/`](../upstream-patches/README.md). It is
not merged upstream yet.

### What the bundle fixes in the runtime

`native:install` does not copy the runtime verbatim. Besides rewriting the Laravel-isms, it
carries five bug fixes into your copy:

| Fix | Why it matters here |
|---|---|
| `notifyLaravel` reports failures under `SHELL_VERBOSITY` instead of swallowing them ([#140](https://github.com/NativePHP/desktop/pull/140)) | The single largest cause of "it just does nothing": a crashed, 500ing or 403ing app looked exactly like a healthy one |
| `window/current` 404s instead of dereferencing a null focused window ([#138](https://github.com/NativePHP/desktop/pull/138)) | `getFocusedWindow()` is null whenever the app is in the background — a Messenger worker calling it threw a bare string |
| `window/open` defaults the zoom factor instead of passing `NaN` ([#137](https://github.com/NativePHP/desktop/pull/137)) | Laravel's `Window` always serialises a default, so this never surfaced there; every other client hits it on the first window |
| `shell/trash-item` sends a body with its 400 ([#139](https://github.com/NativePHP/desktop/pull/139)) | `res.json()` with no argument is rejected by express, turning a handled failure into an unhandled one |
| The preload dispatches native events from one listener rather than one per subscription | Past ten `Native.on()` calls Node prints a MaxListenersExceededWarning that reads like a leak. The demo trips it with eleven |

The first four are filed upstream and unreviewed; the fifth was found here. Patching your own
copy is what `--publish` is for, and it means none of this waits on a merge. Every hunk is
non-strict: if a target has moved — most likely because the fix landed upstream — the install
says so and carries on.

### Check it before you run it

```bash
bin/console native:doctor
```

The runtime talks to your app over HTTP, so a call that never lands is logged by Electron and
nowhere you would look. The commonest way to get a dead app is the routes import missing,
which gives a window that opens and then does nothing forever. `native:doctor` asks the router
directly and exits non-zero if the runtime's calls would not land, then reports your
bootstrapper and the inputs `native:build` needs.

The other way — your own firewall covering `/_native/api/` — the bundle handles by default;
see `exempt_runtime_firewall` in [troubleshooting](troubleshooting.md#desktop--boot).

## 5. Run it

```bash
bin/console native:run
```

`native:run` prepares `nativephp/build` with the three things the runtime insists on — an
`icon.png`, a `cacert.pem` and a PHP binary — then runs `npx electron-vite dev` in the
Electron project with `APP_PATH` pointing at your app. Useful options:

| Option | Effect |
|---|---|
| `--php-binary=/path/to/php` | PHP for the runtime to spawn; defaults to the one running the command |
| `--electron-path=…` | Electron project location; defaults to `nativephp/electron` |
| `--no-focus` | Do not steal focus when the app restarts on a file change |
| `-v` | Sets `SHELL_VERBOSITY=1`, which makes the runtime log every PHP command it spawns |

That last one is the single most useful debugging flag in the project.

## 6. Two commands that must print nothing but JSON

Before anything else, the runtime runs `bin/console native:config` and
`bin/console native:php-ini`, captures stdout with `execFile`, and `JSON.parse`s the result.
Both rules that follow are absolute:

- **Nothing but JSON on stdout.** No banner, no `dump()`, no deprecation notice, no
  startup log line. Both commands write with `OutputInterface::OUTPUT_RAW` so no formatter
  interferes on their side — but anything *your* app echoes during console boot lands in the
  same stream and breaks the parse.
- **Never assume `NATIVEPHP_API_URL` or `NATIVEPHP_SECRET` exist.** At this point in the
  boot the runtime's API server does not exist. Any service that calls the runtime from a
  console-boot path will throw `RuntimeNotAvailable` here.

If either command fails, the runtime logs the error and **carries on with an empty config**.
No app id, no deep links, no updater, no visible symptom. Check them by hand whenever
something configured seems to be ignored:

```bash
bin/console native:config   # {"app_id":"com.example.app","version":"1.0.0",…}
bin/console native:php-ini  # {"memory_limit":"512M"}
```

Both should print one line of JSON and nothing else.

## 7. Optional: write a manifest

```bash
bin/console native:manifest            # writes nativephp.json
bin/console native:manifest --dry-run  # print it instead
```

`nativephp.json` declares this app's entry points — CLI, router, docroot, the dev/prod
`APP_ENV` names, the lifecycle commands, the writable directories — so that a
manifest-aware runtime needs no patching at all. The command reports what it wrote and
whether the runtime you have installed will actually read it:

- **Supported** — the runtime reads manifests; `native:install` skips every patch.
- **Absent** — today's upstream runtime. The file is ignored and the patches are what make
  your app work. Harmless to keep.
- **Unknown** — could not tell (half-patched, hand-edited, refactored upstream).
  `native:install` patches to be safe, because guessing "supported" wrongly gives you an app
  that 404s every route with nothing in the log.

Defaults are Symfony's, including `doctrine:migrations:migrate --no-interaction
--allow-no-migration` for the migrate step. If you have no Doctrine Migrations, define the
`Native\Symfony\Desktop\Manifest\Manifest` service yourself with `migrate: null`.

## 8. Configuration

Every key, with its default:

```yaml
# config/packages/native_desktop.yaml
native_desktop:
    app_id: com.example.app     # passed to setAppUserModelId()
    name: App                   # slugged for the package filename
    version: '1.0.0'
    author: ~
    copyright: ~
    description: ~
    website: ~
    deeplink_scheme: ~          # 'myapp', without '://' — enables OpenedFromURL
    base_url: ~                 # fallback for absolute window URLs outside a request
    block_browser_access: true  # leave this on
    exempt_runtime_firewall: true  # keep your access_control off /_native/api/ in the runtime
    testing: false              # true swaps the transport for FakeRuntime — test/ only
    php_ini:
        memory_limit: 512M      # merged over the runtime's defaults, passed as -d flags
    updater:
        enabled: false
        default: github
        providers: {}
    events:
        allowed_namespaces: []  # classes instantiable from a runtime event push
    prebuild: []                # shell commands run before staging
    postbuild: []               # shell commands run after packaging
    nsis:
        delete_app_data_on_uninstall: false
    build:
        exclude: […]            # fnmatch patterns never copied into the build
        keep: ['var/cache', 'var/log']
        env_remove: […]         # .env keys stripped from the staged copy
        env_keep: ['APP_SECRET']  # …and the ones that survive anyway; this list wins
        env_defaults:
            APP_ENV: prod
            APP_DEBUG: '0'
```

Run `bin/console config:dump-reference native_desktop` for the annotated tree.

Three of these are security-relevant and worth reading before you change them:

**`block_browser_access`** registers a subscriber at priority 4096 — above the firewall —
that rejects any request carrying neither the `_php_native` cookie nor the
`X-NativePHP-Secret` header while the app runs inside the runtime. Your app is served on a
real loopback port; that secret is the only thing keeping other local processes out. It
fails *open* when the runtime supplied no secret at all, so that a misconfiguration is
diagnosable rather than a wall of 403s.

**`exempt_runtime_firewall`** decorates Symfony's `security.access_map` so the runtime's two
endpoints carry no access-control attributes — but **only while running inside the runtime**,
where `RuntimeAccessSubscriber` has already checked the shared secret. Deployed as an ordinary
web application the same code keeps your firewall over those paths in full, which matters:
`/_native/api/events` dispatches events by name. Without this an `access_control` rule of `^/`
answers the runtime's `POST /_native/api/booted` with a 401 that the runtime discards, and the
app boots to a window that never does anything. Turn it off only if you write the equivalent
`security: false` firewall yourself.

**`events.allowed_namespaces`** is empty by default and should stay empty unless you
dispatch your own event classes by class name. Upstream's Laravel controller does
`class_exists($event) ? new $event(...$payload)` straight from the request body; here only
the 44 known runtime events and namespaces you list are constructed, and anything else
becomes a `NativeEvent` carrying the payload as data.

## 9. Where to go next

[`../demo/`](../demo/) is a working application using both bundles — bootstrapper,
multi-window inspector, notes over the settings store, a jobs page, a routed menu, and a
native-UI screen. Copy from it rather than from this page where the two differ.

- [Desktop API reference](desktop-api.md) — the manager services, area by area.
- [Recipes](recipes.md) — multi-window apps, Messenger workers, typed listeners, packaging.
- [Testing](testing.md) — `FakeRuntime`, assertions, pushing events without a device.
- [Troubleshooting](troubleshooting.md) — read this the first time something is silently
  wrong, which with this runtime is the usual failure mode.
