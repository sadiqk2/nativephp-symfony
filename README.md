# NativePHP for Symfony

Bringing [NativePHP](https://nativephp.com)'s desktop runtime to Symfony: build desktop
applications with Symfony and PHP, on the same Electron runtime the Laravel version uses.

Working, and proven end-to-end — a Symfony 8 app in a native window, driving the runtime's
full API, packaged into a distributable app that has been built *and run*.

![The packaged app](spike/shot-packaged.png)

## Where things are

| | |
|---|---|
| **[`bundle/`](bundle/README.md)** | `native-symfony/desktop-bundle` — desktop. All 116 runtime endpoints, all 44 events, 394 tests. Verified by a running packaged app. |
| **[`mobile-bundle/`](mobile-bundle/README.md)** | `native-symfony/mobile-bundle` — iOS and Android. All 54 bridge methods, the SAPI shim, the persistent runtime, and the full native-UI path — element trees, style parser, routing and components — byte-identical to upstream. 384 tests. Verified by tests, **not** by a device. |
| [`spike/`](spike/README.md) | The reproduction harness: a container with PHP 8.4 + Node 22 + Electron, the runtime patch, and headless runners that screenshot the result. |
| [`upstream-patches/`](upstream-patches/README.md) | Eleven patches: seven against `NativePHP/desktop`, four against `NativePHP/mobile-air`. Ten are **open PRs** ([#136–#141](https://github.com/NativePHP/desktop/pulls?q=is%3Apr+author%3Asadiqk2) and [#349–#352](https://github.com/NativePHP/mobile-air/pulls?q=is%3Apr+author%3Asadiqk2)); only the manifest patch is held, until the small ones land. |
| `upstream/` | Shallow reference clones of `NativePHP/desktop` and `NativePHP/mobile-air` (gitignored; clone on demand). |

## Adding it to an existing Symfony app

Your application does not change. It stays an ordinary Symfony app — same controllers, same
templates, same `bin/console` — and gains two things: a native window, and an API for the
machine it is running on.

You need PHP 8.3+, Symfony 7 or 8, and (for desktop) Node 20+ and `git`.

### 0. Install the packages

Neither bundle is on Packagist yet, so point Composer at a checkout. This is what the
[`demo/`](demo/README.md) does, and it is the only step that changes once they are published.

```bash
git clone https://github.com/sadiqk2/nativephp-symfony /path/to/nativephp-symfony
```

```json
"repositories": [
    { "type": "path", "url": "/path/to/nativephp-symfony/bundle",        "options": { "symlink": true } },
    { "type": "path", "url": "/path/to/nativephp-symfony/mobile-bundle", "options": { "symlink": true } }
]
```

```bash
composer require native-symfony/desktop-bundle:^0.1   # desktop
composer require native-symfony/mobile-bundle:^0.1    # iOS and Android
```

Take one or both — they share no code and neither requires the other. Use `symlink: true`:
without it Composer caches a copy and edits to the bundle appear to do nothing.

Flex registers both for you. If it is not installed, add them yourself:

```php
// config/bundles.php
Native\Symfony\Desktop\NativeDesktopBundle::class => ['all' => true],
Native\Symfony\Mobile\NativeMobileBundle::class => ['all' => true],
```

Both namespaces end in the name Flex derives its candidate bundle class from, which is not a
coincidence: the desktop bundle used to live at `Native\Symfony\` and Flex could not find
`NativeDesktopBundle` under it — it looks for `SymfonyBundle` and `NativeSymfonyBundle` there
— so the line above had to be written by hand while the mobile one appeared on its own.
Verified on a fresh `symfony/skeleton` in both directions.

### Desktop: a window around the app you already have

**1. Configure it.** Only `name` and `app_id` really matter to start:

```yaml
# config/packages/native_desktop.yaml
native_desktop:
    name: 'My App'
    app_id: com.example.myapp
    version: '1.0.0'
```

**2. Decide what appears on launch.** The runtime boots and then POSTs `/_native/api/booted`;
whatever that handler does *is* your startup. Nothing opens unless you ask:

```php
namespace App\Native;

use Native\Symfony\Desktop\Contract\AppBootstrapper;
use Native\Symfony\Desktop\Window\WindowManager;

final class Bootstrapper implements AppBootstrapper
{
    public function __construct(private readonly WindowManager $windows) {}

    public function boot(): void
    {
        $this->windows->open('main')->url('/')->size(1100, 760)->title('My App')->open();
    }
}
```

No wiring: the bundle autoconfigures the interface and a compiler pass aliases it. `boot()`
must be idempotent — macOS re-posts `/booted` on `activate` — and `window/open` is already
idempotent by id, so a `boot()` that only opens windows needs no guard.

**3. Install the Electron runtime into your project.** The bundle deliberately does not
vendor it; requiring `nativephp/desktop` would pull `illuminate/contracts` and
`laravel/prompts` into a Symfony app:

```bash
git clone --depth 1 https://github.com/NativePHP/desktop /tmp/np-desktop
bin/console native:install --source=/tmp/np-desktop/resources/electron
```

This copies the runtime to `nativephp/electron`, retargets the ten hardcoded Laravel strings
in its TypeScript at your app, installs `public/nativephp-router.php`, writes
`config/routes/native_desktop.yaml`, and runs `npm install` (it downloads Electron — expect
minutes; `--skip-npm` to skip).

**4. Check it before running it.** This runtime's failure mode is silence, so the check is
not optional ceremony:

```console
$ bin/console native:doctor          # abridged: it also prints a project/PHP/runtime table

Runtime endpoints
  ✓ POST /_native/api/booted
  ✓ POST /_native/api/events

Firewall
  – no security bundle installed, so nothing can gate the endpoints.

Application startup
  ✓ App\Bootstrapper will run when the runtime finishes booting.

Build inputs
  ✓ nativephp/php-bin
  ✓ Electron project
```

A missing routes import is the commonest way to get an app that opens a window and then does
nothing forever — `/booted` 404s, `boot()` never runs, and Electron logs it somewhere you
would not look. `native:doctor` asks the router directly and exits non-zero.

**If your app has a firewall**, the bundle keeps your `access_control` off `/_native/api/`
while the shared-secret gate is enforcing. Symfony's security config is single-source, so a
bundle cannot contribute a firewall — declare one yourself if you prefer it explicit:

```yaml
# config/packages/security.yaml — before your main firewall
security:
    firewalls:
        native_runtime:
            pattern: ^/_native/api/
            security: false
```

**5. Run and package.**

```bash
bin/console native:run                        # dev; -v logs every PHP command the runtime spawns
composer require nativephp/php-bin            # static PHP binaries, needed to package
bin/console native:build linux x64 --dir      # unpacked build, the fast smoke test
```

### Mobile: the same app, on a phone

The mobile path is **one process with PHP compiled into it** — no port, no shared secret. And
the render path most apps want is the WebView one: a Symfony app that declares no
`#[NativeScreen]` produces no native-route manifest, so both platforms' `BootPlanner` takes
the WebView by construction. Your controllers and Twig templates are unchanged; the new thing
is the device API (camera, biometrics, push, haptics — 54 methods).

**1. Configure it.** `fake_bridge` is what makes development possible at all: `nativephp_call()`
is a compiled extension that exists only inside a packaged app, so off a device every call
returns `null` and a working app is indistinguishable from a broken one. The fake records
instead.

```yaml
# config/packages/native_mobile.yaml
native_mobile:
    name: 'My App'
    app_id: com.example.myapp
    version: '1.0.0'      # quote it — YAML 1.0 is a float, and the hosts compare by string

# config/packages/dev/native_mobile.yaml and config/packages/test/native_mobile.yaml
native_mobile:
    fake_bridge: true
```

**2. Bring in the host projects and retarget them.** `native:mobile:install` prints a
licensing caution deliberately — NativePHP Mobile is sold as a product, though the
`mobile-air` repository itself is MIT; read its terms rather than either summary:

```bash
git clone --depth 1 https://github.com/NativePHP/mobile-air /tmp/np-mobile
bin/console native:mobile:install --source=/tmp/np-mobile/resources
bin/console native:mobile:doctor
```

The installer mirrors `resources/androidstudio` to `nativephp/android` and
`resources/xcode` to `nativephp/ios`, then points the two places each host hardcodes a path
into another vendor's `bootstrap/` at this bundle's shims instead. It throws rather than
skipping a path, because a missed one is a launch to a blank screen with no diagnostic.

**3. Build.**

```bash
bin/console native:mobile:build android --dry-run      # print the whole plan, touch nothing
bin/console native:mobile:build android --stage-only   # everything up to Gradle
bin/console native:mobile:build android --aab          # …then Gradle
bin/console native:mobile:build ios --export-options=auto --team-id=ABCDE12345
```

Staging, `.env` cleaning, the app archive and the manifest all run here and are covered by
tests. The last step is Gradle or Xcode on a machine that has them — and **no build produced
by these commands has been opened by either yet**, which is the one caveat worth repeating.
See [getting started — mobile](docs/getting-started-mobile.md#can-you-build-an-android-or-ios-app-today).

### Two rules that are absolute

- **`native:config` and `native:php-ini` must print nothing but JSON.** The runtime runs them
  before its API server exists, captures stdout with `execFile` and `JSON.parse`s it. Anything
  your app echoes during console boot — a banner, a `dump()`, a deprecation — breaks the parse
  and the app starts with no config and no visible symptom.
- **Never assume `NATIVEPHP_API_URL` or `NATIVEPHP_SECRET` exist.** At that point in the boot
  they do not, so any service that calls the runtime from a console-boot path throws.

A complete working example of all of the above — both bundles, two windows, a native menu, a
native-UI screen — is [`demo/`](demo/README.md), and it is also the integration test.

Every step above was run against a **fresh `symfony/skeleton` (Symfony 8.1)** rather than
written from the code: `composer require` through the path repositories, both config files,
the bootstrapper exactly as printed, `native:install --skip-npm`, and then `native:doctor`
reporting both endpoints reachable and `App\Native\Bootstrapper` wired with nothing to wire
it. `native:config` in `prod` produced 181 bytes of valid JSON and an empty stderr, which is
the rule above holding in a stock app.

## Using it

**[`docs/`](docs/README.md) is the documentation for building an application with this**, and
every document in this repository is also a page on a static site: `tools/build-docs.mjs`
renders all 21 of them into `docs/*.html` with search, light and dark themes, build-time syntax
highlighting and no external requests of any kind — ready for GitHub Pages (Settings → Pages →
`main` / `/docs`). See [`tools/README.md`](tools/README.md) to rebuild, preview or edit it.
Getting started for [desktop](docs/getting-started-desktop.md) and
[mobile](docs/getting-started-mobile.md), an API reference by area for
[desktop](docs/desktop-api.md) and [mobile](docs/mobile-api.md),
[recipes](docs/recipes.md), [testing](docs/testing.md), and a symptom-first
[troubleshooting](docs/troubleshooting.md) page — which is the one to read first, because this
runtime's usual failure mode is silence rather than an error.

Everything below is about the port itself: the specification it implements, the analysis
behind it, and the record of building it. [`docs/README.md`](docs/README.md) sorts every
document in the repository into current reference, historical record, and
for-people-working-on-the-port.

## The documents, in reading order

0. **[ARCHITECTURE.md](ARCHITECTURE.md)** — how desktop and mobile differ, what the two
   bundles do and don't share, the three verification techniques used throughout, and a
   collected list of the traps. Read this first if you only read one.
1. **[PLAN.md](PLAN.md)** — what NativePHP actually is, where Laravel leaks, and the
   roadmap. Start here.
2. **[ANALYSIS.md](ANALYSIS.md)** — the deep dive: the exact boot sequence, a per-directory
   port map with LOC, the eight Laravel-isms in the runtime's TypeScript with line numbers,
   the Symfony-specific design decisions, and the upstream bugs found along the way.
3. **[CONTRACT.md](CONTRACT.md)** — the wire protocol. All 116 endpoints with request and
   response shapes, all 44 events with payload shapes, the environment contract, and the
   seven things the runtime requires of any PHP app.
4. **[SPIKE-RESULTS.md](SPIKE-RESULTS.md)** — M1: proving it possible at all. *Historical
   record, not reference.*
5. **[M2-RESULTS.md](M2-RESULTS.md)** — the bundle. *Historical record.*
6. **[M3-RESULTS.md](M3-RESULTS.md)** — the build pipeline. *Historical record.*
7. **[MOBILE-ANALYSIS.md](MOBILE-ANALYSIS.md)** — mobile, and a correction to an earlier
   conclusion that was wrong.
8. **[NATIVE-UI-CONTRACT.md](NATIVE-UI-CONTRACT.md)** — the native-UI wire format: the
   protocol the SwiftUI and Compose renderers consume, and what a Twig front end
   (the `super-native` equivalent) would have to produce.

## The short version

The Laravel coupling in NativePHP's desktop runtime turned out to be **eight hardcoded
string literals in one TypeScript file**, not an architecture. The PHP↔runtime boundary is
already framework-neutral: localhost HTTP and JSON, with a shared-secret header.

So the adapter needs no fork. `native:install --publish` upstream already mirrors the whole
Electron project into the application's own directory, and the runtime prefers that copy —
so each app patches its own, idempotently, and the proper fix upstream is a small
behaviour-preserving manifest PR.

Mobile splits in two, and an earlier version of this README got it wrong by pricing the
whole thing at the cost of the harder half. Its native-UI engine really is 17,316 LOC coupled
to Blade — but it is **optional**: both platforms' `BootPlanner` falls back to a WebView
whenever an app registers no native routes, and `NATIVEPHP_BOOT_MODE=web` forces it. So a
Symfony mobile app needs a ~100-line SAPI shim and wrappers for 54 bridge methods, not a
rendering-engine port. See [MOBILE-ANALYSIS.md](MOBILE-ANALYSIS.md).

## Status

| | |
|---|---|
| **Desktop** | Done and proven end-to-end, including a packaged app that has been built and run. |
| **Mobile — WebView path** | Implemented and tested. No device verification yet; this environment has no Xcode or Android SDK. |
| **Mobile — native UI** | Implemented. Element trees, the Tailwind-subset parser, `#[NativeScreen]` routing and a component lifecycle — all **byte-verified** against upstream where a comparison exists. |

M0 (contract), M1 (spike), M2 (bundle), M3 (build pipeline), M5 (mobile WebView) and M6
(native UI) are done. No milestone is unbuilt.

What is left is not code:

1. **Device verification for mobile.** `native:mobile:build` stages, cleans and archives the
   application and then invokes Gradle or Xcode — and neither toolchain exists in this
   environment, so no build produced by these commands has been opened by one, and no screen
   has been rendered on a phone. Everything up to that boundary is tested; `--stage-only`
   stops exactly there. This is the single most valuable thing anyone with a Mac or an
   Android SDK can contribute.
2. **Upstream review.** Ten of the eleven patches in
   [`upstream-patches/`](upstream-patches/README.md) are open PRs awaiting review — six on
   `NativePHP/desktop` ([#136–#141](https://github.com/NativePHP/desktop/pulls?q=is%3Apr+author%3Asadiqk2))
   and four on `NativePHP/mobile-air`
   ([#349–#352](https://github.com/NativePHP/mobile-air/pulls?q=is%3Apr+author%3Asadiqk2)),
   each with a regression test in that repo's own idiom. The manifest patch — the one that
   makes the runtime framework-agnostic — is held until the small ones land, so it reaches a
   maintainer who has already merged code from the same author. Prior art is
   [NativePHP discussion #504](https://github.com/NativePHP/laravel/discussions/504).
3. **One open question for upstream** (ANALYSIS.md §9): whether Bifrost's server-side bundler
   can target `bin/console` instead of `artisan`. If it cannot, Symfony applications can only
   produce source-exposed builds — which `native:build` warns about at every invocation
   rather than letting anyone ship source unknowingly.

## Licence

MIT, matching `nativephp/desktop`. The `native-symfony` vendor name is provisional —
`nativephp/*` is someone else's brand, and asking comes before claiming it.
