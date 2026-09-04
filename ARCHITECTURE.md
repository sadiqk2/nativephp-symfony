# Architecture — how the two halves fit together

Desktop and mobile are separate products upstream that happen to share a brand. This
project ports both, and the shapes are genuinely different — worth seeing side by side
before reading either bundle.

---

## The one-line difference

**Desktop is two processes talking over a socket. Mobile is one process with PHP compiled
into it.**

Everything else follows from that.

```
DESKTOP                                    MOBILE
┌──────────────────────────┐               ┌──────────────────────────────┐
│ Electron (Node)          │               │ App process (Kotlin / Swift) │
│  express :4000-5000      │               │                              │
│  ├── 118 JSON endpoints  │◀──HTTP────┐   │  ┌────────────────────────┐  │
│  └── pushes 46 events ───┼──HTTP───┐ │   │  │ PHP, compiled in       │  │
│                          │         │ │   │  │                        │  │
│  spawns: php -S          │         │ │   │  │  nativephp_call()   ───┼──┼─▶ 54 methods
└──────────────────────────┘         │ │   │  │  nativephp_element_*───┼──┼─▶ element tree
                                     ▼ │   │  │                        │  │
┌──────────────────────────┐         │ │   │  │  JNI / direct dispatch │  │
│ PHP: Symfony             │─────────┘ │   │  └────────────────────────┘  │
│  bundle/                 │───────────┘   │     mobile-bundle/           │
└──────────────────────────┘               └──────────────────────────────┘
   shared secret in a header                  same address space, no auth
```

| | desktop | mobile |
|---|---|---|
| Transport | localhost HTTP + JSON | PHP extension functions |
| Auth | `X-NativePHP-Secret` on every call | none needed — same process |
| Surface | 118 endpoints, 46 pushed events | 54 bridge methods |
| PHP lifecycle | `php -S`, one process per request | compiled in; one-shot **or** persistent |
| Needs a SAPI shim | no (`php -S` + a router script) | **yes** — the host reads stdout as HTTP |
| UI | WebView, always | WebView **or** native element tree |
| Framework leak in the native layer | 8 string literals, 1 file | 3 bootstrap paths, 2 files |
| Verified by | a **running packaged app** | tests only — no Xcode, no Android SDK here |

That last row is the one to keep in mind. The two halves are not held to the same standard
of evidence, and this project says so wherever mobile is described rather than letting the
reader assume parity.

---

## What the two bundles share, and what they don't

Almost nothing, and that is the right answer rather than a missed opportunity.

**Not shared, because the mechanisms genuinely differ:** the transport (HTTP client vs
extension function), the security model (a shared secret vs same-address-space), the event
model (44 pushed classes vs 54 synchronous or event-returning calls), the build pipeline
(electron-builder vs Gradle and Xcode).

**Genuinely common,** and the natural content of a future `nativephp/core`:

- `AppBootstrapper` — "the runtime is up, decide what appears". Identical on both.
- The dispatch discipline: a call that *presents* UI is separated from one that *returns*
  a result, so a `true` can never be mistaken for an outcome.
- The diagnostic discipline: never let "the runtime did not answer" look like "the runtime
  said nothing". Both bundles log rather than swallow, and both were bitten by upstream
  doing the opposite.
- The patcher pattern: upstream copies its native project into the app's own directory, so
  each app patches a local copy and needs no upstream cooperation. `RuntimePatcher` and
  `MobileRuntimePatcher` are the same idea twice.

A shared package is deferred deliberately. Two implementations that agree is evidence the
abstraction is real; one implementation plus a guess is not.

---

## The three verification techniques

The interesting part of this project is not the code, it is how each claim is checked. All
three are reusable.

### 1. Run it, do not reason about it (desktop)

A container with PHP, Node, Electron and Xvfb boots the real runtime headlessly and
screenshots it. Every desktop claim traces to a screenshot or a captured HTTP exchange.

This is what caught `APP_ENV=local` (a hard boot failure on Symfony 8, invisible from
reading), `zoomFactor` → `NaN`, express's `sendStatus` sending `"OK"` as a body, and
`APP_SECRET` being stripped by a pattern list inherited from Laravel.

### 2. Byte-compare against upstream's own implementation (mobile native UI)

Upstream's `Edge` classes have no framework dependencies — their only mentions of Blade are
in comments — so a stub PSR-4 autoloader loads them standalone and their output is compared
directly against ours. `TailwindParser` needs one extra stub.

This caught FNV-1a-vs-md5 for callback ids (which would have silently resolved the wrong
handler), two different bit masks for two id spaces, and two element defaults whose absence
would have made a `scroll_view` quietly not scroll.

**Reach for this before reimplementing anything from upstream.** It is cheap and it turns
guesses into diffs.

### 3. Parse upstream's source as a test (both)

`ContractCoverageTest` reads the runtime's express routers and fails if an endpoint is
uncovered. `BridgeCoverageTest` and `UiElementCoverageTest` do the same for the 62 bridge
methods and the 36 element types — the last asserting in **both** directions, since a type
the renderers do not know produces a missing region on the device with no error anywhere.

Upstream drift becomes a failing test rather than a discovery.

---

## Where the bodies are buried

Things that cost real time, collected so they cost no one else any:

- **Laravel's defaults do not translate, they collide.** `*_SECRET` in a cleanup list is
  harmless for `APP_KEY` and fatal for `APP_SECRET`.
- **`res.sendStatus(200)` sends the body `"OK"`.** Not an empty body. Match the reason
  phrase exactly, or a genuine HTML error page gets swallowed too.
- **Symfony does not alias interfaces to implementations.** A Laravel habit; needs
  autoconfiguration plus a compiler pass.
- **Decorating `event_dispatcher` needs the component interface**, not the contract — the
  container registers listeners *through* that service.
- **A programmatic resize emits no `WindowResized`.** The runtime listens for Electron's
  `resized`, which fires on user drags only.
- **Dots are object paths** in the desktop settings store and mobile's secure storage
  alike, so the change event reports the root key, not the one you wrote.
- **Packaged apps have no opcache.** The static binary cannot load it, so every request
  pays full compile cost — Laravel's too.

---

## Reading order

`PLAN.md` → `ANALYSIS.md` → `CONTRACT.md` for desktop; `MOBILE-ANALYSIS.md` →
`NATIVE-UI-CONTRACT.md` for mobile. `SPIKE-RESULTS.md`, `M2-RESULTS.md` and
`M3-RESULTS.md` are the desktop milestones, each ending in a list of what running it
taught. `upstream-patches/README.md` is what would go back.
