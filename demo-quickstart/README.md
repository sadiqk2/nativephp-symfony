# Quickstart — the smallest app that works

Everything [`../README.md`](../README.md#adding-it-to-an-existing-symfony-app) tells you to
write, as a real, runnable Symfony app instead of a series of snippets to assemble yourself.
One bundle (desktop only), one config file, one bootstrapper, one route.

Where [`../demo`](../demo/README.md) is the full showcase — both bundles, four screens, a
native menu, settings, dialogs, child processes, native-UI mobile screens — this is the
opposite end: the least amount of code a real, working native window needs. Diff the two if
you want to see what a feature costs to add.

## What's here

| File | What it is |
|---|---|
| [`config/packages/native_desktop.yaml`](config/packages/native_desktop.yaml) | The three fields that matter to start: `name`, `app_id`, `version`. |
| [`src/Native/Bootstrapper.php`](src/Native/Bootstrapper.php) | The entire app-startup contract, verbatim from the README: one window, opened once. |
| [`config/routes/native_desktop.yaml`](config/routes/native_desktop.yaml) | The routes import `native:install` writes for you — included here so this app is complete without running the installer first. |
| [`src/Controller/HomeController.php`](src/Controller/HomeController.php) | One route. It asks `ClientInterface::isAvailable()` and shows which mode it's in — the one line an app needs to tell "running natively" from "running in a browser". |

That's the whole app. No notes, no menu, no settings, no mobile — `../demo` covers that
ground.

## Running it

Without a runtime, this is an ordinary Symfony app:

```bash
composer install
php -S 0.0.0.0:8080 -t public public/index.php
```

`GET /` renders with a `plain browser` badge. Verified: `composer install` resolves and
installs against a path repository to `../bundle`, `php bin/console native:doctor` reports
both endpoints reachable and `App\Native\Bootstrapper` wired with nothing left to wire by
hand, and `php -S` served the home page with the badge in the `plain` state.

With the Electron runtime, follow [`../demo/README.md`](../demo/README.md#running-it) for the
container setup, then from this directory:

```bash
bin/console native:install --source=/path/to/np-desktop/resources/electron
bin/console native:run
```

The same page now renders with a `runtime connected` badge, because
`NATIVEPHP_API_URL`/`NATIVEPHP_SECRET` are set and `isAvailable()` is true.

## What was actually verified here

This app has no Electron runtime available in this environment (no display, no Docker
daemon), so — like `../demo`'s own honesty section — here is exactly what was and was not
checked:

- `composer install` against `../bundle` as a path repository: **verified**.
- `bin/console native:doctor`: **verified** — both runtime endpoints resolve and
  `App\Native\Bootstrapper` is wired.
- `bin/console native:config` in `prod`: **verified** — prints valid JSON and nothing else,
  the one rule this whole ecosystem depends on.
- Serving `/` with `php -S` and reading the `plain browser` badge back: **verified**.
- Opening a real Electron window and seeing the `runtime connected` badge: **not verified
  here** — no Node/Electron/display in this sandbox. `../demo` is the app that has been
  driven through a real headless run; this one has not.
