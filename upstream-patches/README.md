# Upstream patches — four landed, six open, one held

Eleven patches. **Four of the desktop six are now in upstream's `main`**; the rest are
open pull requests, and the eleventh is held deliberately.

| | repo | status |
|---|---|---|
| `0004`, `0005`, `0007` | [`NativePHP/desktop`](https://github.com/NativePHP/desktop) | **merged** — `parseZoomFactor()`, the `SHELL_VERBOSITY` report and the package metadata are all in `main` |
| `0006` | [`NativePHP/desktop`](https://github.com/NativePHP/desktop) | **resolved** — upstream deleted `CreateSecurityCookieController` outright, so the dead namespace went with it |
| `0002`, `0003` | [`NativePHP/desktop`](https://github.com/NativePHP/desktop) | **open PRs [#138](https://github.com/NativePHP/desktop/pull/138) and [#139](https://github.com/NativePHP/desktop/pull/139)**, awaiting review — the two of the original #136–#141 that are still live |
| `0008`–`0011` | [`NativePHP/mobile-air`](https://github.com/NativePHP/mobile-air) | **open PRs [#349–#352](https://github.com/NativePHP/mobile-air/pulls?q=is%3Apr+author%3Asadiqk2)**, awaiting review |
| `0001` | [`NativePHP/desktop`](https://github.com/NativePHP/desktop) | still held — it is the one that needs a maintainer conversation, and the small fixes it was waiting on have now landed |

The order was deliberate and it worked: small, individually reviewable fixes first, so
that the manifest patch — the only one that asks upstream to change a design — arrives to
a maintainer who has already merged code from the same author. Four have now been merged,
which is the precondition `0001` was waiting on.

Nothing in the bundle depends on which of these have landed. `RuntimePatcher` carries
every bug fix into the copy `native:install` writes, with non-strict hunks: a target that
is gone is reported as *"assuming it is fixed upstream"* and the install continues. Run
against today's `main` it applies nine hunks, recognises `0005` as already applied, and
reports both `0004` hunks as fixed at the source.

**Reproducing this table.** The patch files are `git diff`s, so upstream's own tree
answers the question:

```bash
git clone --depth 1 https://github.com/NativePHP/desktop upstream/np-desktop
cd upstream/np-desktop
for p in ../../upstream-patches/*.patch; do
    git apply --check --reverse "$p" 2>/dev/null && echo "landed  $p" && continue
    git apply --check          "$p" 2>/dev/null && echo "open    $p" || echo "moved   $p"
done
```

`moved` means neither direction applies cleanly — read the file before concluding
anything, since it covers both "the fix landed with different surrounding lines" and "the
code has changed underneath the patch". That is how `0004`, `0005` and `0006` were
classified above.

The `mobile-air` four each carry a regression test in the repo's own Pest idiom, written
against its real `TailwindParser`, `NativeRouter` and `CallbackRegistry` rather than a
stub. Every test was checked against unpatched `main` first: 3 of 3, 4 of 6, 3 of 6 and 1
of 3 fail there, which is the only evidence that they test the fix rather than the code.
The full upstream suite passes at 887 with the same 13 pre-existing failures `main` has on
its own (Android splash-screen and release-build cases, unrelated), and `pint` is clean.

Writing those tests also found a dead branch in `0010` — a double quote inside a
double-quoted literal is caught by the delimiter check above it, so the escape branch for
that case was unreachable. Removed before submitting.

| Patch | PR |
|---|---|
| `0008` theme border must not clobber an explicit width | [#349](https://github.com/NativePHP/mobile-air/pull/349) |
| `0011` `dark:` variant buries a theme token's dark value | [#350](https://github.com/NativePHP/mobile-air/pull/350) |
| `0009` optional route segments never resolved | [#351](https://github.com/NativePHP/mobile-air/pull/351) |
| `0010` expression parser corrupts and drops arguments | [#352](https://github.com/NativePHP/mobile-air/pull/352) |

Each patch file here is a `git diff` of the branch that was pushed, so it includes the
tests and matches the pull request exactly.

Every patch applies cleanly to a clean tree, individually and as a series — verified with
`git apply --check`, and re-verified against `mobile-air`'s current `main` before the four
were submitted. `0001` typechecks clean under the project's own `tsc`.

`0008`–`0011` target the **mobile** repo. An earlier note here called it commercial rather
than MIT: that was wrong about the repository, which carries an MIT `LICENSE.md`
(Bifrost Technology, LLC) and declares `"license": "MIT"` in its `composer.json`. What is
commercial is NativePHP Mobile as a *product* — the licence you buy to ship apps — not the
source these patches touch. Submitting them raises nothing that `0002`–`0007` do not.

```bash
git clone https://github.com/NativePHP/desktop && cd desktop
git apply /path/to/upstream-patches/0001-manifest-declared-app-paths.patch
```

## The one that matters

**`0001-manifest-declared-app-paths.patch`** — replaces the eight hardcoded Laravel
values in `php.ts` with values read from an optional `nativephp.json`:

| | Laravel default (unchanged) |
|---|---|
| `cli` | `artisan` |
| `router` | `vendor/laravel/framework/…/server.php` |
| `docroot` | `public` |
| `env.dev` / `env.prod` | `local` / `production` |
| `lifecycle.optimize` / `.migrate` / `.schedule` | `optimize`, `migrate --force`, `schedule:run` |
| `writableDirs` | `storage/framework/*`, `storage/logs`, `bootstrap/cache` (relative to userData) |
| `seedDir` | `storage` |
| `cacheEnv` | the five `APP_*_CACHE` keys |

**Zero behaviour change for Laravel.** With no manifest present the defaults are exactly
the previous literals. `extra.nativephp.manifest` is already the convention in
`nativephp/mobile-ui`, so this follows it rather than inventing a second mechanism.

Three things the patch also fixes as a consequence, each of which is a real bug for any
non-Laravel app and was found by running one:

- The `storage` copy is guarded with `existsSync`. `fs-extra`'s `copySync` throws on a
  missing source, so an app without that directory **died before the PHP server started**.
- Lifecycle commands became optional. `schedule:run` was spawned every 60 seconds with no
  check that it exists, producing a silently failing process per minute.
- A malformed manifest throws rather than falling back. Falling back to Laravel's paths
  would boot the app into the wrong router and 404 every route with no clue why.

### Revised after a second implementation was written against it

Emitting a Symfony manifest from the adapter found four problems in the first draft of
this patch, all now fixed. Worth recording, because they are the kind that only surface
when something other than Laravel tries to use the interface:

- **`optimize` and `migrate` were not actually optional.** They were spread with a
  non-null assertion (`...lifecycle.optimize!`), so declaring `null` — or omitting them,
  which the type permitted — became `...null` and threw inside `serveApp()`, *before the
  PHP server started*. Only `schedule` was guarded. All three now are.
- **`writableDirs` was dead.** It was in the interface and the defaults, but nothing read
  it: the `mkdirpSync` calls ran unconditionally at module load, creating Laravel's
  directories for every app whatever framework it was running. Its declared values did not
  even match what the code created. It is now what drives those calls, deferred to first
  use because `getManifest()` needs `getAppPath()`, which is not resolvable at module load.
- **Nested merging made "none" inexpressible.** Deep-merging `cacheEnv` meant a declared
  `{}` still inherited Laravel's five keys, and there was no way to say "this framework has
  no seed directory". The merge is now top-level only, so a declared key is authoritative.
- **`seedDir` was typed `string`,** which could not express "none" at all; it is now
  `string | null`.

`LARAVEL_STORAGE_PATH` is deliberately left alone — it is the one remaining Laravel-named
value in the environment contract, and renaming it would be a breaking change for anything
reading it.

### Why this is worth taking even without a second framework

It turns eight implicit assumptions into one declared, documented interface. The
`storage`-copy crash and the unconditional `schedule:run` are bugs in the current code
regardless; the manifest is what makes them expressible rather than special-cased.

## The independent fixes

Each stands alone and is worth submitting on its own merits. None depends on `0001`.

| Patch | Fixes |
|---|---|
| `0002` | `window/current` dereferences `getFocusedWindow()` with no null check. It returns null whenever the app is backgrounded — reachable from any PHP process, e.g. a queue worker calling `Window::current()` — and threw a bare string. Now 404s. |
| `0003` | `shell/trash-item` answers `res.status(400).json()` with no argument, which express rejects, turning a handled failure into an unhandled one. |
| `0004` **(merged)** | `window/open` without `zoomFactor` calls `setZoomFactor(parseFloat(undefined))` → `NaN`, rendering the page at an absurd zoom. Invisible from Laravel because `Windows\Window` declares a `1.0` default and always serialises it, so it lands on the first independent client instead. |
| `0005` **(merged)** | `notifyLaravel` swallows every error in an empty `catch {}`, making a crashed, 500ing or 403ing PHP app indistinguishable from a healthy one. Now reported behind `SHELL_VERBOSITY`. |
| `0006` **(resolved by deletion)** | `CreateSecurityCookieController` reads `config('native-php.secret')` — a namespace that does not exist. The guard therefore compared input against `null`, passing only when no secret was sent, then issued a cookie with a `null` value. It is also the one route `PreventRegularBrowserAccess` deliberately lets through unauthenticated. |
| `0007` **(merged)** | `composer.json`: `homepage` points at the archived `nativephp/laravel`, and the `Updater` alias points at `Native\Electron\Facades\Updater`, a class that moved to `Native\Desktop\Drivers\Electron\Facades\Updater`. |

## `0008` — a bug in the mobile Tailwind parser

`parseThemeBorder()` emits `borderWidth: 1` unconditionally, and since classes merge in
order it overwrites an explicit width that came before it:

```
BEFORE (upstream)                              AFTER (patched)
border-2 border-theme-outline → width 1  ✗     → width 2  ✓
border-theme-outline border-2 → width 2  ✓     → width 2  ✓
border-theme-outline          → width 1  ✓     → width 1  ✓
```

So the same two classes produce different results depending on the order they are written
in, with nothing to indicate why. **This affects upstream's own demo:** the pattern occurs
twice in `NativePHP/super-native`, which therefore renders a 1px border where the template
asks for 2px.

The fix has `parseThemeBorder` emit `borderWidthDefault` instead, which `parse()` resolves
after merging — so a theme border still gets a visible width on its own, but never
overrules an explicit one, and the outcome no longer depends on class order.

Found by extracting every class string from `super-native` (1,336 distinct strings, 684
distinct tokens) and running upstream's parser over all of them.

## `0009` — optional route segments were never resolvable in PHP

`NativeRouter::resolve()` builds its pattern regex with
`preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $pattern)`. `\w` does not match `?`, so a
`{slug?}` placeholder survives into the regex literally and the pattern can only ever match
the string `"{slug?}"`.

Verified against upstream's own code:

```
                    BEFORE                        AFTER
/items/42           params {id: 42}   ✓           params {id: 42}      ✓
/posts/hello        NULL              ✗           params {slug: hello} ✓
/posts              NULL              ✗           params {}            ✓
   (routes: /items/{id}, /posts/{slug?})
```

`BootPlanner` on both platforms matches these patterns happily, so **a route using the
documented `{param?}` syntax booted into the native runloop and then resolved to no screen
at all.** The fix handles the optional form first, consuming its leading slash, and drops
empty captures so an omitted segment reads as absent rather than blank.

Found by porting `BootPlanner.matches()` and testing the port against upstream.

## `0010` — the callback expression parser corrupts and drops arguments

`CallbackRegistry::parse()` converts single-quoted argument literals to JSON with
`str_replace("'", '"')` — every apostrophe, whatever its role — and then degrades any
decode failure to `$args ?? []`. Three distinct silent failures, all verified against
upstream's own code:

```
                       BEFORE                        AFTER
save('hello')          ["hello"]        ✓            ["hello"]     ✓
save("don't")          []               ✗ dropped    ["don't"]     ✓
rename('it\'s fine')   ["it\"s fine"]   ✗ CORRUPTED  ["it's fine"] ✓
setName('O'Brien')     []               ✗ silent     [] + logged   ✓
```

The third is the one that matters: no error, no empty result — the handler runs with a
value the author never wrote. `it's fine` becomes `it"s fine`. If that is a name on its way
to a database, it is corrupted with nothing to indicate it.

The fix tracks which quote actually opened the current string, so an apostrophe inside a
double-quoted literal is data rather than a delimiter, and `\'` inside a single-quoted one
becomes a bare apostrophe rather than a double quote.

`args` stays `[]` on a genuine parse failure rather than becoming null, because callers
spread it (`NativeComponent.php:3316`, `:3371`) and a null would be a TypeError —
tightening that contract deserves its own change. But the failure is no longer silent.

## `0011` — `dark:` buries a theme token's dark value

A theme token already resolves its own dark companion, so wrapping it in the `dark:`
variant nested that companion a level deeper — where `parse()`'s merge never lifts it —
and left the **light** hex in the dark slot:

```
                        BEFORE                                  AFTER
bg-theme-surface        {bg:#FFF, dark:{bg:#000}}      ✓        unchanged            ✓
dark:bg-theme-surface   {dark:{bg:#FFF, dark:{bg:#000}}} ✗      {dark:{bg:#000}}     ✓
```

So `dark:bg-theme-surface` rendered **white in dark mode** — the opposite of what it asks
for — and dropped the light-mode value entirely. Asking for a theme token under `dark:`
can only mean its dark-mode form, so the fix promotes the companion instead of nesting it.

Present in upstream's own demo: `super-native` uses `android:dark:bg-theme-surface-variant`.

`0008` and `0011` both touch `TailwindParser.php` and apply together cleanly.

## Suggested order

Submit the small fixes first. They are independently valuable, quick to review, and they
establish that the effort is serious before anything larger is proposed.

1. `0006` — smallest, clearest, and a security-adjacent correctness fix.
2. `0002`, `0003`, `0004` — three small null/argument guards.
3. `0005` — the diagnostic; a maintainer may have opinions on the verbosity gate.
4. `0007` — metadata.
5. `0001` — last, once the others have landed.

`0008`–`0011` are independent of the above; all four are in a different repository.
`0008` and `0011` touch the same file but do not conflict.

## What is deliberately not here

- **Anything about mobile beyond `0008`.** NativePHP Mobile is a commercial product,
  unlike the MIT-licensed desktop runtime. A self-contained bug fix is one thing; the
  bootstrap-path manifest that mobile needs for the same reason desktop does is a larger
  ask that should wait until the licence question is settled.
- **The `nativephp/core` extraction.** A much larger proposal, and it should follow a
  conversation rather than arrive as a diff. `../ANALYSIS.md` §4 has the reasoning; the
  51 already framework-free files are the natural first move.
- **Any opinion about `php-bin`'s missing opcache.** Real (see `../M3-RESULTS.md`), but it
  belongs to a different repository.

## Provenance

Every item above was found while building a Symfony adapter against this runtime — most of
them by running it rather than reading it. `0004` was a window rendering four enormous
letters; `0006` came out of grepping for a config key that turned out not to exist.
