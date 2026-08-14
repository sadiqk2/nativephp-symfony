# M2 — the bundle

A real Symfony bundle, `native-symfony/desktop-bundle`, replacing the spike's inline
classes.

**Phase 2 is complete: all 116 runtime endpoints and all 44 events are implemented**,
verified by a test that parses the runtime's own express routers. 222 tests, 400
assertions. 120 files, ~6,200 LOC of source and ~1,600 of tests.

Phase 1 (below) delivered Window, App, the event bridge, the security model and the
install/run tooling. Phase 2 (§"Phase 2" further down) added the remaining 76 endpoints.

Screenshots: `spike/shot-bundle.png` (phase 1), `spike/shot-full.png` (phase 2, with the
custom application menu). Bundle docs: `bundle/README.md`.

---

## Phase 1 — what shipped

| Area | Contents |
|---|---|
| **Contracts** (future `nativephp/core`) | `ClientInterface`, `Response`, `AppBootstrapper`, `ProvidesPhpIni`, `BroadcastsToRuntime` |
| **Transport** | `Client` on `HttpClientInterface`, with `RuntimeNotAvailable` / `RuntimeCallFailed` |
| **Window** — all 21 endpoints | `WindowManager`, a fluent `PendingWindow` covering all 39 `open` keys, a typed `Window`, `UrlResolver` |
| **App** — all 19 endpoints | `AppManager`, `AppPath` enum, `NativePaths` (the 13 env-supplied locations) |
| **Event bridge** | `EventFactory` with the dual spread, 12 typed event classes, `NativeEvent`, `RuntimeBroadcaster`, `BroadcastingDispatcher` |
| **HTTP** | `BootedController`, `EventsController`, bundle-shipped routes |
| **Security** | `RuntimeAccessSubscriber` at priority 4096 |
| **DI** | `NativeDesktopBundle` config tree, `AliasContractsPass`, autoconfiguration |
| **Tooling** | `native:install` (copy + patch + npm + plugin rebuild), `native:run`, `native:config`, `native:php-ini`, `native:schedule-tick`, `RuntimePatcher` |

The runtime patch is now PHP (`RuntimePatcher`), replacing the spike's Python script:
idempotent, seven hunks, and it throws rather than skip when a target has moved upstream.

## Proven end-to-end

Same harness as M1, now against the bundle. Zero errors and zero criticals in the app log:

```
resize -> HTTP 200
no-secret -> HTTP 403 (expect 403)
size now: 760 × 520          # window/get read back through the bundle
native: application booted
native: window main shown
native: window main focused   # typed listeners, not arrays
```

The page additionally renders `_windowId detected: main` (Referer-based detection),
`app locale: en-US` (`GET app/locale`), `electron app version: 2.0.0`
(`GET app/version`) and `userData path: /tmp/.config/nativephp` (`NativePaths`, from the
environment, no round-trip).

---

## Phase 1 findings — again, from running it

### 1. `res.sendStatus(200)` sends the body `"OK"`, not an empty body

`CONTRACT.md` §0 said "200 with no body". That is wrong: express's `sendStatus` sets the
body to the status *phrase*, so a successful mutation arrives as `200` with a
`text/plain` body of `OK`, and a missing window as `404` with `Not Found`.

My client logged a JSON parse error for every mutation until I noticed. The naive fix —
"treat any non-JSON body as no data" — is too broad; it would also swallow a genuine
HTML error page, which is the one thing worth reporting. The client now compares the body
against the exact reason phrase for the status code, so status-phrase bodies are silent
and anything else is logged. Contract corrected.

### 2. `window/open` without `zoomFactor` renders the page at an absurd zoom

The runtime does, on `dom-ready`, with no guard:

```ts
window.webContents.setZoomFactor(parseFloat(zoomFactor));
```

Omit `zoomFactor` and that is `parseFloat(undefined)` → `NaN`. The first bundle run
produced a window showing about four enormous letters. Upstream never hits it because
`Native\Desktop\Windows\Window` declares `protected float $zoomFactor = 1.0` and always
serialises it — so the bug is invisible from the Laravel side and lands on the first
independent client. The bundle now always sends `1.0` unless told otherwise; upstream
should guard the `parseFloat`.

### 3. Decorating `event_dispatcher` needs the component interface, not the contract

`ANALYSIS.md` §4 called this "a decorator, ~40 LOC". Not quite: the compiled container
registers every listener and subscriber by calling `addListener()` / `addSubscriber()` on
the `event_dispatcher` service. A decorator implementing only
`Symfony\Contracts\EventDispatcher\EventDispatcherInterface` (which has just `dispatch()`)
breaks container compilation with *"Attempted to call an undefined method named
addListener"*. It has to implement
`Symfony\Component\EventDispatcher\EventDispatcherInterface` and delegate all seven
methods. Still small, but the analysis undersold it.

### 4. Symfony does not alias interfaces to implementations

Implementing `AppBootstrapper` did nothing at first: `service(AppBootstrapper::class)`
had no definition, `nullOnInvalid()` gave null, and the app booted to no windows. That is
a Laravel habit showing through — Laravel's container resolves an interface to its single
binding; Symfony requires an explicit alias.

Fixed properly rather than documented away: the bundle calls
`registerForAutoconfiguration()` on both contracts and an `AliasContractsPass` aliases
each interface to the single tagged service, erroring clearly if there are several.

Worth recording *how* it was found: the `BootedController` logs a warning naming the
missing contract when no bootstrapper is registered, and that warning is what diagnosed
it. Writing the diagnostic before needing it paid for itself within the hour — which is
the same lesson as finding 3 in `SPIKE-RESULTS.md`, from the other direction.

### 5. Retarget the scheduler tick; do not stub `schedule:run`

The runtime spawns `schedule:run` every 60s with no existence check. Shipping a no-op
`schedule:run` would collide with `symfony/scheduler`, which defines its own. Instead
`RuntimePatcher` retargets the runtime at `native:schedule-tick`, a command the bundle
owns. That silences the per-minute console error *and* leaves an app's real scheduler
untouched.

---

## Corrections applied to the earlier documents

- `CONTRACT.md` §0 — response conventions: express sends the status phrase as the body.
- `CONTRACT.md` §1 — `window/open`: `zoomFactor` is effectively required.
- `ANALYSIS.md` §4 — the dispatcher decorator must implement the component interface.
- `ANALYSIS.md` §8 — two more upstream bugs (unguarded `parseFloat`, and the
  already-noted unguarded `storage/` copy now has a companion).

---

## Phase 2 — the remaining 76 endpoints

| Area | Endpoints | Notes |
|---|---|---|
| Dialogs and alerts | 4 | `PendingOpenDialog` / `PendingSaveDialog`, plus `alert()` and a `confirm()` that binds `cancelId` so Escape cannot mean "yes" |
| Menus | 3 | An immutable `Menu` builder over 8 `MenuItem` types, matching `compileMenu`'s shape exactly |
| Dock | 8 | Every method guards on macOS — `app.dock` is *undefined* elsewhere and the request throws rather than no-oping |
| Menu bar / tray | 9 | `PendingMenuBar`, both modes (popover window vs context-menu-only) |
| Notifications | 1 | `reference` correlation, Electron's `{type,text}` action shape |
| Child processes | 8 | `ChildProcessManager` + `MessengerWorker` |
| Clipboard | 7 | Buffer selected by query string, as the runtime requires |
| System | 10 | Keychain encryption, printing, theme |
| Screen | 4 | Including the two endpoints that return *unwrapped* objects |
| Settings, shell, power monitor, shortcuts, progress bar, process, broadcast, debug | 22 | |

Plus all 44 event classes, typed — payload shapes taken from the runtime, positional or
named per event.

**Messenger workers are the piece that makes real apps viable.** `MessengerWorker` runs
`messenger:consume` as a runtime-supervised child process, the Symfony counterpart to
Laravel's `QueueWorker`. Two details that are not obvious: `handlesOwnShutdown: true` so
the runtime sends a plain SIGTERM instead of tree-killing the worker mid-message, and an
ini `memory_limit` of twice `--memory-limit`, because otherwise PHP fatals before
Messenger can notice its own limit and exit cleanly.

### Verified against the live runtime

A `/diagnostics` route exercises every non-blocking API and the headless run asserts on
the result. Dialogs are excluded on purpose — all four block the runtime's event loop
until a human answers, so driving them would hang the run.

```
clipboard      wrote symfony-39c98aed, read back symfony-39c98aed
settings       set -> "yes", forget -> "gone"
notification   reference 1786719963732.9h984lv
child_process  alias diag, pid_on_start null   (as documented)
screen         1 display, cursor {x:700, y:450}
system         theme "system", can_encrypt false   (no keyring in the container)
power          idle_state "active", on_battery false
shortcut       registered true
runtime        pid 61, linux, x64, uptime 7.29s
```

Events that arrived, all through typed listeners, zero errors in the log:

```
native: application booted
native: window main shown / focused
native: process diag spawned pid 179        (positional [alias, pid])
native: process diag said hello from a child (named {alias, data})
native: process diag exited 0                (named {alias, code})
native: setting diagnostics changed          (x2 — set and forget)
```

And the screenshot shows a custom application menu — **App | Demo | Edit** — replacing
Electron's default, so the `Menu` builder's template is one `compileMenu` accepts.

### Two more findings

**6. Dots in a setting key are object paths, not part of the key.** electron-store uses
dot-prop, so `set('diagnostics.ran', …)` stores `{"diagnostics":{"ran":…}}`. `get()` reads
it back correctly, but the `SettingChanged` event reports the **root** key
(`diagnostics`), because the runtime's store watcher diffs top-level keys only. Observed
in the run above. Documented in `SettingsManager` and `CONTRACT.md` §8.

**7. A caller-named event cannot reach both a targeted and a class-typed listener from
one dispatch.** Symfony notifies only the listeners registered for the name passed to
`dispatch()`. Since caller-named events have no class to key on, `EventsController`
dispatches them twice — under `native.{name}` for a listener that wants one specific
action, and under `NativeEvent::class` for a catch-all, which is what you want while
porting. Register for one or the other; both would run a listener twice.

### Contract drift is now a test failure

`ContractCoverageTest` parses the runtime's own `api/*.ts` routers and asserts that every
one of the 116 endpoints is called somewhere in the bundle, and that every
`Native\Desktop\Events\*` name the runtime pushes is mapped. A new upstream endpoint
fails the suite instead of being noticed a release later. Where the runtime sources are
not checked out, those cases skip and the pinned counts still catch a deletion on our
side.

---

## What is left

- **M3** — a demo app worth showing, and the build pipeline (`native:build`), which is
  where the Bifrost question in `ANALYSIS.md` §9 has to be answered.
- **M4** — the upstream manifest PR: eight concrete parameters, plus seven independent bug
  fixes now (§8 of `ANALYSIS.md`), each worth submitting on its own merits.
