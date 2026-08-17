# native-symfony/desktop-bundle

Build desktop applications with Symfony, on NativePHP's Electron runtime.

Status: **M3 complete.** All **116 runtime endpoints** and all **44 events** are
implemented, and `native:build` produces a distributable app that has been **built and
run** — see `../M3-RESULTS.md`. 357 tests.

Not done yet: installer targets beyond `--dir`, and code signing (the env plumbing is
there, untested without real credentials).

The vendor name is provisional — `nativephp/*` is someone else's brand, and asking
comes before claiming it.

## What it gives you

```php
final class Bootstrapper implements AppBootstrapper
{
    public function __construct(private readonly WindowManager $windows) {}

    // The runtime POSTs /_native/api/booted at the end of its boot and this
    // decides what appears. That is the entire app-startup contract.
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

```php
#[AsEventListener]
public function onResized(WindowResized $event): void
{
    // Typed, not an array: $event->id, $event->width, $event->height
}
```

Autowired services, no facades: `WindowManager`, `AppManager`, `NativePaths`,
`ClientInterface`, `RuntimeBroadcaster`.

## Install

```bash
composer require native-symfony/desktop-bundle

# The runtime lives in your project, not in vendor/ — see "Why patch?" below.
git clone --depth 1 https://github.com/NativePHP/desktop /tmp/np-desktop
bin/console native:install --source=/tmp/np-desktop/resources/electron

bin/console native:manifest   # declare the app's paths for the runtime
bin/console native:run
```

`native:install` patches the runtime, and skips it automatically when it detects one
that already reads a `nativephp.json` — so once the upstream manifest change lands,
`native:manifest` is the whole story and nothing is patched.

Then implement `Native\Symfony\Contract\AppBootstrapper` on any service. The bundle
autoconfigures and aliases it — no wiring needed.

Routes cannot be auto-registered (Symfony bundles cannot register their own), so
`native:install` writes the import for you:

```yaml
# config/routes/native_desktop.yaml — written by native:install
native_desktop:
    resource: '@NativeDesktopBundle/src/Resources/config/routes.php'
    type: php
```

It only ever creates the file, never rewrites an edited one without `--force`, and warns
instead of guessing if there is no `config/routes/`. Without this import the app boots into
a window that shows nothing, because `/booted` 404s and `boot()` is never called.

## Configuration

```yaml
native_desktop:
    app_id: com.example.app        # the runtime passes this to setAppUserModelId()
    version: '1.0.0'
    deeplink_scheme: myapp         # enables the OpenedFromURL event
    base_url: ~                    # fallback for absolute window URLs outside a request
    php_ini:
        memory_limit: 512M
    updater:
        enabled: false
    events:
        allowed_namespaces: []     # see "Security" below
    block_browser_access: true     # leave this on
```

## Commands

| Command | Purpose |
|---|---|
| `native:run` | Start the app in development |
| `native:install --source=…` | Copy, patch and build the Electron runtime |
| `native:config` | Print the startup config as JSON — **the runtime calls this** |
| `native:php-ini` | Print PHP ini overrides as JSON — **the runtime calls this** |
| `native:build [os] [arch]` | Package for distribution; `--dir` skips installer generation |
| `native:schedule-tick` | Once-a-minute tick the runtime drives; no-op by default |

The two marked commands are part of the wire contract. They print nothing but JSON on
stdout, and they run before the runtime's API server exists — so they must never
assume `NATIVEPHP_API_URL` is set.

## Why patch the runtime?

The runtime hardcodes eight Laravel-specific values as string literals in its
TypeScript: the `artisan` CLI name (six call sites), the router script path
(`vendor/laravel/framework/.../server.php`), a `storage/` directory it copies
unconditionally, `APP_ENV=local`, and `schedule:run`. `RuntimePatcher` rewrites them.

This is not a fork. `native:install --publish` upstream already mirrors the whole
Electron project into the application's own `nativephp/electron/`, and the runtime
prefers that copy whenever a `package.json` exists there. The proper fix is a manifest
file declaring those eight values, which is a small behaviour-preserving PR — see
`../ANALYSIS.md` §6. Until then, each app patches its own copy. `RuntimePatcher` is
idempotent and **fails loudly** if a hunk's target has moved, because silently skipping
one produces an app that boots into a Laravel router path and 404s everything.

## Building

```bash
composer require nativephp/php-bin       # static PHP binaries + cacert.pem, no PHP deps
bin/console native:build linux x64       # or mac / win, x64 / arm64
bin/console native:build linux x64 --dir # unpacked directory, much faster to check
```

Two things to know before you ship.

**Builds contain readable source.** Upstream's protected build needs a bundle from
Bifrost, NativePHP's hosted service, which currently targets Laravel's entry points.
`native:build` warns rather than letting it pass unnoticed.

**`APP_SECRET` is protected from your own config.** The `build.env_keep` list wins over
`build.env_remove`, and defaults to `APP_SECRET`. Laravel's upstream cleanup list globs
`*_SECRET`, which is safe there (its key is `APP_KEY`) and fatal here — `framework.yaml`
reads `%env(APP_SECRET)%`, so a stripped one means the packaged app cannot boot. Adding a
broad glob must not let you break your own build.

Also worth knowing: the static PHP binary cannot load opcache, so packaged apps — Laravel's
too — run without it. Warm `var/cache` at build time rather than relying on runtime caching.

## Security

Two deliberate divergences from the Laravel implementation.

**Event instantiation.** Upstream's controller does
`class_exists($event) ? new $event(...$payload)` straight from the request body — any
autoloadable class, with attacker-chosen constructor arguments, if the shared secret
leaks. Here only the known event map and namespaces you list in
`events.allowed_namespaces` are constructed; everything else becomes a `NativeEvent`
carrying the payload as data. Identical behaviour for legitimate traffic.

**Browser access.** `RuntimeAccessSubscriber` rejects any request carrying neither the
`_php_native` cookie nor the `X-NativePHP-Secret` header, at priority 4096 — above the
firewall. The app is served by PHP's built-in server on a real loopback port; that
secret is the only thing keeping other local processes out. It fails *open* when the
runtime supplied no secret at all, since 403ing every request would leave no way to
diagnose the misconfiguration.

## Two behaviours worth knowing

**Your own resizes do not emit `WindowResized`.** The runtime listens for Electron's
`resized`, which fires for user drags but not `setSize()`. `window/resize` succeeds and
`window/get` reflects it; no event arrives.

**`window/open` is idempotent by id.** An existing id is shown and focused and nothing
is created, which is why calling it from `boot()` is safe even though the runtime posts
`/booted` again on macOS `activate`.

## Tests

```bash
composer install && vendor/bin/phpunit
```

357 tests, 780 assertions. `ContractCoverageTest` parses the runtime's own express
routers and fails if an endpoint goes uncovered or an event name goes unmapped, so
upstream drift breaks the suite rather than surfacing a release later.

The ones that matter most cover the dual argument spreading (list payloads
positionally, string-keyed payloads as named arguments — get this wrong and half the 44
runtime events break), the `_windowId` Referer-before-URL order, the patcher's
idempotency and its refusal to skip a hunk, that an unlisted class is never
instantiated from a request body, that `confirm()` binds `cancelId` so a dismissed
dialog cannot mean "yes", and that the dock is a no-op off macOS.
