# M3 — the build pipeline

`native:build` packages a Symfony application into a distributable desktop app, and
**the packaged app has been built and run**, not just built. 235 tests.

Screenshot: `spike/shot-packaged.png` — that window is the packaged binary from
`dist/linux-unpacked`, running the staged app under the static PHP from `php-bin`.

---

## The pipeline

`Builder` + `BuildCommand` mirror upstream's sequence, because the shape is dictated by
electron-builder's `extraResources` entry: whatever sits at `$NATIVEPHP_BUILD_PATH` is
copied into the package, so "building" means assembling that directory.

1. **Pre-build hooks** — shell commands from config, run in the project root. A failure
   stops the build before anything is staged.
2. **Stage** the app into `nativephp/build/app`, skipping excluded paths. The walk uses a
   `RecursiveCallbackFilterIterator`, so an excluded directory is never descended into —
   the difference between a fast build and copying `node_modules` before deleting it.
3. **`composer install --no-dev`** in the build directory, then drop `vendor/nativephp/php-bin`
   (tens of megabytes of binaries for every platform, only one of which is needed, and it
   lives outside `app/`) and `vendor/bin`.
4. **Clean the `.env`** — strip secrets, force `APP_ENV=prod` / `APP_DEBUG=0`. Only the
   staged copy is touched; the developer's own file is never modified, and there is a test
   for that.
5. **CA bundle and icons** — `cacert.pem` from `php-bin` (without it the packaged app has
   no working outbound TLS), icons into both the build dir and electron-builder's
   `buildResources`.
6. **Patch `package.json`** — electron-builder reads the app's identity from there, not
   from our config.
7. **electron-builder**, whose `beforeBuild` hook runs `php.js` to unzip the *target*
   platform's static PHP over whatever the dev machine left behind.
8. **Post-build hooks.**

`--dir` produces an unpacked directory instead of an installer, which skips needing `fpm`,
`dpkg` or an AppImage runtime — the fast path for checking a build actually works.

### `nativephp/php-bin` is a clean dependency

Worth stating because it was not obvious: `php-bin` has **zero PHP dependencies**. It is
static binaries for linux/mac/win × x64/arm64 × PHP 8.3/8.4/8.5, plus `cacert.pem`,
multi-licensed. So a Symfony app can require it without pulling in anything Laravel —
unlike `nativephp/desktop`, which would drag in `illuminate/contracts`,
`laravel/prompts` and `spatie/laravel-package-tools`.

---

## Proof: the packaged app runs

Built for linux-x64, then launched from `dist/linux-unpacked` under Xvfb:

```
PHP Server started on port: 8100
  … serving resources/build/app/public/nativephp-router.php   ← the staged app
Process [diag] spawned!
Process [diag] exited with code [0].
```

The window reports, from inside the package:

| | |
|---|---|
| `Symfony 8.1.4 on PHP 8.4.21` | **8.4.21 is the static musl binary from php-bin** — the container's own PHP is 8.4.24, so this is the packaged runtime, not a leak from the host |
| `electron app version 1.0.0` | our configured version, not Electron's 2.0.0 → the `package.json` patch took effect |
| `userData path /tmp/.config/symfony-native-demo` | the slugged app name → identity flowed all the way through |
| menu bar `App │ Demo │ Edit` | the custom application menu, in a packaged build |
| `WindowShown ["main"]`, `WindowFocused ["main"]` | channel C alive in the package |
| `_windowId detected: main` | the renderer's own request carried it |

And every diagnostic passes in the packaged app, including a clipboard round-trip, a
notification, a settings write/read/forget cycle, and a child process spawning and exiting.

Artifact size: 406 MB unpacked (115 MB of that is Electron, 67 MB the PHP binary).

---

## Findings

### 1. Laravel's `.env` cleanup patterns destroy a Symfony app

This is the important one. Upstream's `cleanup_env_keys` globs `*_SECRET`. That is
harmless in Laravel, whose application key is `APP_KEY` — and lethal here, because
Symfony's is **`APP_SECRET`**, read by `config/packages/framework.yaml` as
`%env(APP_SECRET)%`. Strip it and the packaged app throws `EnvNotFoundException` at
container build: it does not boot at all.

I inherited the pattern list and the first real build stripped it. Nothing in the build
output complained — the failure would only have appeared when someone ran the shipped app.

Fixed with more than a narrower default, because a user adding `*_SECRET` themselves is a
perfectly reasonable thing to do: there is now an `env_keep` list that **wins over**
`env_remove`, defaulting to `APP_SECRET`. The blanket `*_SECRET` / `*_KEY` /
`*_TOKEN` / `*_PASSWORD` globs are also gone from the defaults in favour of named vendor
prefixes. Test: `testAppSecretSurvivesEvenABlanketSecretGlob`.

This generalises. Any Laravel-shaped default list carried across needs re-reading against
Symfony's own names, not just translating — the two frameworks collide on `APP_*`.

### 2. Packaged apps have no opcache

Every invocation of the `php-bin` binary prints:

```
Warning: Failed loading Zend extension 'opcache' (… Dynamic loading not supported)
```

The static musl build cannot dynamically load extensions, and its baked-in ini asks for
opcache anyway. So a production NativePHP app — Laravel or Symfony — runs **without**
opcache, and every request pays full compile cost. For Symfony that argues for warming
`var/cache` at build time rather than relying on runtime caching.

The warning also arrives HTML-escaped (`<br />`) into stderr, which means the binary ships
with `html_errors=1` — cosmetic, but it makes the runtime's logs harder to read.

### 3. The Bifrost question, half answered

The unprotected build path **works for Symfony** — that is now demonstrated rather than
assumed. `native:build` prints a warning saying so, because shipping readable source
should be a decision, not a surprise.

What is still unanswered is the protected path: upstream branches on the presence of
`build/__nativephp_app_bundle`, fetched from Bifrost per project and used *both* as the
`php -S` router and prepended to every CLI invocation. Whether that server-side bundler
can target `public/index.php` + `bin/console` instead of the Laravel pair is not
knowable from the open-source client. Still needs @simonhamp.

### 4. A composer path repo copied rather than symlinked into the build

The demo consumes the bundle through a path repo with `symlink: true`, so I expected the
staged `vendor/sadiqk2/nativephp-symfony-desktop-bundle` to be a dangling symlink in
the artifact.
It came out as a real directory — `composer install --no-dev` in the build directory
materialised it. Convenient, but not something to rely on: a real distribution should
install the bundle from a registry, and anyone building from a path repo should check the
artifact rather than assume.

---

## What is left

- **M4** — the upstream PRs. Eight manifest parameters, plus the bug list, which M3 grows:
  the unguarded `parseFloat(zoomFactor)`, the unguarded `storage/` copy, the swallowed
  config-command failures, the hardcoded `schedule:run`, the dead
  `config('native-php.secret')` route, `window/current`'s missing null guard, and
  `shell/trash-item`'s argument-less `res.json()`. Each stands on its own merits.
- Installer targets beyond `--dir` (AppImage, deb, dmg, NSIS) — the pipeline invokes them
  already; they need an environment with the packaging tools, not more code.
- Signing and notarisation: the env plumbing is in `BuildCommand`, untested because it
  needs real credentials.
