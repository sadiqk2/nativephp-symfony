# NativePHP Desktop — Runtime Contract

The wire protocol between a PHP application and the NativePHP Electron runtime.
Extracted from `NativePHP/desktop` @ HEAD (2026-08-14), `resources/electron/electron-plugin/src/`.
Framework-neutral by construction: nothing below mentions Laravel except where the
current implementation leaks it, and those leaks are called out as such.

This is the spec a `nativephp/symfony` adapter implements, and the spec a
`nativephp/core` package would encode. See `ANALYSIS.md` for the boot sequence and
`PLAN.md` for the roadmap.

---

## 0. Transport and conventions

### Channel A — app → runtime

- **Base URL** `http://127.0.0.1:{apiPort}/api/` — supplied to the PHP process as
  `NATIVEPHP_API_URL` (already includes the trailing `/api/`).
- **Port** first free port in `4000–5000`, chosen at runtime boot.
- **Auth** `X-NativePHP-Secret: {secret}` on *every* request, from `NATIVEPHP_SECRET`.
  Mismatch → `403` with an empty body, before any routing (`api/middleware.ts`).
- **Body** JSON (`body-parser.json()`). Send `Content-Type: application/json`.
- **Timeout** the reference client uses 3600s. Several endpoints block on native UI
  (`dialog/*`, `alert/message`, `system/prompt-touch-id`) and will not return until
  the user acts. Do not set a short timeout.
- **`/api/debug/*` is mounted only when `NODE_ENV === 'development'`** (`api.ts`).
  In production those requests 404. Treat as fire-and-forget.

### Response conventions

Three shapes, and they are not consistent — a port must handle all three:

1. `200` whose body is the status **phrase**, not JSON and not empty — most mutations.
   `res.sendStatus(200)` makes express send `text/plain` `OK`; a `404` from
   `window/get/:id` arrives as `Not Found`. Treat a body equal to the reason phrase for
   its status code as "no data" — and *only* that, so a genuine HTML error page is still
   visible as a problem. (This entry originally read "no body"; corrected after a client
   logged a JSON parse error on every successful mutation.)
2. `200` with a JSON object — all reads, plus a few mutations.
3. **`200` sent *before* the work happens.** Every `menu-bar/*` endpoint and both
   `context` endpoints call `res.sendStatus(200)` as their first statement, then act.
   A `200` from those is an acknowledgement of receipt, **not** of success — errors
   there are unobservable by the caller.

Error responses that do exist: `400` (`{error: string}`) from the encrypt/decrypt,
print-to-pdf, and clipboard-image paths; `404` from `window/get/:id`; `410` from
`child-process/get/:alias` and `child-process/restart` when the alias is unknown;
`500` from `shell/open-external` and `system/print`.

### Channel B — runtime → app

- `POST http://127.0.0.1:{phpPort}/_native/api/{events|booted}`, same
  `X-NativePHP-Secret` header. Only those two endpoints exist.
- **Failures are silently swallowed** (`utils.ts::notifyLaravel`, empty `catch {}`).
- Every `events` post is *also* pushed to all renderers as an IPC `native-event`.

### Channel C — runtime → renderer

Exposed by `preload/index.mts` via `contextBridge`, framework-agnostic:

| Surface | Signature | Notes |
|---|---|---|
| `window.Native.on` | `(event: string, cb: (payload, event) => void)` | Leading backslashes stripped from both sides before comparison. |
| `window.Native.contextMenu` | `(template: MenuTemplate[]) => void` | Popped over the current window via `@electron/remote`. |
| `window` event `native:init` | `CustomEvent` | Fired once when preload finishes evaluating. |
| `window.postMessage` | `{type:'native-event', event, payload}` | For code that cannot use `contextBridge`. |
| IPC `log` | `{level, message, context}` | Written to the devtools console by preload. |

---

## 1. Window — 22 endpoints

`state.windows` is a `Record<developerId, BrowserWindow>`. The `id` in every request
is the **developer-assigned string id**, not Electron's numeric id. Unknown ids are
silently ignored (`state.windows[id]?.…`) — no error.

| Method | Path | Request | Response |
|---|---|---|---|
| POST | `window/open` | see below | `200` |
| POST | `window/close` | `{id}` | `200` — also deletes from `state.windows` |
| POST | `window/show` | `{id}` | `200` |
| POST | `window/hide` | `{id}` | `200` |
| POST | `window/maximize` | `{id}` | `200` |
| POST | `window/unmaximize` | `{id}` | `200` |
| POST | `window/minimize` | `{id}` | `200` |
| POST | `window/fullscreen` | `{id, fullscreen: bool}` | `200` |
| POST | `window/reload` | `{id}` | `200` |
| POST | `window/resize` | `{id, width, height}` | `200` — `parseInt`'d |
| POST | `window/position` | `{id, x, y, animate}` | `200` — `parseInt`'d |
| POST | `window/title` | `{id, title}` | `200` |
| POST | `window/url` | `{id, url}` | `200` — `?_windowId={id}` appended |
| POST | `window/closable` | `{id, closable: bool}` | `200` |
| POST | `window/always-on-top` | `{id, alwaysOnTop: bool}` | `200` |
| POST | `window/window-button-visibility` | `{id, windowButtonVisibility: bool}` | `200` — macOS only |
| POST | `window/set-zoom-factor` | `{id, zoomFactor: float}` | `200` — `parseFloat`'d |
| POST | `window/show-dev-tools` | `{id}` | `200` |
| POST | `window/hide-dev-tools` | `{id}` | `200` |
| GET | `window/current` | — | `WindowData` |
| GET | `window/all` | — | `WindowData[]` |
| GET | `window/get/{id}` | — | `WindowData`, or `404` |

**`WindowData`** — 24 fields: `id`, `x`, `y`, `width`, `height`, `title`,
`alwaysOnTop`, `url`, `autoHideMenuBar`, `fullscreen`, `fullscreenable`, `kiosk`,
`devToolsOpen`, `resizable`, `movable`, `minimizable`, `maximizable`, `closable`,
`focusable`, `focused`, `hasShadow`. (`frame`, `titleBarStyle` and
`trafficLightPosition` are commented out upstream — do not expose them.)

> ⚠️ `window/current` calls `BrowserWindow.getFocusedWindow().id` with no null guard.
> When no window is focused (app backgrounded) it throws. Any client must treat this
> endpoint as fallible — see `ANALYSIS.md` §8.3.

**`window/open` request** — 39 keys, all optional except `id`, `url`, `width`, `height`
— and `zoomFactor`, which is optional only on paper: the runtime runs
`setZoomFactor(parseFloat(zoomFactor))` on `dom-ready` with no guard, so omitting it
yields `NaN` and renders the page at an absurd zoom. Always send it (`1.0` is the
upstream default). See `M2-RESULTS.md` finding 2.

```
id  url  x  y  width  height  minWidth  minHeight  maxWidth  maxHeight
frame  title  backgroundColor  transparency  vibrancy  hasShadow
alwaysOnTop  resizable  movable  minimizable  maximizable  closable
focusable  skipTaskbar  hiddenInMissionControl  autoHideMenuBar
titleBarStyle  trafficLightPosition  windowButtonVisibility
fullscreen  fullscreenable  kiosk  zoomFactor  showDevTools
rememberState  webPreferences  preventLeaveDomain  preventLeavePage
suppressNewWindows
```

Behaviour worth porting deliberately:

- **Idempotent**: if `id` already exists, the window is shown and focused and `200`
  returns — no new window, no error.
- `rememberState: true` persists geometry to `window-state-{id}.json` via
  `electron-window-state`; the stored size wins over the requested one *only if*
  `resizable` is truthy.
- `webPreferences` is merged, with `{sandbox:false, preload:…, contextIsolation:true}`
  force-applied last (`webPreferences.ts`) — those three cannot be overridden.
- `preventLeaveDomain` / `preventLeavePage` install a `will-navigate` guard comparing
  hostname / (origin + pathname) against the *initial* URL.
- The window is created with `show:false` and shown on `did-finish-load`, unless
  `NATIVEPHP_NO_FOCUS` is set and the window is already visible.
- `page-title-updated` is `preventDefault()`ed — the document `<title>` never changes
  the native window title. Use `window/title`.
- Emits 9 events (§12). **But not for its own resizes**: `window.ts` listens for
  Electron's `resized`, which fires on user-driven resizes, not `setSize()`. A
  `window/resize` call succeeds and `window/get` reflects it, yet no
  `WindowResized` arrives — verified empirically in the M1 spike. An app cannot
  use the event to observe resizes it initiated itself.

---

## 2. App — 19 endpoints

| Method | Path | Request | Response |
|---|---|---|---|
| POST | `app/quit` | — | `200` |
| POST | `app/relaunch` | — | **no response** — relaunches then quits |
| POST | `app/show` | — | `200` |
| POST | `app/hide` | — | `200` |
| GET | `app/is-hidden` | — | `{is_hidden: bool}` |
| GET | `app/locale` | — | `{locale: string}` |
| GET | `app/locale-country-code` | — | `{locale_country_code: string}` |
| GET | `app/system-locale` | — | `{system_locale: string}` |
| GET | `app/app-path` | — | `{path: string}` |
| GET | `app/path/{name}` | — | `{path: string}` |
| GET | `app/version` | — | `{version: string}` |
| GET | `app/badge-count` | — | `{count: int}` |
| POST | `app/badge-count` | `{count: int}` | `200` |
| POST | `app/recent-documents` | `{path}` | `200` |
| DELETE | `app/recent-documents` | — | `200` |
| GET | `app/open-at-login` | — | `{open: bool}` |
| POST | `app/open-at-login` | `{open: bool}` | `200` |
| GET | `app/is-emoji-panel-supported` | — | `{supported: bool}` |
| POST | `app/show-emoji-panel` | — | `200` |

`app/path/{name}` accepts Electron's `app.getPath()` names (`home`, `appData`,
`userData`, `sessionData`, `temp`, `exe`, `module`, `desktop`, `documents`,
`downloads`, `music`, `pictures`, `videos`, `recent`, `logs`, `crashDumps`).
An invalid name throws inside Electron.

> `app/relaunch` never sends a response. A client that awaits it will hang until the
> process dies. Fire and forget.

---

## 3. System — 10 endpoints

| Method | Path | Request | Response |
|---|---|---|---|
| GET | `system/can-prompt-touch-id` | — | `{result: bool}` |
| POST | `system/prompt-touch-id` | `{reason}` | `200`, or `400 {error}` |
| GET | `system/can-encrypt` | — | `{result: bool}` |
| POST | `system/encrypt` | `{string}` | `{result: base64}`, or `400 {error}` |
| POST | `system/decrypt` | `{string: base64}` | `{result: string}`, or `400 {error}` |
| GET | `system/printers` | — | `{printers: PrinterInfo[]}` |
| POST | `system/print` | `{printer, html, settings?}` | `200` / `500` |
| POST | `system/print-file` | `{path, printer, settings?}` | `200` / `500 {error}` |
| POST | `system/print-to-pdf` | `{html, settings?}` | `{result: base64}` / `400 {error}` |
| GET | `system/theme` | — | `{result: 'system'\|'light'\|'dark'}` |
| POST | `system/theme` | `{theme}` | `{result: theme}` |

`system/printers` reads from `BrowserWindow.getAllWindows()[0]` — **throws if no
window exists**. The HTML print endpoints spin up a hidden `BrowserWindow` and load
the HTML as a data URL; `print` merges `{silent:true, deviceName:printer}` under the
caller's `settings`.

`print-file` takes a path on the **runtime's** filesystem, not the PHP process's,
and is PDF-only: it parses the file's MediaBox to size the page and answers
`500 {error}` when it cannot read the file or cannot find one. It then waits 1.5s
after load for PDFium to paint before starting the job, so budget a longer timeout
than the other calls.

> `print-to-pdf` builds its data URL as `data:text/html;base64;charset=UTF-8,${html}`
> — a malformed media type (`;base64;` is not a valid parameter and the payload is
> passed unencoded). Send percent-encoded HTML and expect quirks.

---

## 4. Menu bar / tray — 9 endpoints

**All nine send `200` before doing anything.** Errors are invisible.

| Method | Path | Request |
|---|---|---|
| POST | `menu-bar/create` | see below |
| POST | `menu-bar/show` | — (throws if no active menu bar) |
| POST | `menu-bar/hide` | — (same) |
| POST | `menu-bar/resize` | `{width, height}` |
| POST | `menu-bar/label` | `{label}` |
| POST | `menu-bar/tooltip` | `{tooltip}` |
| POST | `menu-bar/icon` | `{icon: path}` |
| POST | `menu-bar/context-menu` | `{contextMenu: MenuItem[]}` |
| POST | `menu-bar/show-context-menu` | — |

`menu-bar/create` request: `url`, `label`, `tooltip`, `icon`, `width`, `height`,
`minWidth`, `minHeight`, `maxWidth`, `maxHeight`, `resizable`, `alwaysOnTop`,
`vibrancy`, `backgroundColor`, `transparency`, `showDockIcon`, `onlyShowContextMenu`,
`windowPosition` (default `trayCenter`), `showOnAllWorkspaces` (default `false`),
`contextMenu`, `webPreferences`.

`label`, `tooltip` and `url` are optional only on paper. The runtime passes `label` to
`Tray.setTitle()` and, in tray-only mode, `tooltip` to `Tray.setToolTip()` with no guard;
both take a required string and refuse anything else, so an absent key throws *after* the
`200` has gone out. `url` becomes the popover's `index`, and the vendored menubar library
substitutes `file://<appPath>/index.html`, which a NativePHP build does not have. Always
send them — `''`, `''` and `url('/')` are the upstream defaults.

Two distinct modes:

- `onlyShowContextMenu: true` → a bare `Tray` with a context menu, no window.
  Hides the dock icon unless `showDockIcon`.
- otherwise → a `menubar()` instance (vendored library at `libs/menubar/`) with
  `preloadWindow: true`, `activateWithApp: false`.

Calling `create` a second time destroys the existing tray and **suppresses the
`MenuBarCreated` event** — so `MenuBarCreated` fires only on first creation.
Default icon: `state.icon` with `icon.png` → `IconTemplate.png`.
Default context menu when none supplied: a single `{role:'quit'}` item.
`right-click` also hides the window and pops the context menu (non-tray-only mode).

---

## 5. Menu, dock, context menu — 11 endpoints

| Method | Path | Request | Response |
|---|---|---|---|
| POST | `menu` | `{items: MenuItem[]}` | `200` — replaces the application menu |
| POST | `dock` | `{items: MenuItem[]}` | `200` |
| POST | `dock/show` | — | `200` |
| POST | `dock/hide` | — | `200` |
| POST | `dock/icon` | `{path}` | `200` |
| POST | `dock/bounce` | `{type: 'critical'\|'informational'}` | `200` — id stored in state |
| POST | `dock/cancel-bounce` | — | `200` — cancels the stored id |
| GET | `dock/badge` | — | `{label: string}` |
| POST | `dock/badge` | `{label}` | `200` |
| POST | `context` | `{entries: MenuItem[]}` | `200` (sent first) |
| DELETE | `context` | — | `200` (sent first) — disposes the handler |
| POST | `progress-bar/update` | `{percent: float}` | `200` — applied to **all** windows |

All `dock/*` endpoints call `app.dock.*`, which **is undefined on Windows and Linux**
— those requests throw. Guard by platform on the client.

`progress-bar/update` has no `id`: it sets the progress bar on every window. `-1`
clears it (Electron convention).

### MenuItem shape

Consumed by `api/helper/index.ts::compileMenu`, applied recursively to `submenu`
(which may be either an array or `{submenu: [...]}`).

| `type` | Extra keys | Behaviour |
|---|---|---|
| `link` | `url`, `openInBrowser?`, `event?`, `id?`, `label` | Rewritten to `normal`. On click: fires the event, then either `shell.openExternal(url)` or navigates the focused window to `url` (with `?_windowId=` appended). |
| `checkbox` / `radio` | `checked`, `event?`, `id?`, `label` | Toggles `checked` **in the runtime's copy** then fires the event. |
| `role` | `role`, `label?` | Reduced to `{role, label?}` — every other key is dropped. |
| anything else | `label`, `id?`, `event?`, plus any Electron `MenuItemConstructorOptions` | Default click handler fires the event. |

Click payload — `{item: {id, label, checked}, combo}`, dispatched under
`item.event` if set, else `\Native\Desktop\Events\Menu\MenuItemClicked`.

> `checkbox`/`radio` state is mutated on the runtime side only. The app is told via
> the event payload; it is not queryable afterwards.

---

## 6. Dialogs and alerts — 4 endpoints

| Method | Path | Request | Response |
|---|---|---|---|
| POST | `dialog/open` | `{title?, defaultPath?, buttonLabel?, filters?, message?, properties?, windowReference?}` | `{result: string[] \| undefined}` |
| POST | `dialog/save` | same | `{result: string \| undefined}` |
| POST | `alert/message` | `{message, type?, title?, detail?, buttons?, defaultId?, cancelId?}` | `{result: int}` — index of the clicked button |
| POST | `alert/error` | `{title, message}` | `{result: true}` |

All four are **synchronous and blocking** (`showOpenDialogSync`, `showMessageBoxSync`,
`showErrorBox`) — the runtime's event loop is stalled until the user responds, so no
other API request is served meanwhile. `result` is `undefined` on cancel.

`windowReference` is a window `id`; when it resolves the dialog is modal to that
window, otherwise app-modal. Null/undefined keys are stripped before the Electron
call (`utils.ts::trimOptions`), so `filters` etc. can be omitted safely.

---

## 7. Clipboard — 7 endpoints

All accept an optional `?type=clipboard|selection` **query** parameter (default
`clipboard`; `selection` is X11-only).

| Method | Path | Request | Response |
|---|---|---|---|
| GET | `clipboard/text` | — | `{text: string}` |
| POST | `clipboard/text` | `{text}` | `{text}` |
| GET | `clipboard/html` | — | `{html: string}` |
| POST | `clipboard/html` | `{html}` | `{html}` |
| GET | `clipboard/image` | — | `{image: dataURL \| null}` |
| POST | `clipboard/image` | `{image: dataURL}` | `200`, or `400 {error}` |
| DELETE | `clipboard` | — | `200` |

`GET clipboard/image` returns `null` for an empty clipboard, otherwise a PNG data URL.

---

## 8. Shell, screen, settings, process — 13 endpoints

| Method | Path | Request | Response |
|---|---|---|---|
| POST | `shell/show-item-in-folder` | `{path}` | `200` |
| POST | `shell/open-item` | `{path}` | `{result: string}` — `''` on success, else an error message |
| POST | `shell/open-external` | `{url}` | `200`, or `500 {error}` |
| DELETE | `shell/trash-item` | `{path}` | `200`, or `400` |
| GET | `screen/displays` | — | `{displays: Display[]}` |
| GET | `screen/primary-display` | — | `{primaryDisplay: Display}` |
| GET | `screen/cursor-position` | — | `Point` — **bare object, not wrapped** |
| GET | `screen/active` | — | `Display` — **bare object**; nearest to the cursor |
| GET | `settings/{key}` | — | `{value: any \| null}` |
| POST | `settings/{key}` | `{value: any}` | `200` |
| DELETE | `settings/{key}` | — | `200` |
| DELETE | `settings` | — | `200` — clears everything |
| GET | `process` | — | `{pid, platform, arch, uptime}` — the **Electron** process |

`screen/cursor-position` and `screen/active` are the only two endpoints in the whole
API that return an unwrapped object. Do not assume a wrapper key.

Settings are backed by `electron-store` (a JSON file in `userData`) and are **not**
the same store as `window-state-*.json`. Every write fires a `SettingChanged` event
per changed key (`state.ts`), including writes made by the app itself — so a naive
listener that writes on change will loop.

**Dots in a key are object paths.** electron-store uses dot-prop, so
`POST settings/a.b` stores `{"a":{"b":…}}` and `GET settings/a.b` reads it back — but
the `SettingChanged` event carries the *root* key `a`, because the store watcher
diffs top-level keys only. Verified: writing `diagnostics.ran` produced an event for
`diagnostics`.

> `DELETE shell/trash-item` responds `res.status(400).json()` with no argument on
> failure, which express rejects — see `ANALYSIS.md` §8.4.

---

## 8b. Global shortcuts — 3 endpoints

| Method | Path | Request | Response |
|---|---|---|---|
| POST | `global-shortcuts` | `{key, event}` | `200` |
| DELETE | `global-shortcuts` | `{key}` | `200` — note: body on a DELETE |
| GET | `global-shortcuts/{key}` | — | `{isRegistered: bool}` |

`key` is an Electron accelerator (`CommandOrControl+Shift+K`). `event` is a
**caller-chosen event name**, pushed back with payload `[key]` (positional) whenever
the accelerator fires — this is the clearest case of the dynamic-event-name path in
§12 being load-bearing rather than a fallback.

`globalShortcut.register()` returns `false` when another application already owns the
accelerator, but the endpoint discards that and answers `200` regardless. Confirm with
`GET global-shortcuts/{key}` if registration matters.

Re-registering the same `key` replaces the previous callback. Shortcuts are process-wide
and are released on quit.

---

## 9. Notifications — 1 endpoint

| Method | Path | Request | Response |
|---|---|---|---|
| POST | `notification` | see below | `{reference: string}` |

Request: `title`, `body`, `subtitle`, `silent`, `icon`, `hasReply`, `timeoutType`,
`replyPlaceholder`, `sound`, `urgency`, `actions`, `closeButtonText`, `toastXml`,
`event?`, `reference?`.

- `reference` is the correlation id returned to the caller **and** echoed in all four
  notification events. Supply your own or accept the generated
  `{timestamp}.{random}`.
- `event` overrides the click event name; the other three are fixed.
- `sound` containing `/` or `\` (and not `http(s)://`) is treated as a **local file**:
  the native sound is silenced and the file is played via `play-sound`. A missing file
  is reported to renderers as a `log` IPC message, not to PHP.
- The runtime keeps a strong reference in `state.notifications` until click or close,
  working around electron#16922 — so a notification that is neither clicked nor
  closed leaks until app exit.

---

## 10. Power monitor — 4 endpoints

| Method | Path | Request | Response |
|---|---|---|---|
| GET | `power-monitor/get-system-idle-state` | `?threshold=` (seconds, default 60) | `{result: 'active'\|'idle'\|'locked'\|'unknown'}` |
| GET | `power-monitor/get-system-idle-time` | — | `{result: int}` seconds |
| GET | `power-monitor/get-current-thermal-state` | — | `{result: 'unknown'\|'nominal'\|'fair'\|'serious'\|'critical'}` |
| GET | `power-monitor/is-on-battery-power` | — | `{result: bool}` |

Listeners are registered at **module import time**, not per request — the 8 power
events flow whether or not the app ever calls these endpoints.

---

## 11. Auto-updater and child processes — 11 endpoints

| Method | Path | Request | Response |
|---|---|---|---|
| POST | `auto-updater/check-for-updates` | — | `200` |
| POST | `auto-updater/download-update` | — | `200` |
| POST | `auto-updater/quit-and-install` | — | `200` |
| POST | `child-process/start` | `ProcessSettings` | `ProcessHandle` |
| POST | `child-process/start-node` | `ProcessSettings` | `ProcessHandle` |
| POST | `child-process/start-php` | `ProcessSettings` | `ProcessHandle` |
| POST | `child-process/stop` | `{alias}` | `200` |
| POST | `child-process/restart` | `{alias}` | `ProcessHandle`, or `410` |
| POST | `child-process/message` | `{alias, message}` | `200` (also `200` if unknown) |
| GET | `child-process/get/{alias}` | — | `ProcessHandle`, or `410` |
| GET | `child-process` | — | `Record<alias, ProcessHandle>` |

**`ProcessSettings`**: `alias` (unique key), `cmd: string[]`, `cwd`, `env`,
`spawnTimeout` (default 30000), `persistent`, `handlesOwnShutdown`, `iniSettings`
(php only).

**`ProcessHandle`**: `{pid, proc, settings}` — `proc` is an Electron `UtilityProcess`
and serialises to a useless object; read `pid`. On the synchronous return path `pid`
is `null` because `spawn` has not fired yet, so **`pid` is only reliable via
`GET child-process/get/{alias}`** or the `ProcessSpawned` event.

Semantics:

- `start` runs the command under Electron's bundled Node via a shim
  (`dist/server/childProcess.js`); `start-node` is the same with
  `USE_NODE_RUNTIME=1`; `start-php` prepends the PHP binary plus merged
  `-d key=value` ini flags and injects the full `NATIVEPHP_*` env.
- **`start*` is idempotent by alias** — an existing alias returns the existing handle
  and starts nothing.
- `persistent: true` restarts the process 1s after any exit (a watchdog, not a
  supervisor: no backoff beyond that fixed delay).
- `stop` sets `persistent = false` first, then either `SIGTERM`s the single pid
  (`handlesOwnShutdown`, non-Windows) or tree-kills.
- `restart` stops, polls for deletion up to 5s, then starts with the captured
  settings. Note it captures settings *before* stopping and `{...undefined}` yields
  `{}`, so the `410` branch is unreachable — an unknown alias starts a process with
  empty settings.
- On quit: framework processes are killed, then `stopAllProcesses()`, then up to 12s
  of drain waiting for `state.processes` to empty.

**Laravel leak**: `start-php` special-cases `cmd[0] === 'artisan'` to prepend the
secure-bundle path. A framework-neutral runtime keys this off the manifest's CLI name
(§14).

---

## 12. Reverse channel — 46 events

`POST /_native/api/events` with `{event: string, payload?: array|object}`.

Reference dispatch (`DispatchEventFromAppController`):

```php
class_exists($event) ? event(new $event(...$payload)) : event($event, $payload);
```

Two behaviours any port **must** reproduce:

1. **`...$payload` spreads positionally for list payloads and as named arguments for
   string-keyed payloads.** Both appear below. Getting this wrong breaks roughly half
   the events.
2. **Unknown event names still dispatch**, as a name + array payload. Apps choose
   their own event names for shortcuts, menu items and notifications, so this path is
   load-bearing, not a fallback.

Event names arrive with inconsistent leading backslashes. Normalise before comparing.

| Event (under `Native\Desktop\Events\`) | Payload | Kind |
|---|---|---|
| `Windows\WindowBlurred` | `[id]` | positional |
| `Windows\WindowFocused` | `[id]` | positional |
| `Windows\WindowMinimized` | `[id]` | positional |
| `Windows\WindowMaximized` | `[id]` | positional |
| `Windows\WindowUnmaximized` | `[id]` | positional |
| `Windows\WindowShown` | `[id]` | positional |
| `Windows\WindowHidden` | `[id]` | positional |
| `Windows\WindowClosed` | `[id]` | positional |
| `Windows\WindowResized` | `[id, width, height]` | positional |
| `Windows\WindowFullscreened` | `[id]` | positional |
| `Windows\WindowUnfullscreened` | `[id]` | positional |
| `App\OpenedFromURL` | `[url]` **or** `{url}` | **both** — `open-url` sends a list, Windows/Linux `second-instance` sends an object |
| `App\OpenFile` | `[path]` | positional |
| `Menu\MenuItemClicked` | `{item:{id,label,checked}, combo}` | named |
| `MenuBar\MenuBarCreated` | *none* | — |
| `MenuBar\MenuBarShown` | *none* | — |
| `MenuBar\MenuBarHidden` | *none* | — |
| `MenuBar\MenuBarClicked` | `{combo, bounds, position}` | named |
| `MenuBar\MenuBarRightClicked` | `{combo, bounds}` | named |
| `MenuBar\MenuBarDoubleClicked` | `{combo, bounds}` | named |
| `MenuBar\MenuBarDroppedFiles` | `[files]` | positional (one arg, an array) |
| `Notifications\NotificationClicked` | `{reference, event}` | named — overridable name |
| `Notifications\NotificationActionClicked` | `{reference, index, event}` | named |
| `Notifications\NotificationReply` | `{reference, reply, event}` | named |
| `Notifications\NotificationClosed` | `{reference, event}` | named |
| `ChildProcess\ProcessSpawned` | `[alias, pid]` | **positional** |
| `ChildProcess\ProcessExited` | `{alias, code}` | **named** |
| `ChildProcess\MessageReceived` | `{alias, data}` | named |
| `ChildProcess\ErrorReceived` | `{alias, data}` | named |
| `ChildProcess\StartupError` | `{alias, error}` | named |
| `PowerMonitor\PowerStateChanged` | `{state: 'on-ac'\|'on-battery'}` | named |
| `PowerMonitor\ThermalStateChanged` | `{state}` | named |
| `PowerMonitor\SpeedLimitChanged` | `{limit}` | named |
| `PowerMonitor\ScreenLocked` | *none* | — |
| `PowerMonitor\ScreenUnlocked` | *none* | — |
| `PowerMonitor\Shutdown` | *none* | — |
| `PowerMonitor\UserDidBecomeActive` | *none* | — |
| `PowerMonitor\UserDidResignActive` | *none* | — |
| `Settings\SettingChanged` | `{key, value}` | named |
| `AutoUpdater\CheckingForUpdate` | *none* | — |
| `AutoUpdater\UpdateAvailable` | `{version, files, releaseDate, releaseName, releaseNotes, stagingPercentage, minimumSystemVersion}` | named |
| `AutoUpdater\UpdateNotAvailable` | same 7 keys | named |
| `AutoUpdater\UpdateCancelled` | same 7 keys | named |
| `AutoUpdater\UpdateDownloaded` | same 7 keys **+ `downloadedFile`** | named |
| `AutoUpdater\DownloadProgress` | `{total, delta, transferred, percent, bytesPerSecond}` | named |
| `AutoUpdater\Error` | `{name, message, stack}` | named |

Note `ChildProcess\ProcessSpawned` is positional while every other `ChildProcess`
event is named — a genuine inconsistency to encode, not to tidy.

`App\ApplicationBooted` is **not** in this table: it is dispatched by the app itself
inside the booted handler, never pushed by the runtime.

**Caller-named events** are additional to the 44 and have no fixed names. Three
sources: `global-shortcuts` (payload `[key]`, positional), any MenuItem carrying an
`event` key (payload `{item, combo}`), and `notification`'s `event` override (payload
`{reference, event}`). A port that only handles the 44 class names will drop all three.

### `POST /_native/api/booted`

No body. The app must respond by opening its windows. Called at the end of runtime
boot, and again on macOS `activate` when no windows are visible. **Must be
idempotent** — `window/open` on an existing id is a no-op show+focus, so the natural
implementation already is.

### `POST /api/broadcast` (app → runtime → renderers)

`{event: string, payload: any}` → IPC `native-event` to every window and the menu bar
window. Nothing reaches PHP. This is the app's route to its own front end.

---

## 13. Environment contract

Set on every PHP process the runtime spawns (`php.ts::getDefaultEnvironmentVariables`).

**Framework-neutral (17)**

| Variable | Value |
|---|---|
| `APP_ENV` | `local` in dev, else `production` — **Laravel's env names**, hardcoded. Symfony 8 rejects `local` outright (`Kernel::getAllowedEnvs()`), so this is one of the values a manifest must parameterise. |
| `APP_DEBUG` | `true` in dev, else `false` |
| `NATIVEPHP_RUNNING` | always `true` — the flag that says "you are inside the runtime" |
| `NATIVEPHP_API_URL` | `http://127.0.0.1:{apiPort}/api/` — only once the API is up |
| `NATIVEPHP_SECRET` | the 32-char shared secret — only once the API is up |
| `NATIVEPHP_STORAGE_PATH` | `{userData}/storage` |
| `NATIVEPHP_DATABASE_PATH` | `{userData}/database/database.sqlite` |
| `NATIVEPHP_{USER_HOME,APP_DATA,USER_DATA,DESKTOP,DOCUMENTS,DOWNLOADS,MUSIC,PICTURES,VIDEOS,RECENT}_PATH` | the matching `app.getPath()`; empty string if unavailable |
| `NATIVEPHP_EXTRAS_PATH` | `{resources}/../extras` packaged, else `{APP_PATH}/extras` |

`NATIVEPHP_API_URL` and `NATIVEPHP_SECRET` are absent from the `native:config` and
`native:php-ini` invocations, which run *before* the API server exists. A command that
needs the client must tolerate their absence.

**Laravel-shaped (8)** — these are the ones a manifest should generalise:
`APP_ENV`'s values (see above), `LARAVEL_STORAGE_PATH`, plus (secure builds only) `APP_SERVICES_CACHE`,
`APP_PACKAGES_CACHE`, `APP_CONFIG_CACHE`, `APP_ROUTES_CACHE`, `APP_EVENTS_CACHE`, and
`VIEW_COMPILED_PATH` (currently commented out).

**Optional**: `NIGHTWATCH_TOKEN`, `NIGHTWATCH_INGEST_URI`.

**Read, not written, by the runtime**: `APP_PATH` (dev/testing app root),
`NODE_ENV`, `SHELL_VERBOSITY` (>0 enables PHP command logging),
`NATIVEPHP_NO_FOCUS`.

**Default PHP ini flags** always passed as `-d`: `memory_limit=512M`,
`curl.cainfo={caCert}`, `openssl.cafile={caCert}`, merged under whatever
`native:php-ini` returned, merged under a child process's own `iniSettings`.

---

## 14. What the runtime requires of the app

Seven requirements. Current Laravel values on the left of the arrow, the manifest key
that should replace them on the right.

| Requirement | Today | Proposed manifest key |
|---|---|---|
| CLI entrypoint | `artisan` | `cli` |
| Config command printing JSON to stdout | `native:config` | `commands.config` |
| PHP-ini command printing JSON to stdout | `native:php-ini` | `commands.phpIni` |
| `php -S` router script | `vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php` | `router` |
| Document-root cwd | `public` | `docroot` |
| Dirs to create + seed under `userData` | `storage/framework/{cache,sessions,views,testing}`, `storage/logs`, `bootstrap/cache` | `writableDirs[]` |
| Cache-path env vars (secure builds) | `APP_*_CACHE` | `cacheEnv{}` |
| Boot / event endpoints | `/_native/api/booted`, `/_native/api/events` | fixed, no key needed |

Optional lifecycle commands, skipped cleanly if absent: `optimize` (pre-serve, prod
only), `migrate --force` (pre-serve, prod only, version-gated), `schedule:run`
(every 60s), `nightwatch:agent`.

### Config keys the runtime consumes

Of the whole `nativephp` config, `index.ts` reads exactly **five**:

```
app_id                                   → setAppUserModelId()
deeplink_scheme                          → setAsDefaultProtocolClient()
updater.enabled                          → checkForUpdatesAndNotify()
updater.default                          → selects the provider key
updater.providers.{default}.public_url    → setFeedURL({provider:'generic', url})
```

Everything else in that file is read by the PHP-side build tooling only. A minimal
conforming `native:config` emits those five keys.

### Security model

Three layers, all sharing one secret:

1. Cookie `_php_native = {secret}`, set by the runtime into the Electron session for
   `http://localhost:{phpPort}`.
2. Header `X-NativePHP-Secret: {secret}`, injected into every renderer request to
   `http://127.0.0.1:{phpPort}/*` via `webRequest.onBeforeSendHeaders`, and set
   explicitly on `notifyLaravel` posts.
3. App-side middleware: accept if either matches, else `403`. Exempt the cookie-issuing
   route only.

The PHP dev server listens on a real loopback TCP port; this secret is the only thing
separating the app from any local browser or process that can reach it. Port it
faithfully.

---

## 15. Coverage

| Section | Endpoints |
|---|---|
| Window | 22 |
| App | 19 |
| System | 11 |
| Menu bar | 9 |
| Menu / dock / context / progress | 12 |
| Child process | 8 |
| Global shortcuts | 3 |
| Clipboard | 7 |
| Shell | 4 |
| Screen | 4 |
| Settings | 4 |
| Power monitor | 4 |
| Dialog | 2 |
| Alert | 2 |
| Auto-updater | 3 |
| Notification | 1 |
| Process | 1 |
| Broadcast | 1 |
| Debug (dev only) | 1 |
| **Total** | **118** |

Plus 2 inbound endpoints (`_native/api/{events,booted}`), 46 runtime-pushed event
types, 24 environment variables, and 5 consumed config keys.
