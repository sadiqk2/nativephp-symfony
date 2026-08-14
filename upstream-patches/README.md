# Upstream patches — prepared, not submitted

Eleven patches, ready to become pull requests. **Nothing here has been submitted.** They
are prepared so that opening the PRs is a decision rather than a project.

| | repo | base |
|---|---|---|
| `0001`–`0007` | [`NativePHP/desktop`](https://github.com/NativePHP/desktop) | `main` @ `653d186` |
| `0008`–`0011` | [`NativePHP/mobile-air`](https://github.com/NativePHP/mobile-air) | `main` |

Every patch applies cleanly to a clean tree, individually and as a series — verified with
`git apply --check`. `0001` typechecks clean under the project's own `tsc`. `0008` is
demonstrated with a before/after run of upstream's own parser.

Note `0008` targets the **mobile** repo, which is a commercial product rather than the
MIT-licensed desktop runtime. It is a self-contained bug fix to public code, so submitting
it raises nothing that `0001`–`0007` do not — but it is worth knowing they are different
repositories with different licences.

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
| `writableDirs` | `storage/framework/*`, `storage/logs`, `bootstrap/cache` |
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
| `0004` | `window/open` without `zoomFactor` calls `setZoomFactor(parseFloat(undefined))` → `NaN`, rendering the page at an absurd zoom. Invisible from Laravel because `Windows\Window` declares a `1.0` default and always serialises it, so it lands on the first independent client instead. |
| `0005` | `notifyLaravel` swallows every error in an empty `catch {}`, making a crashed, 500ing or 403ing PHP app indistinguishable from a healthy one. Now reported behind `SHELL_VERBOSITY`. |
| `0006` | `CreateSecurityCookieController` reads `config('native-php.secret')` — a namespace that does not exist. The guard therefore compared input against `null`, passing only when no secret was sent, then issued a cookie with a `null` value. It is also the one route `PreventRegularBrowserAccess` deliberately lets through unauthenticated. |
| `0007` | `composer.json`: `homepage` points at the archived `nativephp/laravel`, and the `Updater` alias points at `Native\Electron\Facades\Updater`, a class that moved to `Native\Desktop\Drivers\Electron\Facades\Updater`. |

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
