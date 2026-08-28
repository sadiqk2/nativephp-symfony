# Desktop API reference

Every service below is registered `public` and autowirable by class name. There are no
facades and no static helpers: inject `WindowManager`, `DialogManager`, `SettingsManager`
and so on. If you came from the Laravel version, that is the largest day-to-day difference —
`Window::open()` becomes an injected `$this->windows->open()`.

This page shows the shape of each area and flags what behaves surprisingly. For the
exhaustive list of all 116 endpoints with request and response shapes, read
[`../CONTRACT.md`](../CONTRACT.md); it is the specification, this is the guide.

## Three things that apply everywhere

**A dispatch is not an outcome.** Most endpoints answer `200` with the body `OK`, and
several — every `menu-bar/*` endpoint and both `context` ones — send that `200` *before*
doing any work. A successful call means the runtime received your request. Nothing more.

**Unknown ids are silently ignored.** The runtime uses optional chaining on its window map,
so `$windows->close('typo')` is a no-op, not an error.

**Outside the runtime, every call throws.** `Client::isAvailable()` is false when
`NATIVEPHP_API_URL` is unset, and any call then throws
`Native\Symfony\Desktop\Client\RuntimeNotAvailable`. Code that also runs as an ordinary web request
must guard:

```php
if ($this->client->isAvailable()) {
    $this->windows->title('Saved');
}
```

`Native\Symfony\Desktop\Client\RuntimeCallFailed` covers the other two failures: a transport error,
and a `403` (the shared secret did not match).

---

## Windows

`Native\Symfony\Desktop\Window\WindowManager` — 21 endpoints.

```php
$windows->open('settings')            // returns PendingWindow; nothing happens until open()
    ->url('/settings')
    ->size(700, 500)
    ->minSize(400, 300)
    ->title('Settings')
    ->rememberState()
    ->open();

$windows->title('Unsaved changes');   // current window
$windows->resize(1200, 800, 'main');
$windows->position(x: 40, y: 40, animate: true, id: 'main');
$windows->navigate('/reports', 'main');
$windows->close('settings');
```

`PendingWindow` covers all 39 `window/open` keys — geometry, `frameless()`,
`transparent()`, `vibrancy()`, `titleBarStyle()`, `kiosk()`, `alwaysOnTop()`,
`skipTaskbar()`, `hiddenInMissionControl()`, `webPreferences()`,
`preventLeavingDomain()`, `preventLeavingPage()`, `suppressNewWindows()`, `showDevTools()`.
Read the class; it is a flat list of one-line setters.

Reads:

```php
$window = $windows->get('main');   // ?Window — null on 404
$window = $windows->current();     // ?Window — null when the runtime failed the call
$all    = $windows->all();         // list<Window>
```

`Window` is a readonly value object with 21 typed properties (`id`, `x`, `y`, `width`,
`height`, `title`, `url`, `focused`, `resizable`, …).

**Surprises worth internalising.**

- `open()` **is idempotent by id.** An existing id is shown and focused and nothing is
  created. This is why calling it from `AppBootstrapper::boot()` is safe even though
  `/booted` fires again on macOS `activate`.
- **`current()` is fallible by design.** The runtime calls
  `BrowserWindow.getFocusedWindow().id` with no null guard, so when the app is backgrounded
  it throws on its side and answers 500. You get `null`, never an exception. Never write
  `$windows->current()->id`.
- **A programmatic resize emits no `WindowResized`.** The runtime listens for Electron's
  `resized`, which fires for user drags but not `setSize()`. `window/resize` succeeds and
  `window/get` reflects the new size; no event arrives.
- **`zoomFactor` is effectively required, and the bundle sends it for you.** The runtime
  does `setZoomFactor(parseFloat(zoomFactor))` on `dom-ready` with no guard, so an absent
  value is `NaN` and renders the page at an absurd zoom. `PendingWindow::open()` defaults it
  to `1.0`.
- **The document `<title>` never reaches the title bar.** The runtime `preventDefault()`s
  Electron's `page-title-updated`. `$windows->title()` is the only way.
- Window URLs must be absolute. `UrlResolver` builds them from the current request, so
  calling `open()` or `navigate()` **outside** an HTTP request throws a `LogicException`
  unless you pass an absolute URL or set `native_desktop.base_url` — the dev server's port
  is assigned by the runtime and never published to PHP.

### The `_windowId` convention

The runtime appends `?_windowId=<id>` to every navigation it performs. Every mutation on
`WindowManager` takes an optional `$id` and falls back to `detectId()`, which reads
`_windowId` from the **Referer first, then the current URL** — in that order, because a form
POST carries the window in its Referer, not its own URI. If neither has it, the id is
`'main'`.

```php
$id = $windows->detectId();   // ?string
```

## Menus

`Native\Symfony\Desktop\Menu\Menu` is an immutable builder; `MenuManager` sends it.

```php
use Native\Symfony\Desktop\Enums\MenuRole;
use Native\Symfony\Desktop\Menu\Menu;

$menu = Menu::new()
    ->submenu('App', Menu::new()
        ->role(MenuRole::About)
        ->separator()
        ->role(MenuRole::Quit))
    ->submenu('File', Menu::new()
        ->label('New report', event: 'App\Menu\NewReport', accelerator: 'CommandOrControl+N')
        ->link('Documentation', 'https://example.com/docs', openInBrowser: true)
        ->separator()
        ->checkbox('Dark mode', checked: true, event: 'App\Menu\ToggleDark'))
    ->submenu('Edit', Menu::new()->role(MenuRole::EditMenu));

$menus->set($menu);          // the application menu
$menus->context($menu);      // the page context menu
$menus->removeContext();
```

Item types: `label()`, `link()`, `checkbox()`, `radio()`, `role()`, `separator()`,
`submenu()`, plus `Menu::make(MenuItem ...$items)` and `add()` if you want to construct
`Native\Symfony\Desktop\Menu\Items\*` yourself. `MenuRole` covers 42 Electron roles.

**Every method returns a new `Menu`.** `$menu->label('x');` on its own does nothing —
assign the result.

**Routing clicks.** An item with an `event:` name arrives as a `NativeEvent` under
`native.<that name>`, which is how you route clicks without inspecting a payload. An item
*without* one arrives as `Event\Menu\MenuItemClicked`, whose `$item` array carries
`id`, `label` and `checked` (with `id()`, `label()`, `checked()` accessors). For checkboxes
and radios, `checked` is the state the runtime has *already* flipped to on its side and is
not queryable afterwards — persist it if you need it.

### Menu bar (tray)

`MenuBarManager` — 9 endpoints, macOS-flavoured but functional elsewhere.

```php
$menuBar->create()
    ->label('7 open')
    ->tooltip('My App')
    ->icon('/abs/path/icon.png')
    ->url('/tray')
    ->size(360, 420)
    ->contextMenu($menu)
    ->create();

$menuBar->label('8 open');
$menuBar->showContextMenu();
$menuBar->hide();
```

Set `onlyShowContextMenu()` for a tray icon with no popover window. Remember shape 3 of the
response convention: every `menu-bar/*` endpoint acknowledges before acting, so a `200` here
tells you nothing about success.

- **`label`, `tooltip` and `url` are effectively required, and the bundle sends them for
  you.** The runtime hands `label` to `Tray.setTitle()` and, in tray-only mode, `tooltip`
  to `Tray.setToolTip()`, both of which take a required string and refuse anything else —
  and an absent `url` makes the popover load a `file://` path a NativePHP build does not
  have. `PendingMenuBar::create()` defaults them to `''`, `''` and `/`, matching upstream.

### Dock (macOS only)

`DockManager` — `menu()`, `show()`, `hide()`, `icon()`, `badge()`, `setBadge()`,
`bounce(DockBounce::Informational)`, `cancelBounce()`. Every method checks the platform and
returns quietly off macOS, where `app.dock` is `undefined` and the request would throw. Your
calling code needs no platform checks.

## Dialogs

`Native\Symfony\Desktop\Dialog\DialogManager` — 4 endpoints, **all of which block the runtime's
event loop until the user answers**. The client's timeout is 3600s for exactly this reason.

```php
$paths = $dialogs->open()
    ->title('Choose a spreadsheet')
    ->filters([['name' => 'Spreadsheets', 'extensions' => ['csv', 'xlsx']]])
    ->multiple()
    ->open();                       // list<string>; [] when cancelled

$one = $dialogs->open()->directories()->openOne();   // ?string

$target = $dialogs->save()
    ->defaultPath('/home/me/report.pdf')
    ->confirmOverwrite()
    ->save();                       // ?string; null when cancelled

$index = $dialogs->alert('Delete 4 items?', ['Delete', 'Cancel'], cancelId: 1);

if ($dialogs->confirm('Discard unsaved changes?')) {
    // …
}

$dialogs->error('Could not start', 'The database file is locked.');
```

Notes:

- `confirm()` binds `cancelId` to the second button, so Escape and the window close button
  both mean "no". Electron otherwise maps them to index 0 — a dismissed dialog silently
  meaning "yes". If you call `alert()` directly, pass `cancelId` yourself.
- An open dialog with no properties defaults to picking files, since Electron accepts it but
  it is useless.
- Cancellation arrives as a *missing* `result` key, not an empty list. The wrappers
  normalise that to `[]` / `null`.
- `error()` is the only dialog that works before the app is ready and needs no window, which
  makes it the one usable way to report a boot failure.
- `attachedTo($windowId)` makes a dialog window-modal; `properties(DialogProperty ...)` sets
  Electron's raw property list.

## Notifications

```php
$reference = $notifications->create()
    ->title('Export finished')
    ->body('report.pdf is ready')
    ->actions(['Open', 'Reveal'])       // macOS; index reported by NotificationActionClicked
    ->withReply('Reply…')               // macOS
    ->event('App\Notification\ExportDone')
    ->show();                           // returns the reference

$notifications->send('Export finished', 'report.pdf is ready');
```

The returned reference correlates the four notification events: `NotificationClicked`,
`NotificationActionClicked`, `NotificationReply`, `NotificationClosed`. With `event()` set,
clicks arrive as a `NativeEvent` under that name instead. `toastXml()` is Windows-only and
overrides title and body entirely.

## Clipboard

```php
use Native\Symfony\Desktop\Enums\ClipboardType;

$clipboard->setText('hello');
$text = $clipboard->text();
$clipboard->setHtml('<b>hello</b>');
$dataUrl = $clipboard->image();                       // ?string, a data: URL
$clipboard->setImage($dataUrl);
$clipboard->clear();

$clipboard->text(ClipboardType::Selection);           // X11 primary selection
```

The buffer is selected by query string, as the runtime requires. `Selection` is meaningful
on Linux only.

## Settings

`SettingsManager` wraps the runtime's electron-store — a JSON file under `userData` that
survives restarts. Unrelated to remembered window geometry, which lives in its own files.

```php
$settings->set('theme', 'dark');
$theme = $settings->get('theme', 'light');
$settings->forget('theme');
$settings->clear();
```

Two behaviours that cost real debugging time:

**Every write fires `SettingChanged`, including writes you make yourself.** The runtime
watches the store, not the caller. A listener that writes on change will loop.

**Dots are object paths, not part of the key.** electron-store uses dot-prop, so
`set('a.b', 1)` stores `{"a":{"b":1}}`. `get('a.b')` reads it back correctly — but the
`SettingChanged` event reports the **root** key `a`, because the runtime's store watcher
diffs top-level keys only. Use a flat separator such as `:` if you need the event key to
match the key you wrote. The same dot-prop behaviour applies to mobile's `SecureStorage`.

## Child processes and Messenger workers

`ChildProcessManager` — 8 endpoints. These run as Electron `utilityProcess`es, which is the
point: the runtime supervises them, restarts the persistent ones, and tears them all down on
quit with a 12-second drain window. A process started here outlives the request that started
it, unlike anything spawned with `symfony/process` from a web request.

```php
$handle = $processes->console('import', ['app:import', 'data.csv']);
$handle = $processes->php('worker', ['bin/console', 'app:work'], persistent: true);
$handle = $processes->node('vite', ['node_modules/.bin/vite', 'build']);
$handle = $processes->start('tail', ['tail', '-f', '/var/log/app.log']);

$processes->message('import', ['pause' => true]);
$processes->stop('import');
$processes->restart('import');

$processes->get('import');   // ?ProcessHandle — null on 410
$processes->all();           // array<string, ProcessHandle>
```

`ProcessHandle` exposes `$alias`, `$pid`, `$cmd`, `$settings`, `isRunning()`,
`isPersistent()`. Note `$pid` is often `null` on start — the runtime has not observed the
spawn yet; the `ProcessSpawned` event carries it.

- **Every start is idempotent by alias.** Starting an existing alias returns the existing
  handle and starts nothing.
- `php()` gets the runtime's own PHP binary, its ini flags, and the full `NATIVEPHP_*`
  environment including the API URL and secret — so a child can call back into the runtime.
- `message()` answers `200` even for an unknown alias, so it cannot confirm delivery.
- `restart()` on an unknown alias is meant to 410 but does not: the runtime captures
  settings before stopping, and `{...undefined}` is `{}` in JavaScript, so you get a process
  with empty settings. Check the returned handle.
- Output arrives as events: `MessageReceived` (stdout) and `ErrorReceived` (stderr), neither
  line-buffered — one event may carry several lines or half of one.

### Messenger

`MessengerWorker` runs `messenger:consume` as a supervised child process. It is the Symfony
counterpart to Laravel's `QueueWorker`, and the argument names are the real difference
(`--memory-limit=128M` not `--memory=128`, `--time-limit` not `--timeout`, transports
positional rather than `--queue=a,b`).

```php
$worker->up();                                            // alias messenger_messenger
$worker->up(alias: 'async', transports: ['async'], memoryLimit: 256, timeLimit: 3600);
$worker->status('async');                                 // ?ProcessHandle
$worker->down('async');
```

It always starts with `persistent: true` (the runtime's watchdog restarts a worker that
exited on `--memory-limit`) and `handlesOwnShutdown: true` (a plain SIGTERM rather than a
tree-kill, so Messenger can finish the message in flight). The ini `memory_limit` is set to
twice `--memory-limit`, because otherwise PHP fatals before Messenger notices its own limit.
Aliases are namespaced `messenger_*` so they cannot collide with your own.

## Events

The runtime pushes 44 typed events plus any number of caller-named ones. Typed events
dispatch under their class name, so a listener is ordinary Symfony:

```php
use Native\Symfony\Desktop\Event\Windows\WindowResized;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final class WindowListener
{
    #[AsEventListener]
    public function onResized(WindowResized $event): void
    {
        // typed, not an array: $event->id, $event->width, $event->height
    }
}
```

Namespaces under `Native\Symfony\Desktop\Event\`: `Windows\` (9), `App\` (3, including
`ApplicationBooted` which the bundle dispatches itself), `Menu\`, `MenuBar\` (7),
`Notifications\` (4), `ChildProcess\` (5), `PowerMonitor\` (8), `Settings\`,
`AutoUpdater\` (7).

**Caller-named events** — from global shortcuts, menu items with an `event`, and
notification overrides — have no class to key on, so they arrive as `NativeEvent` and are
dispatched **twice**: once under `native.<name>`, once under `NativeEvent::class`.

```php
#[AsEventListener(event: 'native.App\Menu\NewReport')]
public function onNewReport(NativeEvent $event): void {}

#[AsEventListener]                       // every caller-named event — useful while porting
public function onAny(NativeEvent $event): void
{
    $this->logger->debug($event->name, $event->payload);
}
```

Register for one or the other. A listener registered for both runs twice.

`NativeEvent` carries `$name`, `$payload` and a `get($key, $default)`. If a known event's
payload cannot satisfy its class constructor — a runtime upgrade changed a shape —
`EventFactory` degrades to a `NativeEvent` with `__error` in the payload rather than 500ing
the endpoint, because the runtime swallows non-2xx answers here.

### Broadcasting to the front end

Laravel sniffs every dispatched event with a wildcard listener. Symfony has no wildcard
hook, so intent is declared on the event:

```php
use Native\Symfony\Desktop\Contract\BroadcastsToRuntime;

final class ImportProgressed implements BroadcastsToRuntime
{
    public function __construct(public readonly int $percent) {}

    public function broadcastAs(): string
    {
        return self::class;
    }

    public function broadcastPayload(): array
    {
        return ['percent' => $this->percent];
    }
}
```

Dispatch it normally; `BroadcastingDispatcher` (which decorates `event_dispatcher`) forwards
it to `POST /api/broadcast`, and the page receives it through `window.Native.on()`. This
reaches renderers only — it never comes back to PHP. A failed broadcast is logged, never
thrown, so it cannot take down whatever dispatched it.

An app ported from Laravel must add this interface to events that previously only declared a
`nativephp` channel.

## The rest, briefly

| Service | What it does | Worth knowing |
|---|---|---|
| `App\AppManager` | `quit()`, `relaunch()`, `show()`, `hide()`, `isHidden()`, `locale()`, `version()`, `path(AppPath)`, `badgeCount()`, `openAtLogin()`, recent documents, emoji panel | `path()` queries live; prefer `NativePaths` for the ten paths already in the environment |
| `Support\NativePaths` | `home()`, `userData()`, `documents()`, `downloads()`, `storage()`, `database()`, … | Read from `NATIVEPHP_*_PATH`; **no round-trip**, and every getter is nullable |
| `System\SystemManager` | TouchID, keychain `encrypt()`/`decrypt()`, `printers()`, `print()`, `printToPdf()`, `theme()`, `setTheme()` | `canEncrypt()` is false without an OS keyring — including most containers |
| `Screen\ScreenManager` | `displays()`, `primaryDisplay()`, `cursorPosition()`, `activeDisplay()` | The last two return **unwrapped** objects; the wrappers hide that |
| `Shell\ShellManager` | `showInFolder()`, `openPath()`, `openExternal()`, `trash()` | `openExternal()` can answer 500 |
| `PowerMonitor\PowerMonitorManager` | `idleState()`, `idleTime()`, `thermalState()`, `onBatteryPower()` | Returns `IdleState` / `ThermalState` enums |
| `Shortcut\GlobalShortcutManager` | `register($accelerator, $event)`, `registerChecked()`, `unregister()`, `isRegistered()` | `register()` cannot fail visibly — another app may own the accelerator. Use `registerChecked()`. The event arrives as a `NativeEvent` under the name you chose |
| `System\ProgressBar` | `update(float $percent)`, `clear()` | Taskbar/dock progress |
| `System\RuntimeInfo` | `get()` — pid, platform, arch, uptime | |
| `System\DebugLogger` | A PSR-3 logger writing to the devtools console | `/api/debug/*` exists **only** when `NODE_ENV=development`. In a packaged app the first record 404s and the logger latches off, so the rest cost nothing. Re-entrant records are dropped, which is what stops the runtime's own "could not read the reply" error from posting another one |
| `Updater\UpdaterManager` | `checkForUpdates()`, `downloadUpdate()`, `quitAndInstall()` | Needs `native_desktop.updater.enabled: true` and a provider; results arrive as the seven `AutoUpdater\*` events |
| `Contract\ClientInterface` | `isAvailable()`, `get()`, `post()`, `delete()` | The escape hatch for an endpoint with no wrapper. `Response` has `$status`, `$data`, `successful()`, `array()`, `value($key, $default)` |

## Commands

| Command | Purpose |
|---|---|
| `native:install --source=…` | Copy, patch and build the Electron runtime into `nativephp/electron` |
| `native:manifest` | Write `nativephp.json`; `--dry-run` to preview |
| `native:doctor` | Check that the runtime can reach the app: routes, firewall, bootstrapper. Exits non-zero when it cannot |
| `native:run` | Start the app in development |
| `native:build [os] [arch]` | Package for distribution; `--dir` skips installer generation |
| `native:config` | Print the startup config as JSON — **the runtime calls this** |
| `native:php-ini` | Print PHP ini overrides as JSON — **the runtime calls this** |
| `native:schedule-tick` | The once-a-minute tick the runtime drives; a no-op by default |

`ScheduleTickCommand` is deliberately not `final`: override the service to hook up
`symfony/scheduler`, a Messenger dispatch, or anything else that wants a minute tick. The
runtime spawns it aligned to the minute boundary and does not check the command exists —
which is why the patcher retargets it here instead of the bundle shipping a `schedule:run`
that would collide with `symfony/scheduler`'s own.

The two marked commands are part of the wire contract: JSON on stdout and nothing else, and
they run before the runtime's API server exists.
