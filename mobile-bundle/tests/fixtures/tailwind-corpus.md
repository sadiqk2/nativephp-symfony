# TailwindParser corpus measurement

Measured 2026-08-14. Turns the build-or-reuse question in `NATIVE-UI-CONTRACT.md` §4d
("A decision, not a task") into numbers.

- Parser under test: `upstream/np-mobile/src/Edge/TailwindParser.php`, 1,407 lines,
  `TailwindParser::parse(string): array`.
- Corpus: `NativePHP/super-native`, the upstream native-UI kitchen-sink demo —
  60 Blade templates, 446 KB, concatenated.
- Raw mapping fixture: `tailwind-corpus.json` (same directory). Every distinct token and
  every distinct class string with its exact parser output, so a reimplementation can be
  diffed against it.
- Run harness: docker, stub autoloader, `Illuminate\Support\Facades\Log` is the only
  framework dependency to stub. `setPlatform(null)`, theme resolver installed with the
  sentinel tables recorded in `_meta.themeResolverLight` / `_meta.themeResolverDark`.

## 1. Corpus size

| quantity | count |
|---|---|
| `class="…"` attributes (plus `->class('…')`: 0 found) | 3,221 |
| attributes containing Blade interpolation (`{{ }}`, `$var`, `@foreach`) | 146 |
| distinct class strings (after collapsing whitespace, stripping interpolation) | 1,336 |
| distinct class tokens | 684 |
| total token occurrences | 9,403 |
| tokens using arbitrary values `x-[N]` | 195 distinct / 1,464 uses |

`:class="…"` appears once and is a runtime expression, not a static string; it is excluded.
Stripping Blade expressions leaves 12 fragment tokens (`bg-[`, `bg-theme-primary/`,
`text-theme-on-`, `]`, …) which are recorded under `extractionArtifacts` in the JSON and
excluded from the 684 — they are corpus-extraction noise, not real classes. All 12 parse to
`[]` without throwing.

## 2. Group breakdown

Every one of the 684 tokens falls into a rule-shaped group. Sorted by usage share:

| distinct | uses | share | group | examples |
|---|---|---|---|---|
| 74 | 1,624 | 17.3% | spacing scale (`SPACING` table, 4 px unit + `px`=1) | `p-4`→`{padding:16}`, `px-3`, `gap-2.5`→`{gap:10}`, `py-px`→`1`, `p-[4]` |
| 132 | 1,023 | 10.9% | size scale (same `SPACING` table + arbitrary px) | `h-[32]`→`{height:32}`, `h-11`→`44`, `w-[280]`, `min-w-[120]` |
| 11 | 935 | 9.9% | align/justify enums → **integers** | `items-center`→`{alignItems:1}`, `justify-between`→`3`, `self-stretch`→`3` |
| 30+1 | 911 | 9.7% | theme tokens (resolver delegation) | `bg-theme-surface`, `text-theme-on-surface-variant`, `border-theme-outline`, `bg-theme-on-surface-variant/15` |
| 2 | 883 | 9.4% | booleans | `w-full`→`{fillWidth:true}`, `h-full`→`{fillHeight:true}` |
| 9 | 757 | 8.1% | font-size scale | `text-xs`→`12`, `text-base`→`16`, `text-2xl`→`24`, `text-6xl`→`60` |
| 13 | 722 | 7.7% | radius scale + per-corner | `rounded-sm`→`2`, `rounded-full`→`9999`, `rounded-t-2xl`→ two corner keys |
| 240 | 562 | 6.0% | palette lookups (22 hues × 11 shades) | `bg-slate-900`→`#0F172A`, `bg-red-300/30`→`#4DFCA5A5` |
| 7 | 526 | 5.6% | font-weight scale → 1..7 | `font-semibold`→`{fontWeight:5}`, `font-bold`→`6`, `font-extrabold`→`7` |
| 4 | 412 | 4.4% | flex shorthands | `flex-1`→`{flexGrow:1,flexShrink:1,flexBasis:0}`, `flex-wrap`→`{flexWrap:1}` |
| 17 | 298 | 3.2% | arbitrary font size | `text-[13]`→`{fontSize:13}` |
| 35 | 142 | 1.5% | arbitrary hex colours | `bg-[#272d48]`, `bg-[#00000066]`→`{bg:"#66000000"}` |
| 7 | 122 | 1.3% | white/black/transparent colours | `text-white`→`#FFFFFF`, `bg-black/50`→`#80000000` |
| 3 | 88 | 0.9% | border width (`0/2/4/8` only) | `border`→`{borderWidth:1}`, `border-2` |
| 3 | 63 | 0.7% | text-align enum | `text-center`→`{textAlign:1}` |
| 8 | 58 | 0.6% | opacity (value/100) | `opacity-50`→`0.5` |
| 7 | 57 | 0.6% | `dark:` + arbitrary colour | `dark:text-[#B0B3B8]`→`{dark:{color:"#B0B3B8"}}` |
| 30 | 50 | 0.5% | inset scale | `top-[10]`→`{positionTop:10}`, `bottom-0` |
| 7 | 29 | 0.3% | shadow → `elevation` | `shadow`→`3`, `shadow-md`→`6`, `shadow-2xl`→`16` |
| 2 | 26 | 0.3% | position enum | `absolute`→`{positionType:1}`, `relative`→`0` |
| 6 | 23 | 0.2% | **`glass` bitmask** | `glass`→`1`, `glass:clear:interactive`→`13` |
| 1 | 17 | 0.2% | `safe-area`→`{safeArea:true}` | |
| 3+3+3+3+6+1 | 50 | 0.5% | transform / decoration / font-family / fractions / tracking / leading | `uppercase`→`{textTransform:1}`, `w-2/3`→`"67%"`, `tracking-widest`→`0.1`, `leading-relaxed`→`1.625` |
| 5 | 15 | 0.2% | `dark:`/`ios:`/`android:` on palette or theme colours | `dark:bg-gray-800`, `android:dark:bg-white` |
| 7 | 8 | 0.08% | **no output at all** (see §4) | `border-t`, `mx-auto`, `overflow-hidden`, `text-7xl`, … |

Variant-prefixed tokens: 16 distinct (`dark:` 12, `android:` 3, `ios:` 1). Variants are not
a separate rule set — `parseClassImpl` strips the prefix and recurses, then wraps the result
in a `dark` sub-array or drops it when the platform does not match. So `dark:` composes with
every group above for free.

## 3. Coverage of a rule-based reimplementation

Counting distinct tokens, from narrowest rule set outward:

| rule set | distinct tokens covered | of 684 | of 9,403 uses |
|---|---|---|---|
| numeric scales + palette table + enum table (+ `/N` alpha, `[N]` arbitrary, variant recursion) | 637 | 93.1% | 89.9% |
| … plus theme-token delegation to a resolver (3 branches) | 667 | 97.5% | 99.6% |
| … plus the `glass` 4-bit flag parser (7 lines) | 673 | **98.4%** | **99.8%** |
| … plus the 11 tokens upstream itself returns `[]` for | 684 | 100% | 100% |

The last row matters: **there is no token in this corpus that a rule-based parser cannot
reproduce, because there is no token in this corpus whose output is not table-derived.**
The 11 remaining tokens are ones upstream *drops* (§4), which a reimplementation reproduces
by also dropping them.

What the tables are, concretely — this is the whole specification surface:

- `COLORS` — 22 hues × 11 shades = 242 hex entries.
- `SPACING` — 35 entries, all `4 × n` except `px`→1 (and `0.5`→2, `1.5`→6, `2.5`→10, `3.5`→14).
  It is a **whitelist, not a formula**: `p-13` is dropped even though 52 is derivable.
- `FONT_SIZES` (10), `FONT_WEIGHTS` (7, values 1–7 not 100–900), `BORDER_RADIUS` (8),
  `BORDER_RADIUS_CORNERS` (8), `SHADOW` (6 → `elevation`), `CONTAINER_SIZES` (13),
  `WIDTH_FRACTIONS` (12, **pre-rounded to integer percents**: `2/3`→`"67%"`),
  `GRADIENT_DIRECTIONS`, `NEGATABLE`.
- Enum integers come from three PHP enums (`AlignItems`, `JustifyContent`, `AlignSelf`)
  plus inline `match` arms for textAlign / textTransform / fontFamily / fontStyle /
  positionType / flexWrap / fit.

Total table content is roughly 200 lines of the 1,407. The rest is dispatch, and dispatch is
where the ordering constraints live (theme before palette, gradient before `bg-`, `inset-x-`
before `inset-`, font-family before font-weight, `min-w-`/`max-w-` before `w-`).

### Compositionality — measured

Parsing a whole class string equals parsing each token and merging, **provided `dark` and
`gradient` sub-arrays are deep-merged instead of overwritten**:

- dark/gradient-aware per-token merge vs `parse(wholeString)`: **0 divergences of 1,336**.
- flat `array_merge` per token: **27 divergences of 1,336** (2.0%) — every one is a lost
  `dark` key, e.g. `flex-1 bg-theme-surface … border border-theme-outline …` where the
  second dark-bearing class erases the first's `dark.bg`.

So the parser is a pure per-token function plus one merge rule. That is the single most
important structural fact for a reimplementation, and it is testable directly against the
`classStrings` section of the JSON fixture.

## 4. Tokens the parser drops, and surprises

No token in the corpus makes `parse()` throw. **0 exceptions across 684 tokens, 1,336 class
strings, and 12 malformed extraction fragments.** Unsupported input returns `[]` and is
recorded in the dropped-class diagnostics.

Tokens present in the corpus that produce `{}` — a rule-based rewrite would not cover these
either, because upstream does not:

| token | uses | why |
|---|---|---|
| `border-t` | 2 | directional border **widths** are not supported at all; `parseBorder` only knows `0/2/4/8` and colours. `border-t`/`-b`/`-l`/`-r`/`-x`/`-y` all drop. Real Tailwind gap. |
| `text-7xl` | 1 | `FONT_SIZES` stops at `6xl` (60). `7xl`–`9xl` drop. |
| `mx-auto` | 1 | `auto` is not in `SPACING`; `mx-auto`/`my-auto`/`m-auto` all drop. |
| `overflow-hidden` | 1 | no `overflow-*` branch. |
| `anchor-top-left`, `anchor-bottom-right` | 2 | not utility classes the parser knows (element-level concepts). |
| `origin-bottom-right` | 1 | no transform-origin branch. |

Four more return `[]` only because of platform gating, and resolve correctly once
`setPlatform()` is called — they are covered, not dropped:
`android:bg-red-500/30` → `{bg:"#4DEF4444"}` under `setPlatform('android')`,
`ios:bg-cyan-300/30` → `{bg:"#4D67E8F9"}` under `'ios'`, likewise
`android:dark:bg-white` → `{dark:{bg:"#FFFFFF"}}` and `android:dark:bg-theme-surface-variant`.
The JSON fixture records them as `[]` because it was generated at `setPlatform(null)`.

Surprising behaviours worth pinning as tests:

1. **`border-2 border-theme-outline` → `{borderWidth: 1, borderColor: …}`.** The theme-border
   branch *always* emits `borderWidth: 1`, so it silently clobbers an explicit `border-2`
   that precedes it. Reverse the order and you get `borderWidth: 2`. **This exact pattern
   occurs twice in the corpus** (`… rounded-xl border-2 border-theme-outline bg-theme-surface p-4`),
   so the demo is rendering 1 px borders where it asks for 2 px. Note the asymmetry:
   `dark:border-slate-800` emits only `dark.borderColor`, no width; `border-2 border-slate-700`
   correctly keeps `borderWidth: 2`. It is specific to `border-theme-*`.
2. **Theme tokens are resolver-dependent, and silently empty without one.** With no resolver
   registered 41 of 684 tokens return `[]` instead of 11; the extra 30 are the
   `bg-/text-/border-theme-*` tokens, 910 uses, 9.7% of the corpus.
   `bg-theme-nonexistent` is `[]` by design (upstream's own test asserts it). The corpus
   contains `text-theme-shane` (1 use), which no real theme defines — an unnoticed typo that
   the diagnostics channel would catch. Theme tokens are *not* part of the parser's
   vocabulary; they are an app-supplied hook.
3. **`w-[50%]` → `{width: 50}`** — the `%` is silently dropped and reinterpreted as points,
   while `w-1/2` → `{width: "50%"}`. Two spellings of the same intent, two different results.
4. **`opacity-200` → `{opacity: 2}`** — no clamping.
5. **8-digit arbitrary hex is byte-reordered**: `bg-[#12345678]` → `{bg: "#78123456"}`
   (RGBA in, AARRGGBB out). `/N` overwrites that alpha: `bg-[#12345678]/50` → `#80123456`.
   3-digit hex expands: `text-[#ABC]` → `#AABBCC`.
6. **`rounded-2xl rounded-t-2xl` keeps both** `borderRadius` and the two corner keys —
   deliberately, so the result is order-independent and the collector resolves precedence.
7. `COLORS` is a hand-copied snapshot of the Tailwind **v3** palette (spot-checked correct:
   `slate-900`→`#0F172A`, `gray-800`→`#1F2937`, `cyan-950`→`#083344`). Tailwind v4 restated
   the palette in OKLCH; any drift between this table and whatever the author's build uses
   is invisible until a colour looks wrong. Copying the table pins us to v3 either way.
8. Font weights are `1..7`, not `100..900`, and two real Tailwind weights are missing from
   the table: `font-black` and `font-extralight` both drop to `[]`.

## 5. Parser surface the corpus does *not* exercise

The corpus is a kitchen-sink demo and still misses these supported branches. A reimplementation
verified only against this corpus would be untested on:

gradients entirely (`bg-linear-to-*` / `bg-gradient-to-*`, `from-*`, `via-*`, `to-*` — the
one place `parse()` merges a nested `gradient` array); `object-*` → `fit`;
`aspect-square` / `aspect-video` / `aspect-[16/9]`; `select-text` / `select-none`;
negative utilities in `-mt-4` spelling (only `ml-[-6]` appears); `inset-*` / `inset-x-*`
/ `inset-y-*`; `safe-area-top` / `safe-area-bottom`; `min-h-*` / `max-h-*`;
`flex-row` / `flex-col` / `flex-nowrap` / `flex-wrap-reverse` and the `grow`/`shrink` aliases;
`no-underline` / `not-italic` / `normal-case`; `border-0` / `border-8`; shade `950`;
`transparent`; `leading-[24px]` (`lineHeightPx`); per-corner arbitrary radius
(`rounded-br-[4]`); `max-w-*` other than `4xl`.

## 6. Verdict

**Reimplement.** The data does not support the "1,407 lines is too much to reproduce" framing.

- 98.4% of distinct tokens (99.8% of uses) are produced by ten lookup tables and one enum
  set. Nothing in the corpus is an algorithm; the only non-table logic is the `glass` bitmask
  (4 flags, 7 lines), the `/N` alpha byte, and the arbitrary-value passthrough.
- The function is compositional per token, with exactly one merge rule (deep-merge `dark`
  and `gradient`). Measured: 0/1,336 divergences with that rule, 27/1,336 without it.
- The remaining 1.6% are tokens upstream itself drops — including a genuine gap (`border-t`)
  and a genuine bug (`border-2 border-theme-outline` → `borderWidth: 1`). Reusing the class
  means inheriting both; reimplementing means we can fix the bug and keep the drop-diagnostic.
- The tables are a *specification* — Tailwind's own palette and scale, not upstream's
  invention. Copying a colour table is not taking a dependency on someone else's internals;
  copying the dispatch order would be, and the dispatch order is short enough to re-derive
  and lock with the fixture.

The cost is not writing it, it's *keeping it honest*, and `tailwind-corpus.json` is the
mechanism: 684 token assertions and 1,336 class-string assertions, generated from the parser
itself. Regenerate it against a new upstream tag to see drift as a diff.

Two conditions on the recommendation:

1. The fixture must be extended to cover §5 before the reimplementation ships — the corpus
   does not exercise gradients at all, and gradients are the second nested-merge case.
2. Theme tokens must stay a resolver hook, not a table. They are app-defined; hardcoding the
   19 tokens this demo uses would be exactly the "partial hand-written subset" §4d warns about.

## Reproducing

```
docker run --rm -v /path/to/nativephp-symfony:/np -v /tmp/m6:/m6 -w /m6 \
  np-symfony-spike php fixture.php
```

Scripts used: `/tmp/m6/extract.php` (token extraction), `/tmp/m6/run.php` (parse sweep, both
resolver states), `/tmp/m6/fixture.php` (fixture + compositionality check),
`/tmp/m6/classify.py` (grouping/counts), `/tmp/m6/probe2.php`, `/tmp/m6/probe3.php`
(edge cases). Corpus at `/tmp/m6/sn/all.blade.php`.
