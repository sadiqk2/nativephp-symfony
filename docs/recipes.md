# Recipes

Complete, working answers to the things people actually build. Every sample here is written
against the real classes; check the signatures in
[`desktop-api.md`](desktop-api.md) / [`mobile-api.md`](mobile-api.md) if you extend them.

- [A multi-window app, and the `_windowId` convention](#a-multi-window-app-and-the-windowid-convention)
- [Running a Messenger worker](#running-a-messenger-worker)
- [Reacting to a runtime event with a typed listener](#reacting-to-a-runtime-event-with-a-typed-listener)
- [A native-UI screen with state](#a-native-ui-screen-with-state)
- [Building for distribution](#building-for-distribution)

---

## A multi-window app, and the `_windowId` convention

The runtime appends `?_windowId=<id>` to every navigation it performs. That parameter is how
a request knows which window it came from — and it is the whole mechanism, so it is worth
understanding before you rely on it.

```php
<?php

namespace App\Native;

use Native\Symfony\Contract\AppBootstrapper;
use Native\Symfony\Window\WindowManager;

final class Bootstrapper implements AppBootstrapper
{
    public function __construct(private readonly WindowManager $windows)
    {
    }

    public function boot(): void
    {
        $this->windows->open('main')
            ->url('/')
            ->size(1100, 760)
            ->minSize(640, 480)
            ->title('Ledger')
            ->rememberState()
            ->open();
    }
}
```

Opening a second window from a controller, and closing it from inside itself:

```php
<?php

namespace App\Controller;

use Native\Symfony\Window\WindowManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class InspectorController extends AbstractController
{
    public function __construct(private readonly WindowManager $windows)
    {
    }

    #[Route('/inspector/open/{id}', name: 'inspector_open')]
    public function open(string $id): Response
    {
        // One window per inspected entity. open() is idempotent by id, so clicking
        // twice focuses the existing window instead of stacking a second one.
        $this->windows->open('inspector:'.$id)
            ->url($this->generateUrl('inspector_show', ['id' => $id]))
            ->size(520, 640)
            ->title('Inspector — '.$id)
            ->open();

        return $this->redirectToRoute('home');
    }

    #[Route('/inspector/{id}', name: 'inspector_show')]
    public function show(string $id): Response
    {
        return $this->render('inspector.html.twig', [
            'id' => $id,
            // Which window am I? Null outside the runtime, or if the navigation
            // did not carry the parameter.
            'windowId' => $this->windows->detectId(),
        ]);
    }

    #[Route('/inspector/{id}/close', name: 'inspector_close', methods: ['POST'])]
    public function close(string $id): Response
    {
        // No id argument: close() falls back to the window this request came from,
        // recovered from the Referer. A form POST is exactly the case the
        // Referer-before-URL order exists for.
        $this->windows->close();

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
```

Three rules that follow from the mechanism:

1. **Keep the fallback order.** `detectId()` reads the Referer first, then the current URL,
   then gives up (`resolveId()` then defaults to `'main'`). A form POST carries the window in
   its Referer, not its own URI. "Improving" this order makes multi-window apps target the
   wrong window.
2. **Make these responses `no-store`.** `?_windowId=…` is part of the URL for HTTP cache
   purposes, and without it two windows can share a cached page.
3. **Do not open windows outside a request** unless you pass an absolute URL or set
   `native_desktop.base_url`. `UrlResolver` builds the base from the current request because
   the dev server's port is chosen by the runtime and never published to PHP; outside a
   request it throws a `LogicException` that says exactly this.

Reading the window back:

```php
$window = $this->windows->get('inspector:'.$id);   // ?Window
$current = $this->windows->current();              // ?Window — may be null; never chain on it
$open = $this->windows->all();                     // list<Window>
```

## Running a Messenger worker

```php
<?php

namespace App\Native;

use Native\Symfony\Contract\AppBootstrapper;
use Native\Symfony\Process\MessengerWorker;
use Native\Symfony\Window\WindowManager;

final class Bootstrapper implements AppBootstrapper
{
    public function __construct(
        private readonly WindowManager $windows,
        private readonly MessengerWorker $worker,
    ) {
    }

    public function boot(): void
    {
        $this->windows->open('main')->url('/')->size(1000, 700)->open();

        // Idempotent by alias, which matters: /booted can fire more than once and
        // this must not start a second consumer.
        $this->worker->up(
            alias: 'async',
            transports: ['async'],
            memoryLimit: 256,   // MB
            timeLimit: 3600,    // seconds, then it exits and the watchdog restarts it
            sleep: 1,
        );
    }
}
```

Watch it, and stop it:

```php
$handle = $worker->status('async');    // ?ProcessHandle

if (null === $handle || !$handle->isRunning()) {
    $worker->up(alias: 'async', transports: ['async']);
}

$worker->down('async');
```

What `up()` does that you would otherwise have to remember: `persistent: true` so the
runtime's watchdog restarts a worker that exited on `--memory-limit`;
`handlesOwnShutdown: true` so shutdown is a plain SIGTERM to that process rather than a
tree-kill that could drop the message in flight; and an ini `memory_limit` of twice
`--memory-limit`, because otherwise PHP fatals before Messenger notices its own limit and
exits cleanly.

The alias on the wire is `messenger_async` — namespaced so a worker cannot collide with your
own process aliases. Use the un-prefixed name in every `MessengerWorker` call; use the
prefixed one when reading `ChildProcessManager::all()` or matching events.

Worker output arrives as events:

```php
#[AsEventListener]
public function onWorkerOutput(MessageReceived $event): void
{
    if ('messenger_async' === $event->alias) {
        // Not line-buffered: one event may carry several lines or part of one.
        $this->logger->info(rtrim($event->data));
    }
}
```

For a one-off job instead of a consumer, use `ChildProcessManager::console()` — same
supervision, no watchdog:

```php
$processes->console('import', ['app:import', $path]);
```

## Reacting to a runtime event with a typed listener

Typed events dispatch under their class name, so `#[AsEventListener]` on a typed parameter is
all a listener needs:

```php
<?php

namespace App\Native;

use Native\Symfony\Event\App\ApplicationBooted;
use Native\Symfony\Event\ChildProcess\ProcessExited;
use Native\Symfony\Event\Settings\SettingChanged;
use Native\Symfony\Event\Windows\WindowResized;
use Native\Symfony\Settings\SettingsManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final class RuntimeListener
{
    public function __construct(
        private readonly SettingsManager $settings,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[AsEventListener]
    public function onBooted(ApplicationBooted $event): void
    {
        $this->logger->info('The runtime finished booting.');
    }

    /**
     * User-driven resizes only. A resize your own code requested through
     * WindowManager::resize() does NOT arrive here.
     */
    #[AsEventListener]
    public function onResized(WindowResized $event): void
    {
        $this->settings->set("geometry:{$event->id}", [$event->width, $event->height]);
    }

    #[AsEventListener]
    public function onSettingChanged(SettingChanged $event): void
    {
        // Fires for writes this app makes itself — the runtime watches the store,
        // not the caller. Writing from here would loop.
        // And $event->key is the ROOT key: writing 'a.b' reports 'a'.
        $this->logger->debug('setting {key} changed', ['key' => $event->key]);
    }

    #[AsEventListener]
    public function onProcessExited(ProcessExited $event): void
    {
        $this->logger->warning('{alias} exited with {code}', [
            'alias' => $event->alias,
            'code' => $event->code,
        ]);
    }
}
```

### Caller-named events

A global shortcut, a menu item with an `event:`, or a notification with `event()` pushes back
a name of *your* choosing. There is no class to key on, so those arrive as `NativeEvent`
under two names — `native.<name>` and `NativeEvent::class`. Register for one, never both:

```php
use Native\Symfony\Event\NativeEvent;

final class ShortcutListener
{
    public function __construct(private readonly GlobalShortcutManager $shortcuts) {}

    public function register(): void
    {
        // register() cannot report failure — another app may already own the
        // accelerator. registerChecked() asks the OS afterwards.
        if (!$this->shortcuts->registerChecked('CommandOrControl+Shift+K', 'App\Shortcut\Palette')) {
            // fall back to an in-app binding
        }
    }

    #[AsEventListener(event: 'native.App\Shortcut\Palette')]
    public function onPalette(NativeEvent $event): void
    {
        // $event->name is the name you chose; $event->payload is whatever came with it
    }
}
```

While porting, a catch-all is the fastest way to see what the runtime is really sending:

```php
#[AsEventListener]
public function onAnything(NativeEvent $event): void
{
    $this->logger->debug('native event {name}', [
        'name' => $event->name,
        'payload' => $event->payload,
    ]);
}
```

Test any of this without a runtime — see [Testing](testing.md#pushing-events-into-the-app).

## A native-UI screen with state

Mobile only, and the least device-verified path in the project. Read
[the mobile getting-started page](getting-started-mobile.md#path-b--the-native-ui-render-path)
first.

```php
<?php

namespace App\Screen;

use Native\Symfony\Mobile\Ui\Component\NativeAction;
use Native\Symfony\Mobile\Ui\Component\NativeComponent;
use Native\Symfony\Mobile\Ui\Element;
use Native\Symfony\Mobile\Ui\Elements\Button;
use Native\Symfony\Mobile\Ui\Elements\Column;
use Native\Symfony\Mobile\Ui\Elements\Row;
use Native\Symfony\Mobile\Ui\Elements\Text;
use Native\Symfony\Mobile\Ui\Elements\TextInput;

final class TodoScreen extends NativeComponent
{
    /** @var list<string> */
    private array $items = [];

    private string $draft = '';

    #[NativeAction]
    public function draftChanged(string $value): void
    {
        // The argument comes from InteractionEvent, narrowed by event type:
        // TEXT_CHANGE always yields exactly one string.
        $this->draft = $value;
    }

    #[NativeAction]
    public function add(): void
    {
        if ('' !== trim($this->draft)) {
            $this->items[] = $this->draft;
            $this->draft = '';
        }
    }

    #[NativeAction]
    public function remove(int $index): void
    {
        // Literal arguments in the expression come first, event values after.
        unset($this->items[$index]);
        $this->items = array_values($this->items);
    }

    protected function render(): Element
    {
        $rows = [];

        foreach ($this->items as $index => $item) {
            $rows[] = Row::make(
                Text::make($item),
                Button::make('×')->onPress("remove({$index})"),
            )
                // Keyed, so removing row 0 does not hand row 1's native state to it.
                ->key('item-'.$item);
        }

        return Column::make(
            Text::make('Todo')->fontSize(24)->fontWeight('bold'),
            TextInput::make($this->draft)
                ->placeholder('What needs doing?')
                ->onChange('draftChanged'),
            Button::make('Add')->onPress('add'),
            ...$rows,
        )->layout(['gap' => 8, 'padding' => 16]);
    }
}
```

Driving it:

```php
use Native\Symfony\Mobile\Ui\Component\ComponentScreenFactory;
use Native\Symfony\Mobile\Ui\Component\InteractionEvent;

$screen = $factory->open(new TodoScreen());   // one fresh instance per screen
$frame = $screen->frame();                    // first paint

// An interaction as the native layer delivers it:
$screen->handle(['callback_id' => $screen->callbackId('add'), 'type' => InteractionEvent::PRESS]);
$screen->handle([
    'callback_id' => $screen->callbackId('draftChanged'),
    'type' => InteractionEvent::TEXT_CHANGE,
    'text' => 'Buy milk',
]);

$screen->close();   // unmounts the graph, drops the diff state
```

Things this recipe is quietly getting right:

- **Only `#[NativeAction]` methods are reachable.** Drop the attribute and the button becomes
  a `CallbackRefused` at dispatch — which `frame()` surfaces eagerly through
  `assertCallbacksDispatchable()` rather than leaving you with a dead button on a device.
- **`key()` on anything reorderable.** Identity is otherwise positional.
- **A fresh component per screen.** Binding an instance twice throws, deliberately: reuse
  would carry state and children across invisibly.
- **`handle()` may return `null`.** That means the id belongs to no live component — routine
  when the device is still showing a superseded frame.
- Children are mounted with `$this->mount(Child::class, ['prop' => $value], key: $k)`, and
  props are assigned to public non-readonly properties. An unknown prop name throws.

## Building for distribution

```bash
composer require nativephp/php-bin          # static PHP binaries + cacert.pem, no PHP deps
bin/console native:build linux x64 --dir    # unpacked directory — the fast smoke test
bin/console native:build linux x64          # installer
bin/console native:build mac arm64
bin/console native:build win x64 --publish  # publish to the configured updater provider
```

The pipeline: pre-build hooks → stage the app into `nativephp/build/app` → `composer install
--no-dev` there → clean the staged `.env` → install `cacert.pem` and icons → patch the
Electron project's `package.json` with your app identity → electron-builder → post-build
hooks. `--skip-composer` reuses the staged vendor dir; `-v` shows subprocess output.

Five things to know before you ship.

**Builds contain readable PHP source.** Upstream's protected build needs a bundle from
Bifrost, NativePHP's hosted service, which currently targets Laravel's entry points.
`native:build` prints a warning on every run rather than letting that pass unnoticed.

**`APP_SECRET` is protected from your own configuration.** `build.env_keep` wins over
`build.env_remove` and defaults to `['APP_SECRET']`. Laravel's upstream cleanup list globs
`*_SECRET`, which is safe there (its key is `APP_KEY`) and fatal here: `framework.yaml` reads
`%env(APP_SECRET)%`, so a stripped one means the packaged app throws
`EnvNotFoundException` at container build and never renders anything. Adding a broad glob must
not let you break your own build. Only the staged copy is ever touched; your own `.env` is
not modified.

**Without `cacert.pem` the packaged app has no outbound TLS.** It comes from
`vendor/nativephp/php-bin`. The build warns rather than failing, because a build that fails
for a missing optional file is worse than one that tells you what will not work.

**Packaged apps have no opcache.** The static musl PHP binary cannot dynamically load
extensions, so every request pays full compile cost — Laravel's builds too. Warm `var/cache`
at build time rather than relying on runtime caching. The manifest's default `optimize` step
(`cache:warmup`) covers this on a manifest-aware runtime; on a patched one, add it to
`prebuild` or let the runtime's optimize step do it.

**`var/cache` and `var/log` must exist in the package.** They are in `build.exclude` (a dev
machine's cache must not ship) and in `build.keep`, which plants a placeholder because
electron-builder prunes empty directories and dotfiles do not stop it. Symfony will not boot
without them.

Signing and notarisation: the environment plumbing is in `BuildCommand`, untested because it
needs real credentials. Installer targets beyond `--dir` are invoked already but need `fpm`,
`dpkg` or an AppImage runtime present.
