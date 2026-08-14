# NativePHP Mobile — the native-UI wire format

The protocol between PHP and the SwiftUI / Jetpack Compose renderers, extracted from
`NativePHP/mobile-air`. This is what a Twig front end would have to produce — the
foundation for M6, the `super-native` equivalent.

**Verified, not just read.** `mobile-bundle/` now contains a second implementation of
this format, and `UiWireFormatTest` byte-compares its output against upstream's own
collector — trees, content hashes, callback ids and navigation keys. Upstream's `Edge`
classes have no framework dependencies (their only mentions of Blade are in comments),
so they load through a stub autoloader and run standalone. Three of the details below
were wrong in the first draft of this document and the comparison test is what caught
them; they are marked ⚠.

The headline: **the format is framework-neutral.** It is a JSON tree with content
hashes. Blade appears nowhere in it. The 17,316 LOC of `src/Edge/` is the *authoring*
layer — a Blade tag precompiler, a Tailwind-subset parser, element classes, a component
lifecycle — sitting on top of a protocol that has no opinion about how the tree was
built.

That is the difference between "port a rendering engine" and "write a second front end
for a documented protocol", and it is why M6 is a real project rather than an
impossible one.

---

## 1. Transport

Not `nativephp_call()`. The element tree has its own extension functions:

| Function | When |
|---|---|
| `nativephp_element_init()` | once, when the native runloop starts |
| `nativephp_element_reset()` | before building each frame |
| `nativephp_element_publish(array $tree)` | once per frame, with the root node |
| `nativephp_element_shutdown()` | when the runloop stops |

From `src/Edge/NativeRouter.php`. So a frame is: reset → build → publish. Interactions
arrive from the native side carrying a callback id, which PHP resolves, runs, and then
publishes a new frame.

`nativephp_call('NativeUI.Transition.Set', …)` is used alongside this to set the screen
transition — the one bridge method that belongs to this path rather than the WebView one.

---

## 2. The node

From `Element::toArray()`. Every key except `id`, `type` and `_hash` is omitted when
empty, which keeps frames small:

```jsonc
{
  "id": 7,                    // stable node identity — see §3
  "type": "column",           // element type — see §6
  "_hash": "9f2c…",           // xxh3 content hash, folds children (Merkle) — see §4
  "flags": 1,                 // optional: NPHP_NODE_FLAG_REUSE — see §4
  "layout": { … },            // Yoga layout properties
  "style":  { … },            // resolved visual style
  "props":  { … },            // element-specific properties
  "on_press": 42,             // callback id — see §5
  "on_long_press": 43,
  "ref": "my-input",          // element ref, for imperative access
  "children": [ { … } ]       // recursive
}
```

`layout`, `style` and `props` are deliberately separate. A renderer applies layout to
its Yoga node, style to its view, and props to the widget — three different consumers,
so mixing them would force every renderer to re-partition them.

---

## 3. Node identity

Four rules, in precedence order, and getting this wrong is what makes a list lose its
scroll position or an input lose focus mid-typing:

1. An explicit `nodeId` set by the caller wins.
2. A `key` hashes `parentKeyPath + '/' + key`.
3. Inside a keyed subtree, an unkeyed node takes its **positional index within the
   keyed parent** — so unkeyed siblings stay stable as long as their parent is keyed.
4. Otherwise a sequential counter, which is the pre-keying legacy behaviour.

The consequence worth stating: identity is stable across frames only where keys are
used. An unkeyed list that reorders will reuse the wrong nodes.

---

## 4. Diffing — Merkle hashes and reuse markers

`_hash` is `xxh3` over a **`serialize()`** of `[type, layout, style, props, on_press,
on_long_press, ref, childHashes]` — in that order. Both the order and the PHP types are
part of the format, since `serialize()` encodes them. Because child hashes are folded in, an unchanged subtree has an unchanged
root hash.

When the caller maintains a `lastNodeHashes` map across frames and a node's hash is
unchanged, the node is emitted as a marker instead of in full:

```json
{ "id": 7, "type": "column", "flags": 1, "_hash": "9f2c…" }
```

`flags: 1` is `NPHP_NODE_FLAG_REUSE`: the renderer splices its previous subtree for that
id. No layout, style, props or children are sent.

Two notes from the upstream source, both worth carrying into any reimplementation:

- Subtree memoisation is **opt-in** — it only fires when the caller maintains the hash
  map between frames.
- The hash must cover everything a renderer reacts to. Upstream's own comment says that
  if a mutation ever fails to repaint, the missing field is the one absent from this
  hash. That makes the hash input list a compatibility surface, not an implementation
  detail.

---

## 4b. ⚠ Id derivation — FNV-1a, and two different masks

The first draft of this document said "a hash", and a reimplementation using md5 produced
ids that diverged silently. Both id spaces use **FNV-1a 32-bit**:

```php
$hash = 0x811C9DC5;                              // offset basis
foreach (bytes) { $hash ^= $byte; $hash = ($hash * 0x01000193) & 0xFFFFFFFF; }
return 0 === $hash ? 1 : $hash;                  // 0 means "unset" natively
```

And they mask differently, which matters:

| | mask | why |
|---|---|---|
| **Node ids** (`deriveNodeIdFromKeyPath`) | full 32 bits | travel as unsigned |
| **Callback ids** (`CallbackRegistry::deriveId`) | `& 0x7FFFFFFF` (31 bits) | Kotlin reads them as a signed `Int`; a full-u32 id wraps negative on the round trip and resolution misses **silently** |

Collisions in both are resolved by rehashing `$input . "\x00" . $salt`, salt from 1. A
registry scope, when set, is joined to the expression with `\x1F` — a separator that
cannot occur in an expression, so two scopes cannot collide by concatenation.

---

## 4c. ⚠ Layout defaults, and two naming conventions

The base element exposes `layoutDefaults()` and `styleDefaults()`, merged **under**
whatever the author set:

```php
public function getLayout(): array { return array_merge($this->layoutDefaults(), $this->layout); }
```

A default has to appear in the emitted layout for the renderer to apply it, but must lose
to an explicit value. `Spacer` is the canonical case — `['flex_grow' => 1]`, so it works
dropped in bare. Missing this made an otherwise byte-identical tree diverge at the root,
because the default feeds the content hash.

And note the two conventions living side by side: **layout keys are snake_case**
(`flex_grow`) while **element props are camelCase** (`fontSize`, `maxLines`). That is
upstream's inconsistency, not a transcription error, and a reimplementation has to
reproduce it.

---

## 4d. The styling pipeline

A class string does not reach the wire directly. It goes through a canonical
intermediate vocabulary, which is what resolves the naming inconsistency in §4c:

```
"flex-1 px-3 bg-slate-900 text-2xl"
        │
        ▼  TailwindParser::parse()  →  a flat camelCase map
{ flexGrow: 1, flexShrink: 1, flexBasis: 0, paddingLeft: 12, paddingRight: 12,
  bg: '#0F172A', fontSize: 24 }
        │
        ├─▶ Element::applyAttributes()                → element-specific props (fontSize)
        ├─▶ NativeElementCollector::applyLayout()     → layout, via element setters
        ├─▶ NativeElementCollector::applyStyle()      → style
        ├─▶ NativeElementCollector::applyElementProps()
        └─▶ buildDarkProps() / mergeDarkProps()       → `dark:` variants as dark_* props
```

So the parser emits **camelCase** (`flexGrow`), and `applyLayout` dispatches each key
through an element *setter* (`->fillWidth()`, `->width()`, `->flexDirection()`) whose
implementation writes the **snake_case** wire name into `$layout`. The translation lives
in the setters, not in a lookup table — which is why grepping for a key map finds
nothing.

Measured outputs, from running upstream's parser standalone (only `Illuminate\Support\Facades\Log`
needs stubbing):

| classes | parser output |
|---|---|
| `flex-1` | `{flexGrow: 1, flexShrink: 1, flexBasis: 0}` |
| `p-4` | `{padding: 16}` |
| `px-2 py-3` | `{paddingLeft: 8, paddingRight: 8, paddingTop: 12, paddingBottom: 12}` |
| `gap-2` | `{gap: 8}` |
| `bg-slate-900` | `{bg: '#0F172A'}` |
| `text-white` | `{color: '#FFFFFF'}` |
| `text-2xl font-bold` | `{fontSize: 24, fontWeight: 6}` |
| `rounded-lg` | `{borderRadius: 8}` |
| `items-center justify-between` | `{alignItems: 1, justifyContent: 3}` |
| `w-full h-12` | `{fillWidth: true, height: 48}` |
| `opacity-50` | `{opacity: 0.5}` |
| `shadow-md` | `{elevation: 6}` |
| `border border-slate-700` | `{borderWidth: 1, borderColor: '#334155'}` |

Note the scale: Tailwind's spacing unit is 4px (`p-4` → 16), enums arrive as **integers**
(`alignItems: 1`), shadows become `elevation`, and `w-full` becomes a boolean `fillWidth`
rather than a width value.

### A decision, not a task

`TailwindParser` is 1,407 lines. Reproducing it is not like the tree builder, where the
rules were short enough to reimplement and verify. Two options, and they are a judgement
call rather than an engineering one:

- **Reimplement**, verified the same way — run both parsers over a corpus of class strings
  and diff. Costly, but no dependency on someone else's internals.
- **Reuse it.** `mobile-air` is MIT, the class needs one stub to run standalone, and the
  mapping is a specification more than an implementation. Cheaper, but it means tracking
  upstream's changes and taking a dependency on a commercial product's internals.

Either way the corpus diff is the mechanism that keeps it honest. What should *not* happen
is a partial hand-written subset that silently disagrees on `p-3.5` or `bg-slate-850`.

`CallbackRegistry::register(string $expression, ?string $kind): int` returns an integer id
derived as in §4b. The node carries the id; the native side sends it back on interaction; PHP looks
up the expression and runs it.

Navigation is content-addressed instead: `registerNavigation(array $config)` returns
`'n' + substr(md5(json_encode($config)), 0, 8)`, and a navigating press is registered as
the expression `__navigate('<key>')`.

The important property for a second front end: **callbacks cross the boundary as ids, not
closures.** Nothing about the registry requires Blade — it requires a way to name a
callable and resolve it again on the next request.

---

## 6. Element types

37 element classes plus 17 native components in `mobile-air`, and 60+ more renderers in
the separate `nativephp/mobile-ui` package. The types in `mobile-air`:

```
Layout      column  row  stack  spacer  scroll_view  lazy_grid  divider
Content     text  image  icon  canvas  rect  circle  line
Input       button  text_input  toggle  pressable  gesture_area
Navigation  native_root_stack  native_root_tabs  top_bar  top_bar_action
            top_bar_title  bottom_bar  bottom_nav  bottom_nav_item
            side_nav  side_nav_group  side_nav_header  side_nav_item
            tab_accessory  navigate  replace  search_item
Feedback    activity_indicator  bottom_sheet  refreshable
```

**The renderers are reusable as-is.** They are Kotlin and Swift, they consume this JSON,
and they neither know nor care that a Blade compiler produced it. Nothing in
`resources/androidstudio/…/nativerender/` or `resources/xcode/` needs rewriting for a
Twig front end — which is the single most important fact in this document.

---

## 7. What M6 would actually involve

Not a port of `src/Edge/`. A second producer of this format:

1. **A tree builder** — the equivalent of `NativeElementCollector`: an element stack,
   `open`/`close`, child attachment, and `toArray()` with the identity and hashing rules
   above. This is the core, and it is small; the rules are in §§3–4.
2. **A Twig authoring layer.** Twig has no equivalent of Blade's tag precompiler, but it
   does not need one: a Twig extension providing functions or tags (`{% native_column %}`
   … or `{{ native_text(…) }}`) can drive the builder directly. Arguably cleaner than
   upstream's approach, which compiles `<native:*>` tags into collector calls
   specifically to *bypass* Blade's component lifecycle for speed — a cost Twig would not
   be paying in the first place.
3. **A style parser.** `TailwindParser` maps a Tailwind-like class subset onto
   layout/style values. The mapping is the specification; a reimplementation needs the
   same output shape, not the same code.
4. **A callback registry** — §5. Straightforward.
5. **Routing** — the equivalent of `Route::native()` / `->layout()`, which in Symfony is
   a route attribute or a controller convention rather than a router macro.
6. **A component lifecycle** — state, re-render on interaction. Symfony has no native
   equivalent of a Livewire-style component, so this is the most open design question,
   and the place to look hardest for prior art before inventing anything.

### Progress

Items 1 and 4 are **done and verified**: `mobile-bundle/src/Ui/` contains the tree
builder, the element base with the identity, hashing and defaults rules, a callback
registry, 13 element types and an `ElementPublisher` wrapping the four extension
functions. `UiWireFormatTest` proves the output is byte-identical to upstream's.

That leaves the authoring layer (2), the style parser (3), routing (5) and the component
lifecycle (6) — of which the lifecycle is the real design question, since Symfony has no
Livewire-shaped equivalent to borrow from.

Also still open, and more important than any of them: **the licence question.** M6 is a
large investment and NativePHP Mobile is a commercial product. That should be settled
before more is built, not after.

### Honest assessment

M6 is the largest remaining piece of work in this project, larger than desktop was. But
the earlier framing — "reimplement 17,316 LOC" — was wrong twice over: the engine is
optional (see `MOBILE-ANALYSIS.md`), and where you *do* want it, the renderers are reused
and only the producer is new.

---

## 7b. Native routing and the manifest

Which paths boot into the native runloop is decided on the device, from a manifest the
CLI bakes in. Getting this wrong strands a screen: the native side boots it and PHP
cannot serve it, or the reverse. Seven behaviours matter, all established by porting
`BootPlanner.matches()` line by line and testing the port against it.

**1. `{param?}` makes the entire remaining tail optional, not just itself.** `matches()`
short-circuits on the *first* missing segment:

```kotlin
else -> return isOptional || (i until p.size).all { … }
```

So `/items/{a?}/edit` matches `/items`, and `/items/{a?}/{b}` matches `/items` — a
*required* `{b}` is skippable if an optional segment precedes it. This contradicts
BootPlanner's own doc comment ("optional trailing matches"), and it makes the `all()`
branch **dead code**: a required first-missing segment fails that same test anyway. True
on both platforms. Whether the code or the comment is wrong is upstream's call, which is
why this is written down rather than patched.

**2. Optional segments were never resolvable in PHP at all** — see patch `0009` in
`upstream-patches/`. `NativeRouter::resolve()` builds its regex with
`preg_replace('/\{(\w+)\}/', …)`, and `\w` does not match `?`, so a `{slug?}`
placeholder survived literally and the pattern could only match the string `"{slug?}"`.
Verified: `/posts/{slug?}` matched neither `/posts/hello` nor `/posts`. Meanwhile
BootPlanner matches both, so such a screen booted natively and then resolved to nothing.

**3. The same list has two different key names.** `native_routes` in the baked
`bundle_meta.json`; `routes` in the runtime dump. Both readers depend on their own.

**4. The runtime dump is honoured only when its `version` string-equals the baked one** —
and iOS reads both with `as? String`, so a JSON *number* version fails the cast, compares
as `""`, and the dump is silently ignored. An empty version is equally dangerous, since it
equals the missing-key fallback.

**5. The platforms diverge on a missing `bundle_meta.json`.** Kotlin tolerates it and can
boot `NATIVE_DIRECT` from the runtime dump alone; Swift's `plan()` guards and returns
`.webLegacy`. **So `native_routes` must always be baked** — never treat the dump as the
only source.

**6. The dump path is fixed** at `storage/framework/native_routes.json`, under
`app_storage/persisted_data/` on Android and Application Support on iOS. Upstream relies
on Laravel's `storage/framework` already existing; a Symfony app has no such directory, and
a failed write leaves the device on a stale baked list with nothing logged.

**7b. A `dark:` prefix on a theme token inverts it.** A theme token resolves its own dark
companion, and the `dark:` wrapper nests that companion a level deeper than the merge lifts
— so the light hex ends up in the dark slot and the dark one is unreachable. See patch
`0011`. Worth knowing even once patched, because any second implementation of the variant
logic will make the same mistake.

**7. Matching is segment-wise with empty segments dropped**, so `/items//42` matches
`/items/{id}` and leading or trailing slashes are insignificant — but a trailing slash does
miss upstream's exact-match fast path and falls through to the pattern scan.

---

## 8. Caveats

The format is verified against upstream's own PHP implementation, which is a real check —
but **not** against a running device. This environment has no Xcode or Android SDK, so
whether the Kotlin and Swift renderers accept these trees in practice is still untested. The
format is also internal: unlike the desktop HTTP API, which is stable enough that
upstream's own client depends on it across versions, `_hash`, `flags` and the id rules
are implementation details that upstream is free to change. Any Twig front end should
expect to track them, and the byte-comparison test above is how that stays cheap.
