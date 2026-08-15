# NativePHP for Symfony — Architecture Study & Implementation Plan

Status: planning. Written 2026-08-14 after a full read of the NativePHP org.
Prior art: [NativePHP/laravel discussion #504](https://github.com/NativePHP/laravel/discussions/504) (opened by @sadiqk2, Feb 2025).

> **See `ANALYSIS.md` for the deep technical reference**: the exact boot sequence,
> the full runtime contract, the 44-event reverse channel, a per-directory port map
> with LOC, the eight Laravel-isms in `php.ts` with line numbers, Symfony-specific
> design resolutions, eight upstream bugs worth reporting, and the M1 recipe.
>
> **`CONTRACT.md`** is the wire protocol (all 116 endpoints, 44 events, env vars).
> **`SPIKE-RESULTS.md`** is the working proof, with the findings that only running it revealed.
> **`M2-RESULTS.md`** covers the bundle, **`M3-RESULTS.md`** the build pipeline, and
> `bundle/README.md` is the package's own documentation.
> **`MOBILE-ANALYSIS.md`** covers mobile — and corrects §2.3 and §10's verdict on it.

---

## 1. What NativePHP actually is (as of Aug 2026)

It is **two unrelated products** that share a brand, not one framework with two targets.

### 1.1 `nativephp/desktop` (repo `NativePHP/desktop`, ★422)

The current desktop monorepo. It absorbed three now-**archived** repos: `laravel`
(★3.9k), `electron` (★516), `electron-plugin` (★54). Anything you read about
`nativephp/laravel` or `nativephp/electron` is historical — do not build against it.

Architecture — **two OS processes**:

```
┌─────────────────────────────── Electron main process ───────────────────────────────┐
│  resources/electron/electron-plugin/  (TypeScript)                                  │
│    • express server on 127.0.0.1:<apiPort>, mounted at /api/*   ← PHP calls IN       │
│      auth: X-NativePHP-Secret header (random per boot)                              │
│    • spawns PHP:  php -S 127.0.0.1:<phpPort> <routerScript>     → app HTML           │
│    • spawns PHP:  php artisan <cmd>                              → config/migrate    │
│    • pushes runtime events OUT to the app: POST /_native/api/events                  │
└─────────────────────────────────────────────────────────────────────────────────────┘
                                    ▲   │
                       116 JSON endpoints │ HTTP over localhost
                                    │   ▼
┌──────────────────────────────── the PHP app (Laravel today) ────────────────────────┐
│  vendor/nativephp/desktop/src/   Facades → Client → POST /api/window/resize etc.     │
└─────────────────────────────────────────────────────────────────────────────────────┘
```

**The boundary is already framework-agnostic.** It is localhost HTTP + JSON.
Nothing in the Electron process knows what a Laravel is *except* in a handful of
specific places (see §2.2). That is the whole opportunity.

Endpoint surface, from `resources/electron/electron-plugin/src/server/api/*.ts`
— 116 endpoints across 22 modules:

| module | eps | module | eps | module | eps |
|---|---|---|---|---|---|
| window | 21 | clipboard | 7 | dialog | 2 |
| app | 19 | shell | 4 | contextMenu | 2 |
| system | 10 | settings | 4 | alert | 2 |
| menuBar | 9 | screen | 4 | progressBar | 1 |
| dock | 8 | powerMonitor | 4 | process | 1 |
| childProcess | 8 | globalShortcut | 3 | notification | 1 |
| | | autoUpdater | 3 | menu / debug / broadcasting | 1 each |

### 1.2 `nativephp/mobile` (repo `NativePHP/mobile-air`, ★1134)

Completely different. PHP is **compiled into** the iOS/Android app bundle:

- `resources/androidstudio/app/src/main/cpp/` — PHP embedded via NDK, `bridge_jni.cpp`
- `bootstrap/{ios,android}/native.php` — hand-rolled SAPI: builds `$_GET/$_POST/$_COOKIE`
  from `$_SERVER`, `require bootstrap/app.php`, `$kernel->handle()`, echoes a raw
  HTTP response
- `bootstrap/{ios,android}/persistent.php` + `src/Runtime.php` — long-lived interpreter,
  requests dispatched via `zend_eval_string()` into `Runtime::dispatch()`
- Native calls go through a **PHP extension function**, not HTTP:
  `nativephp_call('Dialog.Toast', $json)`
- UI is a native element tree (SwiftUI / Jetpack Compose) driven from **Blade**:
  `resources/views/native/*.blade.php` with `<*>` elements → `mobile-ui`'s
  `resources/android/*Renderer.kt` + iOS equivalents. See `NativePHP/super-native`
  (the UI kitchen sink) for what this DX looks like in practice.
- ~20 satellite plugin packages: `mobile-camera`, `mobile-file`, `mobile-dialog`,
  `mobile-shader`, `mobile-vibe`, …, each with its own Kotlin/Swift renderers.

---

## 2. Coupling audit — where Laravel actually leaks

### 2.1 PHP side: `nativephp/desktop/src` (178 files)

Measured, not estimated:

- **51 files (29%) have zero framework coupling at all.** Enums, DataObjects,
  Menu items, Contracts, Support.
- The largest apparent coupling is **mechanical and cheap**:
  - 45 × `Illuminate\Foundation\Events\Dispatchable` + `SerializesModels`
  - 44 × `ShouldBroadcastNow` + `Broadcasting\Channel`
  - 20 × `Support\Facades\Facade`
  - 13 × `Console\Command`
  These are ~120 files of pure boilerplate around plain data. Porting them is
  find-and-replace, not design work.
- **The real coupling is 6 places:**

| # | Where | What it does | Symfony analogue |
|---|---|---|---|
| 1 | `NativeServiceProvider` | rewrites `storage_path`, forces `session.driver=file`, `queue.default=database`, injects a `nativephp` sqlite connection + WAL pragmas, pushes HTTP middleware, swaps `ExceptionHandler`, registers 11 commands, boots queue workers | `NativeBundle` + DI extension + compiler pass; env-driven `%kernel.cache_dir%`, a DBAL connection, an EventSubscriber |
| 2 | `Client\Client` | `Illuminate\Support\Facades\Http` → base URL + secret header | `Symfony\Contracts\HttpClient\HttpClientInterface` (or PSR-18). ~30 lines. |
| 3 | `Events\EventWatcher` | wildcard `Event::listen('*')`, forwards anything broadcasting on channel `nativephp` to `POST /api/broadcast` | Symfony has no wildcard listener — needs an explicit marker interface + a dispatcher decorator |
| 4 | `Http/` — 3 controllers, 2 middleware, `routes/api.php` | `_native/api/{booted,events,cookie}`; `PreventRegularBrowserAccess` | 3 controllers + bundle-loaded routes + firewall/EventSubscriber |
| 5 | `Builder/` + `Drivers/Electron/` | bundle the app, prune vendor, copy Electron project to `base_path('nativephp/electron')`, run electron-builder | mostly reusable; `base_path()` → a `ProjectPaths` service |
| 6 | 93 × `config()`, 21 × `base_path()`, 15 × `app()`, 12 × `event()` | Laravel's *runtime-mutable* config is the deepest mismatch — Symfony's container is compiled and frozen | env vars + a `NativeConfig` value object resolved at boot; anything that must change per-boot goes through env, which the Electron side already sets |

Note `Drivers/Electron/` — **a driver seam already exists** (Electron is one driver;
Tauri has been discussed). That directory layout is the natural place a
`Drivers/` abstraction for the *runtime* lives — it is not a framework seam, but it
proves the maintainers already think in terms of swappable backends.

### 2.2 Node side: the whole Laravel assumption is ~10 lines

Every Laravel-ism in the Electron plugin, all in
`resources/electron/electron-plugin/src/server/php.ts`:

1. `['artisan', 'native:php-ini']` and `['artisan', 'native:config']` — CLI entrypoint
2. `['artisan', 'schedule:run' | 'optimize' | 'migrate --force']`
3. router script hardcoded to
   `vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`, cwd `public/`
4. `LARAVEL_STORAGE_PATH` env + mkdir of `storage/framework/{cache,sessions,views,testing}`
5. `bootstrap/cache` + `APP_{SERVICES,PACKAGES,CONFIG,ROUTES,EVENTS}_CACHE` env vars
6. `vendor/laravel/nightwatch` detection (optional, ignorable)

Plus `api/childProcess.ts:174` special-casing `settings.cmd[0] === 'artisan'`.

**That's it.** Six touch points, all in one file, all trivially parameterisable.
(Running it later turned up a seventh in the same function — `APP_ENV` is set to
Laravel's `local`/`production`. See `ANALYSIS.md` §6 item 8.)

### 2.3 The decisive asymmetry

| | desktop | mobile |
|---|---|---|
| PHP↔native transport | localhost HTTP/JSON — already agnostic | PHP ext `nativephp_call()` — also agnostic |
| Framework leak in the native layer | ~10 lines, 1 file | `bootstrap/*/native.php` hard-requires `bootstrap/app.php` + `Illuminate\Contracts\Http\Kernel`; `Runtime::boot($app)` |
| UI layer to port | none — it's a WebView, your Twig already works | **Blade → native element tree.** A Twig equivalent means reimplementing the whole `mobile-ui` renderer driver |
| Native toolchain needed | none | Xcode + Android Studio + NDK + Kotlin/Swift |
| Upstream churn | slow, stable | very high — this is where all the energy is |
| Licensing | MIT, clean | check before investing: NativePHP Mobile ships under a paid license model |

---

## 3. Decision: **start with desktop.** Do not touch mobile yet.

Rationale: desktop's native side is a separate process behind a JSON API, needs no
native toolchain, has six identifiable Laravel-isms, and its UI story for Symfony is
*already solved* — a Symfony app in a WebView is just a Symfony app. Mobile requires
re-implementing Blade-driven native UI rendering for Twig, and its bootstrap layer is
genuinely Laravel-shaped. Mobile is milestone 5+, if ever.

### The single best first move

**Not** a fork, and **not** the big `nativephp/core` refactor.

Start by **writing down the contract**, then **spiking against the unmodified Electron
runtime**. Reason: the contract is currently *implicit*, spread across 22 TS files and
one PHP `Client`. Every path forward — a Symfony bundle, an upstream `core` package,
a Tauri driver — needs that document to exist, and producing it costs nothing
politically. It is also the artifact that makes an upstream PR reviewable instead of
a 5,000-line "trust me".

**Key unlock discovered during the audit:** `native:install` copies the entire Electron
project into the *user's* project at `base_path('nativephp/electron')`. So a Symfony
bundle can ship its own patched `php.ts`/`dist/` **without forking upstream and without
waiting for a merge.** The spike is unblocked today.

---

## 4. Plan

### M0 — `CONTRACT.md` (no code) — ✅ DONE

Deliverables:
- All 116 endpoints: method, path, request JSON shape, response JSON shape.
  Source: `resources/electron/electron-plugin/src/server/api/*.ts`.
- The reverse channel: every event the runtime POSTs to `_native/api/events`,
  mapped to the 45 event classes in `src/Events/`.
- The environment contract: `getDefaultEnvironmentVariables()` in `php.ts` — all 20
  `NATIVEPHP_*` vars + the Laravel-specific ones that need generalising.
- The bootstrap contract: what the runtime requires of *any* PHP app —
  a CLI entrypoint, a router script, a writable cache dir, a writable log dir,
  a sqlite path, an events endpoint, a booted endpoint, a cookie endpoint.

This doc is the spec for M2 and the justification for M4.

### M1 — "Hello Symfony Desktop" spike — ✅ DONE (see `SPIKE-RESULTS.md`)

Goal: an unmodified-upstream Electron runtime rendering a Symfony 7 page, calling
`Window::resize()` from a Symfony controller.

Blockers and their fixes:

| Blocker | Spike fix | Real fix (M4) |
|---|---|---|
| runtime calls `php artisan …` | drop a root `artisan` shim that forwards to `bin/console` | manifest-declared CLI entrypoint |
| router script path hardcoded to Laravel's `server.php` | patch the vendored `nativephp/electron/` copy in the project | manifest-declared router script |
| `artisan native:config` / `native:php-ini` expected | implement as Symfony commands emitting the same JSON | unchanged |
| `optimize`, `migrate --force` | map to `cache:warmup`, `doctrine:migrations:migrate` (or no-op) | manifest-declared lifecycle hooks |
| `storage/framework/*`, `bootstrap/cache` | let it mkdir them, ignore; point Symfony at `var/` via env | generalised path contract |

Exit criterion: a screenshot. Nothing more. **Met** — Symfony 8.1.4 on PHP 8.4.24 in
a native window, all three channels verified, plus a sixth patch hunk (`APP_ENV`) and
three new upstream bugs that only surfaced by running it.

### M2 — `nativephp/symfony` bundle (the real work) — ✅ DONE (see `M2-RESULTS.md`)

- `NativeBundle`, DI extension, `native:` config tree, autowired services (no facades)
- Transport: `HttpClientInterface`-backed `Client` implementing the M0 contract
- API services: Window, Menu, MenuBar, Dialog, Notification, Clipboard, Shell, System,
  Screen, Settings, ChildProcess, GlobalShortcut, PowerMonitor, ProgressBar, Alert,
  App, Dock, AutoUpdater
- Events: 45 plain event classes + `_native/api/events` controller →
  `EventDispatcherInterface`. Reverse direction (app → runtime broadcast) needs an
  explicit `BroadcastsToRuntime` marker interface, since Symfony has no `Event::listen('*')`.
- Commands: `native:install`, `native:serve`, `native:build`, `native:config`,
  `native:php-ini`, `native:migrate`, `native:debug`
- Security: port `PreventRegularBrowserAccess` + the security-cookie handshake
- Order of implementation: **Window → App → Dialog/Notification → Menu → the rest.**
  Window+App is 40 of the 116 endpoints and covers every real app's first hour.
  Phase 1 delivered Window (21) and App (19) plus the event bridge, security model and
  install/run tooling. **Phase 2 completed the surface: all 116 endpoints and all 44
  events**, with 222 tests and a live diagnostics run. Contract drift is now a test
  failure, not a discovery.

### M3 — build pipeline + demo — ✅ DONE (see `M3-RESULTS.md`)

The build pipeline landed first, since it answers a real open question. `native:build`
stages, prunes, cleans and packages; the resulting app has been run from
`dist/linux-unpacked` on the static `php-bin` binary. The unprotected build path works
for Symfony; the Bifrost-protected one still needs @simonhamp.

#### Original scope — a `symfony-starter` demo

The `super-native` / `kitchen-sink` equivalent: Twig + Turbo/Stimulus, exercising each
API group. This is what convinces Symfony devs the thing is real, and it doubles as the
integration test suite.

### M4 — upstream path to `nativephp/core` — six desktop PRs **open**, mobile patches held

`upstream-patches/0002`–`0007` are open as NativePHP/desktop **#136–#141** (independent
bug fixes, no review yet). Patch `0001` (the manifest) is deliberately held until the
small ones land. `0008`–`0011` (mobile-air) are written and verified but unsubmitted —
they need Sadiq's go-ahead and the mobile licensing read below.

Only attempt this *with M1–M3 in hand*. Sequence of small, individually-reviewable PRs
against `NativePHP/desktop`:

1. **php.ts parameterisation** — replace the six hardcoded Laravel-isms with values read
   from a manifest file (the org already uses this pattern: `mobile-ui`'s
   `extra.nativephp.manifest → nativephp.json`). Zero behaviour change for Laravel;
   ships a default manifest that reproduces today's values exactly. **This is the only
   PR that strictly must land upstream.**
2. **Extract `nativephp/core`** — Contracts, Enums, DataObjects, Menu items, event
   payload DTOs. Start with the 51 already-uncoupled files: literally a package move.
3. **`nativephp/desktop` becomes the Laravel adapter** over core. Facades and the
   service provider stay exactly where they are.
4. `nativephp/symfony` declares `nativephp/core` and drops its duplicated contracts.

### M5 — mobile, WebView path — ✅ DONE (see `mobile-bundle/README.md`)

The original entry here said Blade→native rendering was a project in its own right and
mobile should wait. That is still true of the *native-UI* path, and false of mobile as a
whole: `BootPlanner` on both platforms falls back to a WebView whenever an app registers no
`Route::native` patterns, and `NATIVEPHP_BOOT_MODE=web` forces it. Twig in a WebView needs
no Edge port at all.

So mobile splits in two:

- **M5 — WebView path. Done.** `mobile-bundle/` ships the SAPI shim, the persistent
  runtime, a patcher for the three hardcoded bootstrap paths, and wrappers for all 54
  `nativephp_call` methods. 55 tests — but **no device verification**, which is a weaker
  standard of evidence than desktop's and is flagged wherever mobile is described.
- **M6 — native UI for Twig — ✅ DONE.** The `super-native` equivalent: element trees
  (36 wire types byte-compared against upstream's `Edge` classes), the Tailwind-subset
  parser plus `StyleApplier`, `#[NativeScreen]` routing, the component lifecycle, and
  `ComponentScreenRenderer` joining the two. Wire format in `NATIVE-UI-CONTRACT.md`;
  upstream's renderers are reused as-is, only the tree *producer* is new.

Licensing still needs reading before anything mobile ships publicly — unlike desktop,
NativePHP Mobile is a commercial product.

---

## 5. Political / process notes

- Discussion #504 has **four volunteers** who offered to help: @cedric-r-mycelium,
  @recchia, @darrenwilly, @Rom1Bastide. @Rom1Bastide independently proposed
  substantially this same `core` + adapters architecture in Feb 2026. Re-open that
  thread with the M0 contract doc attached — a spec plus a working spike is a very
  different ask from "please make it agnostic".
- **No maintainer has responded to #504 in 18 months.** Assume no upstream help.
  Plan so that only PR #1 of M4 depends on @simonhamp saying yes, and so that M1–M3
  ship with or without him.
- Naming: `desktop` is MIT so adapting the code is fine, but "NativePHP" is their
  brand. Ask before publishing under a `nativephp/*` composer vendor; ship as
  something neutral until then.

---

## 6. Reference — local checkouts used for this study

```
np-desktop/   github.com/NativePHP/desktop      (depth 1)  — 178 PHP files, 4.2M
np-mobile/    github.com/NativePHP/mobile-air   (depth 1)  — embedded PHP, 25M
```

Files worth re-reading before starting M0:
- `np-desktop/resources/electron/electron-plugin/src/server/php.ts` ← all 6 Laravel-isms
- `np-desktop/resources/electron/electron-plugin/src/server/api.ts` ← the mount table
- `np-desktop/src/NativeServiceProvider.php` ← everything the bundle must replicate
- `np-desktop/src/Client/Client.php` ← the transport, in 40 lines
- `np-desktop/config/nativephp-internal.php` ← the env contract
- `np-mobile/bootstrap/ios/native.php` ← why mobile is hard
