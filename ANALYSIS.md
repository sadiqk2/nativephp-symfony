# NativePHP Desktop — Deep Technical Analysis

Reference document for porting NativePHP to Symfony. Read alongside `PLAN.md`.
Based on a full read of `NativePHP/desktop` @ HEAD (2026-08-14): 9,615 LOC PHP
(178 files) + 4,100 LOC TypeScript, and `NativePHP/mobile-air` @ HEAD: 54,744 LOC
PHP (272 files).

Everything below is read out of the source, not from the docs.

---

## 1. The boot sequence, exactly

This is the single most important thing to understand. From
`electron-plugin/src/index.ts::bootstrapApp()`:

```
 1. app.whenReady()                                    Electron
 2. loadConfig()
      └─ execFile(php, ['artisan','native:config'])    ← spawns PHP, reads stdout as JSON
 3. setDockIcon()                                       macOS dev only
 4. setAppUserModelId(config.app_id)
 5. setDeepLinkHandler(config.deeplink_scheme)
 6. startAutoUpdater(config.updater.*)
 7. startElectronApi()
      └─ express listen on 127.0.0.1, first free port in 4000–5000
         state.electronApiPort = port
         state.randomSecret = 32-char random string (generated at module load)
 8. loadPhpIni()
      └─ execFile(php, ['artisan','native:php-ini'])   ← spawns PHP, reads stdout as JSON
 9. startPhpApp()  →  serveApp()
      a. ensureAppFoldersAreAvailable()   copies <app>/storage → userData/storage
      b. env = getDefaultEnvironmentVariables(secret, apiPort)   ← 20+ NATIVEPHP_* vars
      c. optional: artisan nightwatch:agent
      d. if !dev: artisan optimize          (cached by app version in electron-store)
      e. if !dev: artisan migrate --force   (cached by app version in electron-store)
      f. phpPort = first free port in 8100–9000
      g. spawn: php -d <ini…> -S 127.0.0.1:<phpPort> <routerScript>   cwd=<app>/public
         └─ resolves when stderr matches /Development Server \(.*:(\d+)\) started/
      h. appendCookie()  → sets cookie _php_native=<secret> for http://localhost:<phpPort>
10. startScheduler()   aligns to the next minute boundary, then artisan schedule:run every 60s
11. session.webRequest.onBeforeSendHeaders  →  inject X-NativePHP-Secret on every
    request to http://127.0.0.1:<phpPort>/*
12. notifyLaravel('booted')  →  POST http://127.0.0.1:<phpPort>/_native/api/booted
      └─ PHP: NativeAppBootedController → app(config('nativephp.provider'))->boot()
         → THIS is where the app opens its first window
```

**Step 12 is the whole app-startup contract.** The runtime never opens a window.
It calls one endpoint and the app decides. That is a two-line port.

Note the ordering constraint at step 9b: `NATIVEPHP_API_URL` and `NATIVEPHP_SECRET`
are only in the env because the API server started at step 7. The PHP process
receives them as environment variables — there is no discovery, no handshake, no
config file. `config('nativephp-internal.api_url')` reads
`env('NATIVEPHP_API_URL')` and the `Client` uses it as a base URL. Trivially
portable to `%env(NATIVEPHP_API_URL)%`.

---

## 2. The two channels and the auth model

### Channel A — PHP → runtime (116 endpoints)

- Base URL: `http://127.0.0.1:<apiPort>/api/` (`NATIVEPHP_API_URL`)
- Auth: `X-NativePHP-Secret: <secret>` on every request. `api/middleware.ts` is
  nine lines: wrong header → `403`, full stop. No CSRF, no session, no bearer.
- Content type: JSON both ways. Many endpoints return bare `200` with no body.
- Client: `src/Client/Client.php`, 38 LOC, three methods (`get`/`post`/`delete`),
  `timeout(60*60)`.

### Channel B — runtime → PHP (44 event types)

- `utils.ts::notifyLaravel(endpoint, payload)` → `POST http://127.0.0.1:<phpPort>/_native/api/<endpoint>`
  with the same `X-NativePHP-Secret` header. Two endpoints only: `events` and `booted`.
- **Errors are swallowed** (`catch {}` with an empty body). A crashed PHP app is
  invisible to the runtime. Worth knowing when debugging a port.
- `notifyLaravel('events', …)` *also* forwards to every renderer via
  `broadcastToWindows('native-event', payload)`, so browser JS sees the event
  without a PHP round-trip.

### Channel C — runtime → renderer (the browser)

- `preload/index.mts` exposes `window.Native.on(event, cb)` and
  `window.Native.contextMenu(template)` via `contextBridge`, plus a
  `window.postMessage({type:'native-event', …})` bridge and a `native:init`
  CustomEvent on the window.
- **`window.Native` is entirely framework-agnostic.** It works in a Twig page as-is.
- Only `preload/livewire-dispatcher.js` is Laravel-specific — a 30-line shim that
  turns postMessage into `Livewire.dispatch('native:'+name, payload)`. It is
  injected into every full-page HTML response by
  `src/Events/LivewireDispatcher.php` hooking Laravel's `RequestHandled` event.
  A Symfony port replaces this file with a Stimulus/Turbo (or plain
  `CustomEvent`) dispatcher and injects it from a `kernel.response` subscriber.

### Three-layer security model

1. `_php_native` cookie, set by Electron directly into the session
   (`utils.ts::appendCookie`) — value is `state.randomSecret`.
2. `X-NativePHP-Secret` header, injected by `webRequest.onBeforeSendHeaders`
   for renderer navigations and set explicitly by `notifyLaravel`.
3. `PreventRegularBrowserAccess` middleware: if either matches
   `config('nativephp-internal.secret')`, pass; otherwise `abort(403)`.

So the PHP dev server is listening on a real TCP port and the *only* thing keeping
a browser on the same machine out is that shared secret. Port this faithfully — it
is not decoration.

---

## 3. What the runtime requires of the PHP app — the real contract

Seven things. That is the complete list.

| # | Requirement | Current (Laravel) | Symfony equivalent |
|---|---|---|---|
| 1 | A CLI entrypoint accepting subcommands | `artisan` at project root | `bin/console` |
| 2 | `<cli> native:config` printing JSON on stdout | `echo json_encode(config('nativephp'))` | a Command doing the same |
| 3 | `<cli> native:php-ini` printing JSON on stdout | provider's `phpIni()` or `{}` | a Command doing the same |
| 4 | A `php -S` router script + a document-root cwd | `vendor/laravel/framework/…/server.php`, cwd `public/` | own router script, cwd `public/` |
| 5 | `POST /_native/api/booted` → open windows | `NativeAppBootedController` | a controller |
| 6 | `POST /_native/api/events` → dispatch an event | `DispatchEventFromAppController` | a controller |
| 7 | Writable dirs at the paths the env vars name | `storage/framework/*`, `bootstrap/cache` | `var/cache`, `var/log` |

Optional, degrade gracefully if absent: `optimize`, `migrate --force`,
`schedule:run`, `nightwatch:agent`.

### Config keys the runtime actually reads

Out of the ~90-line `config/nativephp.php`, `index.ts` consumes exactly **five**:

```
app_id                                    → electronApp.setAppUserModelId()
deeplink_scheme                           → app.setAsDefaultProtocolClient()
updater.enabled                           → autoUpdater.checkForUpdatesAndNotify()
updater.default                           → picks the provider key
updater.providers.<default>.public_url    → autoUpdater.setFeedURL()
```

Everything else in that file (`version`, `author`, `cleanup_*`, `queue_workers`,
`prebuild`/`postbuild`, `nsis`, `binary_path`) is consumed by the **PHP-side build
commands**, never by the runtime. So M1's `native:config` can emit five keys and
work. This is a much smaller contract than the config file suggests.

### The environment contract

`php.ts::getDefaultEnvironmentVariables()` — grouped by portability:

- **Framework-neutral (17):** `APP_DEBUG`, `NATIVEPHP_RUNNING`,
  `NATIVEPHP_STORAGE_PATH`, `NATIVEPHP_DATABASE_PATH`, `NATIVEPHP_API_URL`,
  `NATIVEPHP_SECRET`, and ten `NATIVEPHP_*_PATH` vars mapping Electron's
  `app.getPath()` (home, appData, userData, desktop, documents, downloads, music,
  pictures, videos, recent) plus `NATIVEPHP_EXTRAS_PATH`.
- **Laravel-shaped (8):** `APP_ENV` (the *name* is neutral, the *values* are not —
  `local`/`production` are Laravel's; see §6 item 8), `LARAVEL_STORAGE_PATH`, and —
  only in a secure build —
  `APP_SERVICES_CACHE`, `APP_PACKAGES_CACHE`, `APP_CONFIG_CACHE`,
  `APP_ROUTES_CACHE`, `APP_EVENTS_CACHE`, `VIEW_COMPILED_PATH` (commented out).
- **Optional:** `NIGHTWATCH_TOKEN`, `NIGHTWATCH_INGEST_URI`.

The ten path vars become Laravel filesystem disks in `NativeServiceProvider::configureDisks()`
(`user_home`, `app_data`, `user_data`, `desktop`, `documents`, `downloads`, `music`,
`pictures`, `videos`, `recent`, `extras`), each `driver: local, throw: false, links: skip`.
Symfony has no equivalent global disk registry — either register Flysystem adapters via
`league/flysystem-bundle` config, or expose a small `NativePaths` service. **Recommend the
latter for M2** and Flysystem later; don't make `league/flysystem-bundle` a hard dependency.

---

## 4. Reverse channel — the 44 events

`DispatchEventFromAppController` is the whole mechanism:

```php
$event = $request->input('event');           // e.g. "\Native\Desktop\Events\Windows\WindowResized"
$payload = $request->input('payload', []);
if (class_exists($event)) { event(new $event(...$payload)); }
else                      { event($event, $payload); }
```

Two subtleties a port must replicate exactly:

1. **`...$payload` is both positional and named spreading.** `WindowResized` is
   pushed as `[id, width, height]` (positional); `ChildProcess\ProcessExited` is
   pushed as `{alias, code}` (string keys → PHP 8 *named arguments*). Any port
   needs the same dual behaviour or half the events break.
2. **Unknown event names still dispatch**, as a string-named event with an array
   payload. `globalShortcut`, menu items and notifications all let PHP choose the
   event name it wants pushed back — the runtime is already generic here. In Symfony
   this needs an explicit fallback: dispatch a generic `NativeEvent` under the given
   name via `EventDispatcherInterface::dispatch($e, $name)`.

Event names arrive with inconsistent leading backslashes (`'\\Native\\…'` from
`index.ts` and `helper/index.ts`, bare from `window.ts`). The preload strips them
with `replace(/^(\\)+/, '')`; `class_exists()` tolerates both. Normalise on the
Symfony side rather than inheriting the inconsistency.

Full inventory: 9 Windows, 9 ChildProcess/AutoUpdater… precisely —
Windows 9, AutoUpdater 7, MenuBar 7, PowerMonitor 8, ChildProcess 5,
Notifications 4, App 2, Menu 1, Settings 1 = **44**, plus `App\ApplicationBooted`
which is dispatched by PHP itself, never pushed.

### Forward direction — app → runtime → renderer

`EventWatcher` (29 LOC) registers `Event::listen('*')`, and for every dispatched
object that (a) has `broadcastOn()`, (b) includes the string channel `nativephp`,
and (c) is not itself a `Native\Desktop\Events\*` class, POSTs it to
`/api/broadcast`, which `broadcastToWindows()` pushes to every renderer.

**This is the hardest single thing to port.** Symfony's `EventDispatcher` has no
wildcard listener. Options:

- **(recommended)** a marker interface `BroadcastsToRuntime` plus a decorator on
  `event_dispatcher` that inspects each dispatched object. Symfony dispatches by event
  *class*, not by interface, so the alternative — a compiler pass registering a
  listener per implementor — cannot see runtime-created classes.

  Correction from building it: the decorator must implement
  `Symfony\Component\EventDispatcher\EventDispatcherInterface`, not the contracts
  interface. The compiled container registers every listener by calling
  `addListener()` on the `event_dispatcher` service, so a decorator with only
  `dispatch()` breaks container compilation. Seven methods to delegate, not ~40 LOC of
  pure logic. See `M2-RESULTS.md` finding 3.
- Not recommended: requiring users to call `Native::broadcast($event)` explicitly.
  It works, but it silently changes the programming model versus the Laravel docs.

`LogWatcher` has the same shape (listens for `MessageLogged` → POST `/api/debug/log`)
and in Symfony is just a Monolog handler. Note `/api/debug/*` is **only mounted when
`NODE_ENV === 'development'`** (`api.ts`), so a production build POSTing there gets 404s
into the void — harmless, but don't spend time debugging it.

---

## 5. Port map — where the 9,615 LOC go

| Area | LOC | Difficulty | Notes |
|---|---|---|---|
| `src/*.php` (root: the 19 API classes) | 1,603 | **easy** | Each method is `$this->client->post('window/resize', […])`. Mechanical. |
| `src/Events/` | 1,220 | **easy** | 44 data classes + `EventWatcher` (hard, §4) + `LivewireDispatcher` (replace). |
| `src/Drivers/` | 1,187 | **medium** | Electron project management, npm orchestration, updater providers. Mostly reusable with `base_path()` → a paths service. |
| `src/Commands/` | 1,093 | **medium** | 11 commands. `native:config`/`native:php-ini` are 10 LOC each. DB commands (`migrate`, `seed`, `wipe`, `fresh`) extend Laravel's own — Symfony needs Doctrine equivalents or drops them. |
| `src/Fakes/` | 915 | **easy** | Test doubles. Port later; not on the critical path. |
| `src/Windows/` | 601 | **easy** | `WindowManager` + fluent `Window`/`PendingOpenWindow`. Uses `Conditionable` — Symfony has no trait for that; either vendor a 20-LOC copy or drop `->when()`. |
| `src/Builder/` | 552 | **medium** | Already uses `symfony/filesystem` + `Symfony\Component\Filesystem\Path`. Mostly framework-neutral already. |
| `src/Facades/` | 527 | **delete** | Replace with autowired services. Zero port work; pure subtraction. |
| `src/Support/` | 480 | **easy** | `Composer` (uses `base_path`, `data_set`), `Environment` (pure PHP_OS_FAMILY, copy verbatim), `Timezones`. |
| `src/Menu/`, `src/MenuBar/` | 708 | **easy** | Fluent builders producing arrays; the shape is defined by `helper/index.ts::compileMenu`. |
| `src/Concerns/` | 262 | **easy** | `DetectsWindowId` needs `Referer`-then-current-URL `_windowId` extraction from a Symfony `Request`. |
| `src/Contracts/`, `Enums/`, `DataObjects/` | 264 | **copy** | Already framework-free. First candidates for `nativephp/core`. |
| `src/Http/` | 121 | **easy** | 3 controllers + 2 middleware. |
| `src/Client/` | 38 | **easy** | Swap `Http` facade for `HttpClientInterface`. |
| `src/Logging/`, `src/Exceptions/` | 44 | **easy** | Monolog handler; an `ErrorListener` writing `[NATIVE_EXCEPTION]:` to `error_log` (the runtime greps stderr for that literal prefix — keep it). |

Realistic first-pass scope for a usable bundle: **~3,500 LOC** (root API classes +
events + Http + Client + Windows + Menu + the four commands needed for the dev
loop). Facades and Fakes are free. Builder/Drivers can wait until someone needs a
production build.

---

## 6. The six Laravel-isms in the Electron plugin, with locations

All in `electron-plugin/src/server/php.ts` unless noted:

| # | Location | Code | Fix |
|---|---|---|---|
| 1 | L153, L174 | `const command = ['artisan', 'native:php-ini' \| 'native:config']` | manifest key `cli` |
| 2 | L184, L208, L286, L425, L442 | `args[0] === 'artisan'` guards; `['artisan','schedule:run'\|'optimize'\|'migrate --force']` | manifest keys `cli`, `lifecycle.*` |
| 3 | L388–L397 | router script `vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`, `cwd = join(appPath,'public')` | manifest keys `router`, `docroot` |
| 4 | L20, L24–L28, L253 | `LARAVEL_STORAGE_PATH`; mkdir `storage/framework/{cache,sessions,views,testing}`; `copySync(join(appPath,'storage'), storagePath)` | manifest key `writableDirs[]` |
| 5 | L22, L428–L433 | `bootstrap/cache` + `APP_{SERVICES,PACKAGES,CONFIG,ROUTES,EVENTS}_CACHE` | manifest key `cacheEnv{}` |
| 6 | L47–L96 | `vendor/laravel/nightwatch` detection | leave as-is; it no-ops elsewhere |
| 7 | `api/childProcess.ts:174` | `settings.cmd[0] === 'artisan'` | manifest key `cli` |
| **8** | L~300 (`getDefaultEnvironmentVariables`) | `APP_ENV: … ? 'local' : 'production'` | manifest key `env.{dev,prod}` |

Item 8 was **not** visible from reading — it surfaced only when the spike ran, and it
is the only one of the eight that is a *hard* boot failure rather than a wrong path:
Symfony 8 whitelists `APP_ENV` in `Kernel::getAllowedEnvs()` and throws on `local`.
See `SPIKE-RESULTS.md` finding 1.

That is the entire upstream diff needed for framework independence at the runtime
layer. A `nativephp.json` manifest with six keys, defaulting to today's Laravel
values, is a zero-behaviour-change PR. The org already uses exactly this pattern —
`mobile-ui/composer.json` declares `extra.nativephp.manifest → nativephp.json`.

**And the escape hatch:** `ElectronServiceProvider::electronPath()` prefers
`base_path('nativephp/electron/')` over the vendor copy whenever a `package.json`
exists there, and `native:install --publish` mirrors the whole Electron project
into the user's repo. So a Symfony bundle can ship a patched plugin *today*,
without upstream cooperation. The upstream PR is for hygiene, not for unblocking.

---

## 7. Symfony-specific design problems and resolutions

### 7.1 Runtime-mutable config — the deepest mismatch

`NativeServiceProvider::configureApp()` mutates config at boot: `session.driver=file`,
`queue.default=database`, a whole `database.connections.nativephp` array,
`queue.failed.database`, `queue.batching.database`, plus
`rewriteStoragePath()` which does `Arr::dot(config()->all())` and string-replaces the
old storage path in **every** config value. Symfony's container is compiled and frozen;
none of this is possible at runtime.

Resolution: everything the runtime needs to vary is *already* an environment
variable, because it has to cross a process boundary. So:

- `%env(NATIVEPHP_STORAGE_PATH)%` etc. straight into `framework.cache`,
  `framework.session.save_path`, Monolog paths, and a `doctrine.dbal.connections.nativephp`
  with `path: '%env(NATIVEPHP_DATABASE_PATH)%'`.
- Ship a `config/packages/native.yaml` recipe that wires those, rather than trying to
  mutate anything. Declarative where Laravel is imperative — arguably better.
- The `Arr::dot` path rewriting has no analogue and needs none: Symfony's
  `%kernel.cache_dir%`/`%kernel.logs_dir%` are single-sourced, unlike Laravel's
  dozens of independently-configured absolute paths.
- SQLite WAL setup (`PRAGMA journal_mode=WAL`, `busy_timeout=5000`) becomes a
  DBAL `postConnect` event subscriber. Do not skip this — multiple PHP processes
  (dev server + queue workers + scheduler) hit that file concurrently.

### 7.2 Queue workers

`QueueWorker::up()` spawns `artisan queue:listen|queue:work` as a *runtime child
process* via `/api/child-process/start-php` — the Electron `utilityProcess`, with
`persistent: true` so the runtime restarts it on exit. Symfony Messenger's
`messenger:consume` maps cleanly onto the same call; only the argument names change
(`--memory`/`--timeout`/`--sleep` → `--memory-limit`/`--time-limit`/`--sleep`).

The `persistent` watchdog, `handlesOwnShutdown` SIGTERM semantics, and the 12-second
drain window in `index.ts::before-quit` are all runtime-side and come for free.

### 7.3 The dev-server document root

Laravel's `server.php` router exists to emulate mod_rewrite for `php -S`. Symfony
ships `Symfony\Component\HttpFoundation\Response` but no equivalent router script for
the built-in server (`symfony serve` uses its own Go binary, which is not usable here —
the runtime needs `php -S` so it can pass `-d` ini flags and own the process).

Write a ~20-line router: serve the file if it exists under `public/`, else
`require public/index.php`. This is well-trodden ground; Symfony's own docs
described exactly this before `symfony serve` existed.

### 7.4 `Conditionable`, `Macroable`, `Str`, `Arr`, collections

13 files use `Illuminate\Support\Traits\Conditionable` for `->when()`/`->unless()` on
the fluent builders, one uses `Macroable`. Pulling `illuminate/support` into a Symfony
bundle for this would be absurd. Vendor a 25-LOC `Conditionable` into
`Native\Symfony\Support` and drop `Macroable`. `Str`/`Arr`/`Collection` uses are few
enough (a handful) to inline.

### 7.5 The `_windowId` convention

Every navigation the runtime performs appends `?_windowId=<id>`
(`utils.ts::appendWindowIdToUrl`), and `DetectsWindowId` recovers it from the
`Referer` header, falling back to the current URL. Consequences for Symfony:

- The query parameter is part of the URL for HTTP-cache purposes. Make sure
  `native:` routes are `no-store`, or two windows will share cached pages.
- `Referer`-based detection means the *current* request's window is inferred from
  where the user came from. Fragile by design; keep the fallback order identical
  rather than "improving" it, or multi-window apps will target the wrong window.

### 7.7 Laravel's default lists do not translate — they collide

`config/nativephp.php`'s `cleanup_env_keys` globs `*_SECRET`. Harmless in Laravel, whose
key is `APP_KEY`; fatal in Symfony, whose key is **`APP_SECRET`** and is read by
`framework.yaml` as `%env(APP_SECRET)%`. A build that strips it produces an app that
throws `EnvNotFoundException` before it can render anything.

The lesson is general, and applies to anything carried across from the Laravel config:
these lists were written against Laravel's names and the two frameworks collide on
`APP_*`. Re-read them rather than translating them. See `M3-RESULTS.md` finding 1 for the
fix, which guards against the user re-introducing the glob themselves.

### 7.6 Provider bootstrap

`config('nativephp.provider')` names a class (`App\Providers\NativeAppServiceProvider`)
that the booted-controller resolves and calls `->boot()` on, and that
`native:php-ini` optionally calls `->phpIni()` on. In Symfony this becomes an
interface pair — `NativeAppBootstrapper { public function boot(): void; }` and the
existing `ProvidesPhpIni` — implemented by a user service and autowired. Cleaner
than the Laravel version, which resolves a service-provider class out of the
container for two ad-hoc method calls.

---

## 8. Findings worth reporting upstream

These make good first PRs: small, real, and they buy credibility before asking for
the framework-agnosticism refactor.

1. **`CreateSecurityCookieController` reads a config namespace that does not exist.**
   `src/Http/Controllers/CreateSecurityCookieController.php:11,15` uses
   `config('native-php.secret')`. The only config files are `nativephp.php` and
   `nativephp-internal.php` — grep confirms `native-php` appears nowhere else in the
   package. So the guard compares user input against `null` (passing only when no
   `secret` param is sent) and then sets the cookie to `null`. The route is
   effectively dead; the real cookie is set by Electron in `utils.ts::appendCookie`.
   Either fix to `nativephp-internal.secret` or delete the route. As written it is
   also the one route `PreventRegularBrowserAccess` deliberately lets through
   unauthenticated.

2. **`notifyLaravel` swallows every error** (`utils.ts`, empty `catch {}`). A PHP app
   that is down, 500ing, or 403ing is indistinguishable from one working fine. A
   single `console.error` behind `SHELL_VERBOSITY` would save porters and app
   developers a lot of time — I hit this repeatedly while tracing the flow.

3. **`window/current` will throw if no window is focused.**
   `api/window.ts` calls `BrowserWindow.getFocusedWindow().id` with no null guard;
   `getFocusedWindow()` returns `null` when the app is in the background. Then
   `getWindowData(undefined)` throws a bare string. Reachable from any background
   PHP process (a queue worker calling `Window::current()`).

4. **`shell/trash-item` returns `res.status(400).json()` with no argument**, which
   express rejects. Minor, but it turns a handled failure into an unhandled one.

5. **A missing `storage/` directory kills the boot.**
   `php.ts::ensureAppFoldersAreAvailable()` calls
   `copySync(join(appPath,'storage'), storagePath)` unconditionally, and `fs-extra`
   throws on a missing source — so boot dies at step 9a, before the PHP server starts.
   Not Symfony-specific: a Laravel app whose storage lives elsewhere hits it too. One
   `existsSync` guard fixes it. Found by running the spike.

6. **`native:config` / `native:php-ini` failures are swallowed.**
   `index.ts::loadConfig()` / `loadPhpIni()` catch, log, and return `{}`. During the
   spike both commands were failing and boot ran to completion anyway — no app id, no
   deep links, no updater, no visible symptom. Same class as finding 2, and the single
   most expensive thing to debug when porting: a broken config command and a correct
   one look identical.

7. **`schedule:run` is spawned unconditionally every 60s** with no check that the
   command exists, producing a silently failing process per minute for any app that
   lacks it.

8. **`window/open` without `zoomFactor` calls `setZoomFactor(NaN)`.**
   `api/window.ts` does `setZoomFactor(parseFloat(zoomFactor))` on `dom-ready` with no
   guard. Invisible upstream because `Windows\Window` defaults the property to `1.0`
   and always serialises it — so it lands on the first independent client instead.
   Found by looking at a window rendering four enormous letters.

9. **Packaged apps silently have no opcache.** The `php-bin` static binary cannot load
   extensions dynamically, yet its baked-in ini asks for opcache, so every invocation
   prints a warning and every request pays full compile cost. Affects Laravel apps
   equally. The warning is also HTML-escaped into stderr (`html_errors=1`), which makes
   the runtime's logs harder to read.

10. **Stale conflict metadata.** `composer.json` conflicts with `nativephp/laravel`
   and `nativephp/electron` — correct — but the `homepage` still points at
   `github.com/nativephp/laravel`, which is archived, and `extra.laravel.aliases`
   maps `Updater` to `Native\Electron\Facades\Updater` while the class actually
   lives at `Native\Desktop\Drivers\Electron\Facades\Updater`. That alias is broken.

---

## 9. Open questions that need maintainer input

1. **Bifrost and the secure build.** `BuildCommand` branches on
   `Builder::hasBundled()` — the presence of `build/__nativephp_app_bundle`,
   downloaded from `bifrost.nativephp.com` (`api/v1/projects/{id}/builds/latest-desktop-bundle`).
   Without it, `native:build` prints a loud `* * * INSECURE BUILD * * *` warning and
   ships readable source. In a secure build the bundle file is used *both* as the
   `php -S` router script and prepended to every `artisan` invocation — so it is a
   loader that must know the project's entrypoints. Whether Bifrost's server-side
   bundler can target `public/index.php` + `bin/console` instead of
   `public/index.php` + `artisan` is not answerable from the client code. **If it
   cannot, Symfony users get source-exposed builds only** — a genuine product gap,
   and also a commercial argument *for* Symfony support: Bifrost is the paid product,
   and a Symfony adapter enlarges its market.

2. **Naming and vendor namespace.** `nativephp/desktop` is MIT, so adapting the code
   is unambiguously fine. Publishing as `nativephp/symfony` is a brand question.

3. **Would a manifest PR be accepted?** §6's PR is small and behaviour-preserving,
   but it is the one thing that must land upstream for the port not to carry a
   patched copy of the plugin forever.

---

## 10. Mobile — a correction

> **This section is superseded by `MOBILE-ANALYSIS.md`.** The numbers below are accurate but
> the conclusion drawn from them was wrong: the Blade-coupled rendering engine is **optional**,
> not mandatory. `BootPlanner` on both platforms falls back to a WebView path whenever an app
> registers no `Route::native` patterns, and `NATIVEPHP_BOOT_MODE=web` forces it explicitly. A
> Symfony mobile app therefore needs **none** of the 17,316 lines described here — it needs a
> ~100-line SAPI shim and wrappers for 54 bridge methods.
>
> I measured the hard path and treated it as the only path. Kept below as written, because the
> Edge figures still price the *native-UI* project (M6) correctly.

### The original assessment — why native UI is a different order of magnitude

Numbers, so this is not a matter of taste:

- **`src/Edge/` is 17,316 LOC — 32% of mobile's entire PHP codebase.** It is a
  rendering engine: `NativeTagPrecompiler` (compiles `<native:*>` tags straight into
  `NativeElementCollector` calls, explicitly *bypassing* the Blade component
  lifecycle for speed), `TailwindParser`, `ElementRegistry`, `ComponentRegistry`,
  37 element classes, a Yoga-flexbox layout model, `NativeRouter`, plus
  `Route::native()` / `Route::nativeGroup()` / `->layout()` macros on Laravel's
  Router. **32 of its 92 files import Blade, `Illuminate\View`, or a compiler
  directly.**
- The device-side bootstrap is a hand-rolled SAPI: `bootstrap/{ios,android}/native.php`
  reconstructs `$_GET`/`$_POST`/`$_COOKIE` from `$_SERVER`, requires
  `bootstrap/app.php`, resolves `Illuminate\Contracts\Http\Kernel`, and echoes a raw
  HTTP response by hand. `persistent.php` + `src/Runtime.php` keep the interpreter
  alive across requests via `zend_eval_string()` into `Runtime::dispatch()`, with a
  `Runtime::boot($app)` that takes a Laravel application instance.
- The native bridge itself (`nativephp_call($method, $jsonPayload)`, a PHP extension
  function) *is* framework-agnostic — that part is fine.
- Requires Xcode + Android Studio + NDK + Kotlin/Swift to even run the test loop.
- ~20 satellite plugin packages, each shipping its own Kotlin/Swift renderers, plus a
  plugin manifest/compiler system (`src/Plugins/`, `AndroidPluginCompiler`,
  `IOSPluginCompiler`).

A Twig port means reimplementing a 17k-LOC Blade-coupled rendering engine against a
moving target, before a single native call happens. Desktop needs ~3,500 LOC against
a stable HTTP contract, and its UI layer needs **zero** work because a Symfony app in
a WebView is already a Symfony app.

Verdict unchanged: desktop first, and mobile only if desktop earns it.

---

## 11. Revised milestones

M0 and M1 are now well-enough understood to start immediately.

- **M0 — `CONTRACT.md`.** §§1–4 above are most of it. What remains is the per-endpoint
  request/response table for all 116 endpoints. Mechanical; extract from the 22 TS
  route files.
- **M1 — spike.** Concretely, in a fresh Symfony 7 app:
  1. `composer require nativephp/desktop` (yes, the Laravel one — for its
     `resources/electron/`), then copy `resources/electron` to `nativephp/electron`.
  2. Patch three spots in `nativephp/electron/electron-plugin/src/server/php.ts`
     (§6 items 1, 2, 3), rebuild `dist/`.
  3. Root `artisan` shim → `bin/console`.
  4. Two commands: `native:config` (emit the five keys), `native:php-ini` (emit `{}`).
  5. Two controllers: `/_native/api/booted`, `/_native/api/events`.
  6. One router script for `php -S`.
  7. A 40-LOC `Client` on `HttpClientInterface`, and `Window::open()` /
     `Window::resize()`.
  8. `npm install && npm run dev` in `nativephp/electron` with `APP_PATH=<symfony app>`.
  Exit criterion: a Twig page in an Electron window that resizes itself.
- **M2–M5** as in `PLAN.md`, with the port map in §5 as the work breakdown and the
  design resolutions in §7 as the decisions already made.
- **Alongside M1:** open the five findings in §8 as issues/PRs. They are real,
  independently useful, and they establish that this is a serious effort before
  #504 gets re-opened with a spec attached.
