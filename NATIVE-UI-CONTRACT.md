# NativePHP Mobile — the native-UI wire format

The protocol between PHP and the SwiftUI / Jetpack Compose renderers, extracted from
`NativePHP/mobile-air`. This is what a Twig front end would have to produce — the
foundation for M6, the `super-native` equivalent.

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

`_hash` is `xxh3` over `[type, layout, style, props, on_press, on_long_press, ref,
childHashes]`. Because child hashes are folded in, an unchanged subtree has an unchanged
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

## 5. Callbacks

`CallbackRegistry::register(string $expression, ?string $kind): int` returns an **integer
id** derived from a hash of the expression, rehashed with a salt on collision (roughly 1
in 2³¹). The node carries the id; the native side sends it back on interaction; PHP looks
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

### Where to start

Not with code. Two things first:

- **Pin the format by testing against it.** Build a tree in PHP, publish it, and assert
  the JSON matches what upstream's own collector produces for an equivalent Blade
  template. Byte-comparison against a real implementation is the only way to know this
  document is right, and it is cheap.
- **Answer the licence question.** M6 is a large investment and NativePHP Mobile is a
  commercial product. That has to be settled before, not after.

### Honest assessment

M6 is the largest remaining piece of work in this project, larger than desktop was. But
the earlier framing — "reimplement 17,316 LOC" — was wrong twice over: the engine is
optional (see `MOBILE-ANALYSIS.md`), and where you *do* want it, the renderers are reused
and only the producer is new.

---

## 8. Caveats

Everything here is read out of `mobile-air` at the commit in `upstream/`, and none of it
is verified against a running device — this environment has no Xcode or Android SDK. The
format is also internal: unlike the desktop HTTP API, which is stable enough that
upstream's own client depends on it across versions, `_hash`, `flags` and the id rules
are implementation details that upstream is free to change. Any Twig front end should
expect to track them, and the byte-comparison test above is how that stays cheap.
