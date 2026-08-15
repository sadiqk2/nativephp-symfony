# Troubleshooting

The dominant failure mode of this runtime is **silence**. It carries on with an empty config
when `native:config` fails, and it ignores unknown window ids. So most entries below are
symptom-first, because the symptom is usually all you get.

The worst of it — `notifyLaravel` swallowing every failed callback, which made a crashed,
500ing or 403ing app indistinguishable from a healthy one — is **fixed in the copy of the
runtime this bundle installs**. `RuntimePatcher` carries that fix and four others; see
[what the bundle patches](getting-started-desktop.md#what-the-bundle-fixes-in-the-runtime).
So under `native:run -v` a failed callback now says so.

Two things to do before reading further:

```bash
bin/console native:run -v      # sets SHELL_VERBOSITY=1 — the runtime then logs every
                               # PHP command it spawns, with its full argv and env
```

and watch your own log (`var/log/dev.log`). The bundle logs deliberately where the runtime
would swallow: a missing bootstrapper, a non-JSON response, a failed broadcast.

- [Desktop — boot](#desktop--boot)
- [Desktop — windows and events](#desktop--windows-and-events)
- [Desktop — packaging](#desktop--packaging)
- [Desktop — tooling](#desktop--tooling)
- [Mobile](#mobile)

---

## Desktop — boot

### My app boots to a blank window, or to no window at all

Five distinct causes. `native:doctor` checks for the first — and the second is handled for
you by default:

```bash
bin/console native:doctor
```

**The routes are not imported.** Symfony bundles cannot register routes, so the runtime's
`POST /_native/api/booted` 404s and nothing ever asks for a window.

```bash
bin/console debug:router | grep _native
# native_desktop_booted   POST   /_native/api/booted
# native_desktop_events   POST   /_native/api/events
```

If they are missing, add the import shown in
[getting started](getting-started-desktop.md#2-import-the-bundles-routes--this-is-not-automatic).

**Your firewall covers the runtime's own endpoints.** **The bundle handles this for you by
default** — but it is worth knowing about, because the symptom is indistinguishable from the
one above and the cause is in a file you wrote.

`RuntimeAccessSubscriber` runs at priority 4096 and returns *without stopping propagation* —
it cannot do otherwise, since the router listens at 32 and your app may have listeners of its
own below the firewall. So the firewall still evaluates, and an `access_control` rule of `^/`
— which is what most authenticated apps have — answers the runtime's
`POST /_native/api/booted` with a **401** that the runtime discards. Laravel is not exposed
to this: there the two routes are registered outside the app's middleware groups entirely.

`native_desktop.exempt_runtime_firewall` (on by default) decorates Symfony's
`security.access_map` so those paths carry no attributes **while running inside the runtime**.
Outside it — the same codebase deployed as an ordinary web application — your firewall still
applies in full, which matters: `/_native/api/events` dispatches events by name, and
`RuntimeAccessSubscriber` lets everything through when `NATIVEPHP_RUNNING` is unset.

A bundle cannot fix this as configuration, which is why it is done with a decorator:
`security.firewalls` rejects keys from a second config file outright, and
`security.access_control` throws `ForbiddenOverwriteException`. Both are single-source by
design.

If you turn the option off, write the equivalent yourself:

```yaml
# config/packages/security.yaml
security:
    firewalls:
        native:
            pattern: ^/_native/api/
            security: false
```

Firewalls match in declaration order and the first match wins, so `native` has to come
*before* your main firewall — appended after it, it never matches and nothing changes.

With the option off, `native:doctor` checks this by asking Symfony's own
`security.firewall.map` and `security.access_map` what covers those two paths, rather than
grepping your YAML. Both are needed: an `access_control` rule stays in the access map even
when a `security: false` firewall covers the path, because the rule is applied by the
firewall's `AccessListener` and that listener never runs — reading the map alone warns at the
one person who has already fixed it.

**No `AppBootstrapper` is registered.** The window exists only because your code asked for
one. `BootedController` logs a warning naming the contract and answers 500 — and the runtime
throws that 500 away, so the log line is the only symptom:

> The NativePHP runtime booted but no Native\Symfony\Contract\AppBootstrapper service is
> registered, so no window will open.

Implement `Native\Symfony\Contract\AppBootstrapper` on any service; autoconfiguration and
`AliasContractsPass` do the wiring. (If you register two implementations, the compiler pass
fails with both class names — that is deliberate.)

**Your `boot()` threw.** The runtime discards the response, so an exception in `boot()` looks
identical to no bootstrapper at all. Check the log; if you need a visible failure, use
`DialogManager::error()`, which is the only dialog that works before the app is ready and
needs no window.

**The window opened but the page is blank.** Open the devtools —
`$windows->open('main')->showDevTools()` or `$windows->showDevTools()` — and look at the
console and network tabs. This is now an ordinary Symfony debugging problem.

### Every route in the packaged or dev app returns 404

The runtime is serving Laravel's router path. `RuntimePatcher` rewrites the router script
literal (`vendor/laravel/framework/.../server.php` → `public/nativephp-router.php`); if that
patch did not apply, or you installed a *manifest-aware* runtime without writing
`nativephp.json`, the runtime resolves Laravel's paths and 404s everything with nothing in
the log.

```bash
bin/console native:manifest              # writes nativephp.json and reports what the runtime supports
bin/console native:install --source=… -f # or reinstall and re-patch
ls public/nativephp-router.php           # must exist
```

`native:manifest` tells you which case you are in: *Supported*, *Absent* (today's upstream —
the patches are what make it work) or *Unknown*.

### The boot dies with "The environment "local" is not registered as allowed"

`php.ts` hardcodes Laravel's environment names, and Symfony 8's
`Kernel::getAllowedEnvs()` throws on anything outside `dev`/`prod`/`test`. This is the one
Laravel-ism that is a hard failure rather than a wrong path, and it kills `native:config` and
`native:php-ini` before the window exists.

Fix: install the patched runtime (`native:install`), or write `nativephp.json` on a
manifest-aware runtime — its `env.dev`/`env.prod` keys are `dev`/`prod`.

### The boot dies before the PHP server starts, mentioning `storage`

`ensureAppFoldersAreAvailable()` calls `copySync(appPath + '/storage', …)` unconditionally,
and `fs-extra`'s `copySync` throws on a missing source. Any app without a top-level
`storage/` directory dies there. The patcher guards it with an `existsSync`; if you are on an
unpatched runtime, either patch it or create an empty `storage/`.

### Something I configured is being ignored — app id, deep links, the updater

`native:config` is failing. `index.ts::loadConfig()` wraps it in
`try { … } catch (e) { console.error(e) }` and returns `{}`, then boot continues to
completion: no app id, no deep-link registration, no updater, and a window that looks
perfectly normal. A totally broken config command and a correct one are visually identical.

```bash
bin/console native:config    # must print exactly one line of JSON, nothing else
bin/console native:php-ini
```

Anything else on stdout — a banner, a deprecation notice, a `dump()`, a log line written
during console boot — breaks `JSON.parse`. Also make sure nothing in that path calls the
runtime: at that point `NATIVEPHP_API_URL` is unset and the client throws
`RuntimeNotAvailable`.

### I get 403 from every request when I open the app URL in a browser

That is `RuntimeAccessSubscriber` doing its job: while running inside the runtime it rejects
any request carrying neither the `_php_native` cookie nor the `X-NativePHP-Secret` header, at
priority 4096 (above the firewall). Your app is on a real loopback port and that secret is the
only thing keeping other local processes out.

To debug in a browser, either send the header, or set `native_desktop.block_browser_access:
false` temporarily. Note the subscriber fails *open* when the runtime supplied no secret at
all, so if you are seeing 403s the secret exists and simply did not match.

### `RuntimeCallFailed: … rejected with 403 — the X-NativePHP-Secret header did not match`

`NATIVEPHP_SECRET` is generated per boot and handed only to processes the runtime spawns. A
stale value usually means this process outlived the runtime that started it — a child process
still running after a restart, most often.

### `RuntimeNotAvailable: … NATIVEPHP_API_URL is not set`

The code is running in a process the runtime did not start: a plain CLI invocation, an
ordinary web request, or a test. Guard it:

```php
if ($this->client->isAvailable()) { … }
```

Or, in tests, use `FakeRuntime::available()` — see [Testing](testing.md).

### `LogicException: Cannot build an absolute window URL outside an HTTP request`

`UrlResolver` builds window URLs from the current request because the PHP server's port is
chosen by the runtime at boot and never published to PHP. From a console command or a worker
there is nothing to derive it from. Pass an absolute URL, or set `native_desktop.base_url`.

## Desktop — windows and events

### My resize does not fire an event

Expected, and not fixable from PHP. The runtime listens for Electron's `resized`, which fires
for user drags but not for `setSize()`. `window/resize` succeeds and `window/get` reflects the
new size — no `WindowResized` arrives.

If you need to know your own resizes happened, act at the call site rather than in a listener.
Any test that expects the event will fail against a real runtime too.

### `$windows->current()` returns null

Also expected. The runtime calls `BrowserWindow.getFocusedWindow().id` with no null guard, so
when no window is focused (the app is backgrounded) it throws on its side and answers 500.
`current()` returns `null` rather than propagating that. Never write
`$windows->current()->id`.

If you want "the window this request came from" rather than "the focused window", use
`detectId()`, which is what every mutation already falls back to.

### The page renders enormous — about four letters fill the window

`zoomFactor` reached the runtime as `undefined`, and `setZoomFactor(parseFloat(undefined))` is
`NaN`. `PendingWindow::open()` always sends `1.0`, so this only happens if you post
`window/open` through `ClientInterface` yourself. Include `zoomFactor`.

### The window title never changes when the page's `<title>` does

The runtime `preventDefault()`s Electron's `page-title-updated`. Use
`$windows->title('…')` — it is the only route to the native title bar.

### My settings change event has the wrong key

You wrote `a.b`; the event reports `a`. Dots are **object paths**, not part of the key:
electron-store uses dot-prop, so `set('a.b', 1)` stores `{"a":{"b":1}}`. `get('a.b')` reads it
back correctly, but the runtime's store watcher diffs top-level keys only, so
`SettingChanged` names the root.

Fix: use a flat separator such as `:` (`set('a:b', 1)`) if the event key must match the key
you wrote. Same behaviour in mobile's `SecureStorage`.

### My settings listener loops forever

`SettingChanged` fires for writes your own app makes, because the runtime watches the store
rather than the caller. A listener that writes on change will re-enter. Guard on the key, or
do not write from that listener.

### My listener never runs / runs twice

Which name an event dispatches under depends on the event:

| Source | Arrives as | Listener |
|---|---|---|
| One of the 44 runtime events | its typed class | `#[AsEventListener]` on the typed parameter |
| A menu item **with** `event: 'X'` | `NativeEvent` | `#[AsEventListener(event: 'native.X')]` |
| A menu item **without** one | `MenuItemClicked` | typed listener; read `$event->id()` |
| A global shortcut | `NativeEvent` under the name you registered | `#[AsEventListener(event: 'native.…')]` |
| A notification with `event()` | `NativeEvent` | same |

Caller-named events are dispatched **twice** — under `native.<name>` and under
`NativeEvent::class` — because Symfony notifies only the listeners registered for the name
passed to `dispatch()`, and one call cannot serve both a targeted and a catch-all listener.
Register for one or the other; a listener registered for both runs twice.

If a typed listener never fires, put a catch-all `NativeEvent` listener in temporarily and log
`$event->name` and `$event->payload` — that shows exactly what the runtime is sending.

### An event's payload does not match the class, or arrives with `__error`

`EventFactory` degrades to a `NativeEvent` carrying `[...payload, '__error' => …]` when a
payload cannot satisfy its event class constructor. That means a contract mismatch: a runtime
upgrade changed a payload shape, or the map is wrong. It degrades rather than 500ing because
the runtime swallows the answer anyway.

Note the dual spreading it relies on: list payloads spread positionally
(`WindowResized: [id, width, height]`), string-keyed payloads spread as **named arguments**
(`ProcessExited: {alias, code}`). If you dispatch your own event classes by class name via
`events.allowed_namespaces`, your constructor parameter names are part of the contract.

### My child process does nothing, or `restart()` on a dead alias "succeeds"

- `message()` answers `200` even for an unknown alias, so it cannot confirm delivery.
- `restart()` on an unknown alias is meant to 410, but the runtime captures the settings
  before stopping and `{...undefined}` is `{}` in JavaScript — you get a process with empty
  settings instead of an error. Check the returned handle.
- `ProcessHandle::$pid` is often `null` immediately after starting; the `ProcessSpawned`
  event carries the real pid.
- Starting an existing alias is a no-op that returns the existing handle.

### My global shortcut never fires

`register()` cannot report failure — another application may already own the accelerator.
Use `registerChecked()`, which asks the OS afterwards and returns `false` if the registration
did not take.

### Dock calls do nothing

`DockManager` guards every method on macOS and returns quietly elsewhere, because `app.dock`
is `undefined` on Windows and Linux and the request would throw rather than no-op.

### `debug/*` calls do nothing in the packaged app

`/api/debug/*` is mounted only when `NODE_ENV === 'development'`. In a packaged app those
requests 404. `DebugLogger` is fire-and-forget by design.

### The log says "Runtime returned non-JSON for POST …"

That message means the body was neither JSON nor the status reason phrase — most often a
genuine HTML error page. It is a real signal, not noise: the client matches the exact reason
phrase for the status code (express's `res.sendStatus(200)` sends the body `OK`, a 404 sends
`Not Found`) precisely so that a real error page still gets reported.

## Desktop — packaging

### The packaged app cannot make HTTPS calls

No CA bundle. The runtime passes `$NATIVEPHP_BUILD_PATH/cacert.pem` to every PHP process as
`curl.cainfo` and `openssl.cafile`; without it every outbound TLS handshake fails.

```bash
composer require nativephp/php-bin    # ships cacert.pem; zero PHP dependencies
bin/console native:build linux x64
```

`native:build` warns rather than failing when it cannot find one — check the build output for
"The packaged app will have no CA bundle".

### The packaged app does not boot at all — `EnvNotFoundException`

`APP_SECRET` was stripped from the staged `.env`. `framework.yaml` reads
`%env(APP_SECRET)%`, so without it the container cannot even be built. This is the collision
between Laravel's defaults and Symfony's names: Laravel's cleanup list globs `*_SECRET`, which
is harmless there (its key is `APP_KEY`).

The bundle defends against this with `build.env_keep`, which **wins over** `build.env_remove`
and defaults to `['APP_SECRET']`. If you see this, you have removed `APP_SECRET` from
`env_keep`. Put it back.

### The packaged app is slow, and logs an opcache warning on every request

```
Warning: Failed loading Zend extension 'opcache' (… Dynamic loading not supported)
```

The static musl PHP binary cannot dynamically load extensions and its baked-in ini asks for
opcache anyway. **Packaged NativePHP apps have no opcache** — Laravel's too — so every request
pays full compile cost.

Warm `var/cache` at build time rather than relying on runtime caching. On a manifest-aware
runtime the manifest's `optimize` step (`cache:warmup`) does this on first serve; otherwise add
it to `native_desktop.prebuild` or run it in the staged directory.

### The packaged app fails writing cache or logs

`var/cache` and `var/log` are excluded from the copy (a dev machine's cache must not ship) and
listed in `build.keep`, which plants a placeholder because electron-builder prunes empty
directories and dotfiles do not stop it. If you have edited `build.keep`, put them back —
Symfony will not boot without both.

### The build contains readable PHP source

It does, and `native:build` warns on every run. Upstream's protected build needs a bundle from
Bifrost, NativePHP's hosted service, which currently targets Laravel's entry points; whether
that bundler can target `bin/console` is not answerable from the open-source client. There is
no workaround in this project today.

## Desktop — tooling

### `native:install` says the source does not look like a NativePHP Electron project

It checks for `electron-plugin/src/server` under `--source`. Point it at
`<checkout>/resources/electron`, not at the repository root.

### `native:install` fails with "Could not apply the … patch"

`PatchFailed::hunkDidNotMatch` — the upstream code a hunk targets has changed. The patcher
throws rather than skipping, because a skipped hunk produces an app that boots into a Laravel
router path and 404s everything. Either pin an older `NativePHP/desktop` checkout, update
`RuntimePatcher`, or move to a manifest-aware runtime and `native:manifest`.

### I edited the runtime's TypeScript and nothing changed

The runtime loads `electron-plugin/dist/`, which ships pre-compiled. Rebuild:

```bash
cd nativephp/electron && npm run plugin:build
```

`native:install` does this for you after patching, which is why `--skip-npm` leaves the
patched sources inert.

### `native:run` says the Electron project has no `node_modules`

Run `native:install` without `--skip-npm`, or `npm install` in `nativephp/electron` yourself.

### `native:run` cannot find an icon or a CA bundle

`$NATIVEPHP_BUILD_PATH` needs exactly three things: `icon.png`, `cacert.pem` and `php/php`.
`native:run` copies the first from the Electron project's `build/icon.png`, the second from
your `openssl.cafile` / `curl.cainfo` / `/etc/ssl/certs/ca-certificates.crt`, and the third
from the PHP binary running the command. If it cannot find one it says which, and you can drop
the file into `nativephp/build/` by hand.

### A failing process every minute

The runtime spawns a scheduler tick every 60 seconds from the first minute boundary and does
not check the command exists. On a patched runtime that is `native:schedule-tick`, which the
bundle ships as a no-op. On an unpatched one it is `schedule:run`, which your app probably does
not have. Patch the runtime, or write the manifest (`lifecycle.schedule`).

Do not ship your own `schedule:run` — it would collide with `symfony/scheduler`'s.

## Mobile

### Every mobile API call returns null, false, or an empty array

`nativephp_call()` is a compiled extension that exists **only inside a packaged app**, so
outside one `Bridge::isAvailable()` is false and every call no-ops. That includes your dev
machine, your test suite and CI.

```yaml
# config/packages/test/native_mobile.yaml (or dev)
native_mobile:
    fake_bridge: true
```

`bin/console native:mobile:doctor` states it plainly, and says so again in a note.

### My callback runs with no arguments

Mobile native UI, and there are three separate causes.

**The event type carries no payload.** `InteractionEvent::fromArray()` narrows the payload
*by type*: `TEXT_CHANGE`/`SUBMIT` yield one string, `TOGGLE_CHANGE`/`CHECKBOX_CHANGE` one
bool, `SLIDER_CHANGE` one float, `RADIO_CHANGE`/`SELECT_CHANGE` one string, `TAB_CHANGE` one
int — and **everything else, including a plain press, carries nothing**. A handler bound to
`->onPress()` receives only the literal arguments in its expression. If you need a value on
press, put it in the expression: `->onPress("remove({$index})")`.

**An unrecognised type code.** An unknown type degrades to a plain press and carries nothing,
deliberately, rather than leaking its payload into an argument slot.

**Your handler declares more parameters than the call can fill.** Arguments are
`[...expression literals, ...event payload]`, truncated to the slots the method actually has.
A mismatch throws `CallbackRefused` at dispatch — argument counts are deliberately *not*
checked at publish time, because how many values arrive depends on the event type, which is
unknown until the interaction happens.

### A button on the device does nothing at all

The expression names a method that cannot be dispatched — a typo (`'incremnt'`), or a method
missing `#[NativeAction]`. Only `#[NativeAction]` methods are reachable, default-deny.

`ComponentScreen::frame()` calls `assertCallbacksDispatchable()` right after publishing and
throws `CallbackRefused` for exactly this case, so **render the screen in a test** and the dead
handler becomes an exception instead of a silent no-op on a device. The check runs after the
publish because expressions are registered while the tree is serialised, not while it is
built.

### The device shows a stale or spliced-in subtree after navigating

Diff state was carried across screens. `_hash` reuse markers are keyed by node id, and node ids
are only meaningful within one screen's tree — a colliding id lets the renderer splice a
subtree it still has cached, producing a wrong-but-plausible screen.

`ComponentScreen` (in its constructor and `close()`) and `NativeScreenResponder` (on a pattern
change) call `ElementPublisher::resetDiffState()` for you. If you drive the publisher yourself,
call it on every navigation.

### A reordered list loses scroll position or input focus

Unkeyed nodes take positional identity, so reordering hands row 2's native state to row 1. Call
`->key('…')` on anything in a list that can reorder. The same rule applies to
`mount(Child::class, …, key: $k)`.

### My component state resets at random

Something is resetting it. `MobileRuntime` resets every service tagged `kernel.reset` after
each dispatch — so a component, a `ComponentScreen` or a `ComponentScreenFactory` registered
with that tag would be cleared under a live screen. That reads as a random UI reset on a device
and never reproduces in a test. The bundle deliberately does not tag them; do not add it.

Also check you are not calling `$factory->open(new X())` per request for a screen that is
supposed to persist — a fresh instance is fresh state. And a component instance cannot be
rebound: reusing one throws.

### A native screen boots into the WebView instead

`BootPlanner` fell back, and every reason is silent. In order of likelihood:

1. `entry_mode` is `web` — `NATIVEPHP_BOOT_MODE=web` at build time forces the WebView.
2. No baked manifest. **iOS requires `bundle_meta.json` to exist**; Android can boot native
   off the runtime dump alone, so this reproduces on one platform only.
3. The two `version` values are not equal **as strings**. iOS reads both through
   `as? String`, so a JSON *number* version fails the cast, compares as `""`, and the runtime
   dump is ignored. `NativeRouteManifest::version()` is a string and refuses an empty one for
   this reason.
4. The dump's directory does not exist. Upstream writes into Laravel's `storage/framework`,
   which always exists there; `writeRuntimeDump()` creates it and throws on failure rather than
   leaving the device on a stale baked list.
5. The requested path matches none of the declared patterns — check you used Laravel
   placeholder syntax (`{id}`, `{id?}`), which is what the device matches.

### A `#[NativeScreen]` renders, but its state resets on every tap

The screen is being re-instantiated per frame rather than kept alive. `ComponentScreenRenderer`
caches one instance per route pattern for exactly this reason, so this points at a custom
`ScreenRendererInterface` that builds a fresh component in `renderScreen()`. State lives in
ordinary properties, so a new instance is a new screen — and nothing logs it.

The same shape with the opposite symptom: if taps do nothing at all, the component was bound
to a registry it created itself instead of the one `renderScreen()` was handed. The id the
device sends back was minted by the previous frame and only resolves in the registry that
holds it, so the lookup misses silently.

### `NativeScreenResponder` cannot be instantiated

Only reachable now if the alias has been overridden with something that does not resolve — the
bundle aliases `ScreenRendererInterface` to `ComponentScreenRenderer`, and the responder's
renderer argument is wired `nullOnInvalid()`. If you overrode it, check your service is
registered and public enough to be aliased; otherwise the responder throws a `LogicException`
naming the interface at the point a native screen is actually requested.

### `theme-*` classes produce no colour, or `ios:` classes vanish

`StyleParser` needs a `ThemeColorResolverInterface` — without one, every
`bg-/text-/border-theme-*` class drops rather than guessing (31 of the 684 corpus tokens).
`ArrayThemeColorResolver` is the simple implementation. Platform variants drop unless
`native_mobile.platform` is `ios` or `android`; `null` means "neither", which is the safe
default off a device and the wrong one on it.

Pass the out-param to see exactly what was dropped:

```php
$style = $parser->parse($classes, $dropped);   // $dropped: list<string>
```

Note that intentional no-ops (a variant aimed at the *other* platform) are excluded from
`$dropped`, since they are not typos.

### My parsed styles have no effect on the device

`StyleParser::parse()` returns the canonical **camelCase** intermediate vocabulary
(`flexGrow`), not the wire format (layout keys are snake_case, `flex_grow`). Upstream has an
`applyLayout()` that routes each key through the right element setter; this bundle does not
implement that dispatcher yet. So `->layout($parser->parse('flex-1'))` puts keys on the wire
that the renderers do not read.

Until an applier exists, set wire keys directly (`->layout(['flex_grow' => 1])`) or route the
parsed values through the typed setters yourself.

### The app launches to a blank screen with nothing in the log

Look in logcat (Android) or the Xcode console (iOS) for `[NATIVE_EXCEPTION]` — that prefix is
how the bootstrap shims report a failure that happens before there is a logger, an error page,
or a parseable HTTP response. `BOOT_FATAL:` on stdout means `persistent.php` failed to boot the
kernel at all.

If nothing appears at all, the host is probably still executing
`vendor/nativephp/mobile/bootstrap/…/native.php` — i.e. `native:mobile:install` did not patch
the projects you are actually building. Re-run it against the copies under `nativephp/android`
and `nativephp/ios`.
