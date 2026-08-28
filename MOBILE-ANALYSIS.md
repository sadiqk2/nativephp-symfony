# NativePHP Mobile — analysis, and a correction

**My earlier verdict was wrong.** `PLAN.md` §2.3 and `ANALYSIS.md` §10 called mobile a
different order of magnitude, on the grounds that its UI is a 17,316-LOC native rendering
engine coupled to Blade. The engine is real and that number is right. What I got wrong is
that it is **optional**.

I measured the hard path and treated it as the only path. Reading the actual boot code
rather than the file listing shows there are two, and the easy one is the one desktop
already uses.

---

## 1. There are two render paths, and one of them is a WebView

`resources/androidstudio/.../ui/BootPlanner.kt` — its own docblock:

> Decides how the first screen boots: direct JNI dispatch into the native runloop (no
> WebView, no Chromium) or the legacy WebView path. […] Missing or unparseable manifest ⇒
> WEB_LEGACY — always safe, byte-identical to the pre-native-first behavior.

The decision is data-driven from a native-route manifest that the CLI bakes into
`bundle_meta.json`, listing the `Route::native` URI patterns. The logic:

```
entry_mode == "web"            → WEB_LEGACY      (explicit build-time escape hatch)
no native-route manifest       → WEB_LEGACY
requested path matches none    → WEB_LEGACY
otherwise                      → NATIVE_DIRECT
```

`resources/xcode/NativePHP/BootPlanner.swift` is the same design, `.webLegacy` /
`.nativeDirect`, same `entry_mode == "web"` check.

And the escape hatch is a documented, first-class env var. From
`src/Concerns/PreparesBuild.php` and `src/Commands/BuildIosAppCommand.php`:

```php
// NATIVEPHP_BOOT_MODE=web forces the legacy path.
$entryMode = env('NATIVEPHP_BOOT_MODE') === 'web' ? 'web' : 'auto';
```

**Consequences for a Symfony port.** A Symfony app has no `Route::native` patterns, so it
produces no manifest, so every path falls through to `WEB_LEGACY` — and `NATIVEPHP_BOOT_MODE=web`
makes that explicit rather than incidental. The Edge engine is never entered. Twig renders
into a WebView exactly as it does on desktop, and **zero of those 17,316 lines need
porting** for a working mobile app.

The WebView path is not a deprecated corner either: it has a full supporting stack
(`WebViewManager.kt` at 655 lines, `WebRenderer.kt`, `PHPWebViewClient.kt`,
`JumpWebViewSession.kt`, `WebCookieMirror.kt`) and its JS bridge exposes
`window.Native.dispatch(eventName, payload)` — the same shape as desktop's preload.

---

## 2. What mobile actually requires of the PHP app

Different from desktop in one important way: there is **no HTTP boundary**. PHP is compiled
into the app and called through JNI (Android) or directly (iOS), so there is no port, no
secret, no express server. Two mechanisms instead:

### 2a. A hand-rolled SAPI

The native layer executes a bootstrap script by absolute path and reads its stdout as a raw
HTTP response. From `bridge/PHPBridge.kt`:

```kotlin
get() = "${getLaravelPath()}/vendor/nativephp/mobile/bootstrap/android/native.php"
get() = "${getLaravelPath()}/vendor/nativephp/mobile/bootstrap/android/persistent.php"
```

and `NativePHPApp.swift`:

```swift
let phpFilePath = appPath + "/vendor/nativephp/mobile/bootstrap/ios/native.php"
let phpFilePath = appPath + "/vendor/nativephp/mobile/bootstrap/ios/artisan.php"
```

`bootstrap/{ios,android}/native.php` reconstructs `$_GET`/`$_POST`/`$_COOKIE` from
`$_SERVER`, boots the framework, resolves `Illuminate\Contracts\Http\Kernel`, and hand-writes
the status line, headers and body to stdout. `persistent.php` keeps the interpreter alive and
dispatches subsequent requests through `Runtime::dispatch()` via `zend_eval_string()`.

**This is the mobile equivalent of desktop's hardcoded router script**, and it is the same
kind of problem with the same kind of solution: three hardcoded paths in the native sources.

### 2b. The native bridge

`nativephp_call($method, $jsonPayload)` — a PHP extension function. **62 distinct methods**
in the current PHP surface:

| Group | Methods |
|---|---|
| Camera | GetPhoto, PickMedia, RecordVideo |
| Device | GetInfo, GetId, GetBatteryInfo, Vibrate, ToggleFlashlight |
| Microphone | Start, Stop, Pause, Resume, GetStatus, GetRecording |
| Geolocation | GetCurrentPosition, CheckPermissions, RequestPermissions, WatchPosition, ClearWatch, StartBackgroundWatch, StopBackgroundWatch, BackgroundWatchStatus, DrainWatchBuffer, TrimWatchBuffer |
| SecureStorage | Get, Set, Delete |
| PushNotification | GetToken, CheckPermission, ClearBadge, RequestPermission |
| Browser | Open, OpenInApp, OpenAuth |
| Share | Url, File |
| MobileWallet | IsAvailable, CreatePaymentIntent, ConfirmPayment, PresentPaymentSheet, GetPaymentStatus |
| System | GetAppearance, MinimizeApp, OpenAppSettings |
| Biometric | Prompt |
| Dialog | Toast, Alert |
| Scanner | Scan |
| File | Copy, Move |
| Network | Status |
| UI / NativeUI | SetBackground, Transition.Set |
| Perf | 8 instrumentation methods |

**The bridge itself is entirely framework-agnostic** — a function name and a JSON string. The
PHP wrappers around it are the same mechanical work as desktop's endpoint wrappers, and there
are fewer of them (62 vs 116). Five of the 62 have no wrapper here yet, all Geolocation and
all of them starting or locating a position rather than addressing a watch already running:
`GetCurrentPosition`, `CheckPermissions`, `RequestPermissions`, `WatchPosition` and
`StartBackgroundWatch`. `BridgeCoverageTest` names them so the gap is stated rather than
counted over — the count read 57 until that test learned to read a method name computed
above its call site, which is where all five were hiding.

---

## 3. The same escape hatch exists

Critically, the native projects are copied into the *user's* project, exactly as the Electron
project is on desktop — `src/` references `base_path('nativephp/android')` and
`base_path('nativephp/ios')` throughout (11 and 10 call sites respectively).

So the three hardcoded bootstrap paths are patchable per-project, with no upstream
cooperation, by the same `RuntimePatcher` pattern already built for desktop. The proper
upstream fix is the same too: declare them in a manifest.

---

## 4. Revised difficulty

| | desktop | mobile (WebView path) | mobile (native UI) |
|---|---|---|---|
| Transport | localhost HTTP, 116 endpoints | `nativephp_call`, 62 methods | same |
| Framework leak in the native layer | 8 string literals, 1 file | 3 bootstrap paths, 2 files | plus Blade-coupled rendering |
| SAPI shim needed | no (`php -S` + router) | **yes, ~100 LOC** | yes |
| UI work | none | **none** | reimplement 17,316 LOC for Twig |
| Toolchain to verify | none | Xcode / Android SDK+NDK | same |
| Verdict | done | **tractable — do this** | a project in itself |

So mobile splits cleanly in two, and I had been pricing the whole thing at the cost of the
second half.

---

## 5. Plan

### M5 — mobile, WebView path

1. **A Symfony SAPI shim** — the `native.php` equivalent: superglobals from `$_SERVER`, boot
   the kernel, emit a raw HTTP response. Plus a persistent variant that reuses the kernel
   across requests, which is where mobile's performance comes from.
2. **A `MobileRuntimePatcher`** — retarget the three hardcoded bootstrap paths in the copied
   Android/iOS projects, and force `entry_mode: web`.
3. **PHP wrappers for the 62 bridge methods**, typed, in the shape already established by the
   desktop bundle.
4. **Build commands** — `native:mobile:install`, `native:mobile:run android|ios`.

**Honest limit:** items 1–3 can be unit-tested here; item 4 and any device run cannot. This
environment has no Xcode and no Android SDK/NDK, so anything mobile ships **verified by
tests, not by execution** — the opposite of desktop, where the claim is backed by a
screenshot of a running package. That distinction gets stated wherever mobile is described.

### M6 — native UI for Twig (the `super-native` equivalent)

`NativePHP/super-native` is the kitchen-sink demo for the native-UI path: Blade templates of
`<native:*>` tags rendered as SwiftUI and Jetpack Compose. A Symfony equivalent means a Twig
front end for the same element protocol:

- A Twig extension or lexer producing the same element tree `NativeElementCollector` builds
  (the wire format between PHP and the renderers is the tractable part — it is just a tree).
- The Tailwind-subset parser, the Yoga layout model, and the element registry — 92 files.
- `Route::native` / `->layout()` equivalents in Symfony routing.
- The renderers themselves are Kotlin and Swift and are **reusable as-is** — 9 + 5 in
  `mobile-air`, 60+ in `mobile-ui`. Nothing needs rewriting there, which is the one piece of
  good news about M6.

The right first step for M6 was not code but pinning down the **element-tree wire format**
the renderers consume, the same way `CONTRACT.md` pinned down the HTTP API.

**That is now done — see [`NATIVE-UI-CONTRACT.md`](NATIVE-UI-CONTRACT.md).** The format is a
JSON tree of `{id, type, _hash, layout?, style?, props?, on_press?, children?}`, published
through its own extension functions (`nativephp_element_{init,reset,publish,shutdown}`), with
Merkle content hashes and reuse markers for diffing, and callbacks crossing as integer ids.
Blade appears nowhere in it: the 17,316 LOC is the *authoring* layer, and the renderers
consume this protocol without caring what produced the tree.

---

## 6. Still open

- **Licensing.** NativePHP Mobile is a commercial product. `mobile-air/composer.json` says
  MIT, but the product has a paid licence and the docs gate features behind it. Before any of
  M5 ships publicly, that needs reading properly — it is a different question from desktop,
  which is unambiguously MIT.
- **Device verification.** Everything above needs a real emulator run to be more than a
  well-argued design.
