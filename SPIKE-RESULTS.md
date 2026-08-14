# M1 Spike — Symfony 8 running under the NativePHP desktop runtime

**Result: it works.** A Symfony 8.1.4 app on PHP 8.4.24, in a native Electron window,
driving the runtime's API and receiving its events. No Laravel anywhere in the app.

Reproduce with `spike/README.md`. Screenshots: `spike/shot.png` (first success, with
devtools), `spike/shot-final.png` (final, after a programmatic resize).

---

## What was proven

| Channel | Proof |
|---|---|
| **Boot handshake** | The runtime POSTed `/_native/api/booted`; the Symfony controller answered by calling `window/open`. The window in the screenshot exists *only* because Symfony asked for it. |
| **A — app → runtime (write)** | `window/open` created the window at exactly the requested 1000×720 with the requested title. Then `/resize/760/520` → `window/resize` → `200`. |
| **A — app → runtime (read)** | `window/get/main` read back into Twig: id `main`, size, position, title, focused. After the resize the same read returned **760 × 520** — verified numerically, not from pixels. |
| **B — runtime → app** | Symfony's log recorded `native-event Native\Desktop\Events\Windows\WindowShown {"payload":["main"]}` and the same for `WindowFocused`. Positional payload spreading exercised. |
| **C — runtime → renderer** | The page printed `window.Native present — listening` and then `WindowFocused ["main"]`. **No Livewire, no bridge shim** — the preload's `window.Native` is framework-agnostic and works in a plain Twig page as-is. |
| **Security model** | Request carrying `X-NativePHP-Secret` → `200`. Same request without it → **403**, from the ported `PreventRegularBrowserAccessSubscriber`. |
| **Env contract** | `NATIVEPHP_RUNNING=true` reached Symfony (the page's green badge). `native:php-ini`'s `{"memory_limit":"512M"}` came back through the runtime and appeared in the spawned server's `-d` flags. |

Total app-side code: **7 PHP classes + 1 router script + 1 Twig template.** No bundle,
no abstraction layer, no `illuminate/*`.

---

## The diff against the runtime

Five hunks, two files, all inside `electron-plugin/src/server/`
(`spike/patch-runtime.py` is the executable version):

| Hunk | Change | Why |
|---|---|---|
| 1 | `['artisan', …]` → `['bin/console', …]` (6 sites) + the two `argv[0] === 'artisan'` guards | CLI entrypoint |
| 2 | router script `vendor/laravel/framework/…/server.php` → `public/nativephp-router.php` | Symfony has no built-in-server router script |
| 3 | guard `copySync(appPath/storage, …)` with `existsSync` | Symfony has no `storage/` dir |
| 4 | `settings.cmd[0] === 'artisan'` in `childProcess.ts` | same as 1 |
| 5 | `APP_ENV` `local`/`production` → `dev`/`prod` | **found by running it** — see below |

**No upstream changes were needed.** `native:install --publish` mirrors the whole
Electron project into the app's own `nativephp/electron/`, and
`ElectronServiceProvider::electronPath()` prefers that copy whenever a `package.json`
exists there. So an adapter can ship a patched runtime today and treat the upstream
manifest PR as hygiene rather than a blocker — as predicted in `ANALYSIS.md` §6.

---

## New findings — things only running it revealed

### 1. `APP_ENV=local` is a hard boot failure on Symfony 8 (an 8th Laravel-ism)

`php.ts::getDefaultEnvironmentVariables()` hardcodes Laravel's environment names:

```ts
APP_ENV: process.env.NODE_ENV === 'development' ? 'local' : 'production',
```

Symfony 8 whitelists `APP_ENV` in `App\Kernel::getAllowedEnvs()` (`['prod','dev','test']`)
and throws on anything else:

```
In KernelTrait.php line 587:
  [InvalidArgumentException]
  The environment "local" is not registered as allowed by "App\Kernel::getAllowedEnvs()".
```

Both `native:config` and `native:php-ini` died on this. I had catalogued seven
Laravel-isms in `php.ts` from reading; this is the eighth, and it is the only one that
is a *hard* failure rather than a wrong path. Belongs in the manifest as `env.{dev,prod}`.

### 2. A missing `storage/` directory takes the whole boot down

`ensureAppFoldersAreAvailable()` calls `copySync(join(appPath,'storage'), storagePath)`
unconditionally. `fs-extra`'s `copySync` throws on a missing source, so **any app
without a top-level `storage/` dir dies at boot step 9a** — before the PHP server
starts. Not Symfony-specific: a Laravel app whose storage lives elsewhere hits it too.
One `existsSync` guard fixes it. Add to the upstream findings list.

### 3. Failures in `native:config` / `native:php-ini` are silently swallowed

`index.ts::loadConfig()` and `loadPhpIni()` both wrap the `execFile` in
`try { … } catch (error) { console.error(error) }` and return `{}`. On my first run
both commands were failing and **boot continued to completion** — with no app id, no
deep-link registration, and no updater, because every config key was missing. The app
window still opened, so nothing looked wrong.

This is the same class of problem as the swallowed `notifyLaravel` errors
(`ANALYSIS.md` §8.2): the runtime treats "PHP did not answer" as "PHP said nothing".
For a porter it is the single most expensive behaviour to debug, because a totally
broken config command and a correct one are visually identical.

### 4. `schedule:run` is hardcoded and unconditional

The runtime spawns `<cli> schedule:run` every 60s from the first minute boundary, with
no check that the command exists. For any app without it that is a failing process
every minute, silently. The spike ships a no-op stub. Lifecycle commands belong in the
manifest as optional.

### 5. Programmatic resizes do not emit `WindowResized`

Confirmed empirically: after `window/resize` succeeded and `window/get` reported the
new 760×520, **no `WindowResized` event arrived** — Symfony's log for that run contains
only `WindowShown` and `WindowFocused`. `window.ts` listens for Electron's `resized`,
which fires for user-driven resizes, not `setSize()`. Not a bug, but it means an app
cannot rely on the event to observe its own resizes, and any port's tests must not
expect it. Worth stating in `CONTRACT.md` §1.

---

## Notes on the harness

- No PHP and only Node 12 on the host, so everything runs in a container
  (`spike/Dockerfile`: PHP 8.4 + Node 22 + Electron's system libs + Xvfb).
- `npm run dev` is `node php.js && electron-vite dev --watch`. **`php.js` is skipped**:
  it exists only to unzip a static binary out of the `nativephp/php-bin` composer
  package into `$NATIVEPHP_BUILD_PATH/php/php`. Dropping a real PHP at that path is
  equivalent and removes the dependency on `php-bin` entirely for development.
- `$NATIVEPHP_BUILD_PATH` needs three things: `icon.png`, `cacert.pem`, `php/php`.
- Electron needs `ELECTRON_DISABLE_SANDBOX=1` in a container. The D-Bus and WebGL
  errors in the log are Xvfb artefacts, not runtime problems.
- The shared secret is never logged — the harness lifts it from the PHP dev server's
  `/proc/<pid>/environ` to make an authenticated request. In a real app the renderer
  gets it via `webRequest.onBeforeSendHeaders` and the `_php_native` cookie.

---

## What this changes about the plan

M1's exit criterion is met, and two assumptions are now facts rather than inferences:

- The runtime really is framework-agnostic at the boundary. The Laravel coupling is a
  handful of string literals in one file, not an architecture.
- The adapter needs no upstream cooperation to be *useful*, only to be *tidy*.

`PLAN.md` M2 (the bundle proper) can start from the spike's seven classes. The upstream
manifest PR now has eight concrete parameters to propose, plus three independent bug
fixes (findings 2, 3, 4 here and §8 in `ANALYSIS.md`) that are worth submitting first
on their own merits.
