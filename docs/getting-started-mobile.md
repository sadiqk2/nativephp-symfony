# Getting started — mobile

Two things to know before you write any code.

**Nothing here has run on a phone.** Every claim in the mobile bundle is backed by tests —
including tests that byte-compare element trees against upstream's own collector and parse
upstream's Kotlin and Swift to check coverage — but no build has been produced by Xcode or
Gradle in this project, and no screen has been rendered on a device. The desktop side is
backed by a screenshot of a running packaged app; mobile is not held to that standard yet.
Treat it as a well-tested design awaiting device verification.

**NativePHP Mobile is a commercial product.** The desktop runtime is MIT; the iOS and
Android hosts are not. Read its licence terms before distributing anything built this way.
`native:mobile:install` prints this warning too, deliberately.

---

## The one architectural difference from desktop

Desktop is two processes over localhost HTTP. Mobile is **one process with PHP compiled
into it**: no port, no shared secret, no express server. The app calls
`nativephp_call('Camera.Photo', $json)` — a function that exists only inside a packaged app
— and the host answers in the same address space. Outside a packaged app the bridge is
always unavailable and every call returns `null` or `false`.

Consequently there is no dev-server loop like `native:run`. What replaces it is
`native_mobile.fake_bridge: true`, plus tests.

## 1. Install

```bash
composer require native-symfony/mobile-bundle
```

```php
// config/bundles.php
return [
    // …
    Native\Symfony\Mobile\NativeMobileBundle::class => ['all' => true],
];
```

Then copy the two native projects into your application and retarget them:

```bash
git clone --depth 1 https://github.com/NativePHP/mobile-air /tmp/np-mobile
bin/console native:mobile:install --source=/tmp/np-mobile/resources
bin/console native:mobile:doctor
```

`native:mobile:install` mirrors `resources/androidstudio` to `nativephp/android` and
`resources/xcode` to `nativephp/ios`, then patches the two places each host hardcodes a
path into `vendor/nativephp/mobile/bootstrap/…/native.php` so they point at this bundle's
shims instead. `--platform=android|ios|both`, `--force` to overwrite. The patcher throws
rather than skipping a path, because a missed one is a launch to a blank screen with no
diagnostic.

From there you build with Android Studio or Xcode. There is no headless path: both
toolchains are required, and neither runs in CI without a licence.

`native:mobile:doctor` reports the project dir, the PHP version and SAPI, whether the
bridge is available, whether the persistent runtime has booted and how many dispatches it
has served, whether opcache exists, whether both native projects are installed, and whether
all four bootstrap shims are present. Run it *through the console shim on a device* and read
it in logcat or the Xcode console — that is the only place it tells you anything you did not
already know.

## 2. The four bootstrap shims

The host executes PHP by absolute path and reads stdout as a complete HTTP message. That is
what these are:

| Shim | Role |
|---|---|
| `native.php` | One-shot: autoloader → env → kernel → one request → emit. Pays for everything per request. |
| `persistent.php` | Booted once. Builds the kernel and hands it to `MobileRuntime::boot()`, then the interpreter stays alive. |
| `dispatch.php` | Per-request in persistent mode. Evaluated in a fresh scope, so it reaches the runtime through `MobileRuntime::instance()`. |
| `console.php` | Console entry point — what the host runs for migrations, cache warming, anything scheduled. Replaces upstream's `artisan.php`. |

**Prefer the persistent pair.** The autoloader, the container and every bundle's boot cost
once instead of per screen, and on a phone that is the difference between a native-feeling
app and a sluggish one. `MobileRuntime` resets everything tagged `kernel.reset` after each
dispatch through `services_resetter` — the same mechanism `messenger:consume` uses — so
Doctrine, the profiler, the validator and security token storage are all handled, including
bundles this code has never heard of.

Both shims read `COMPOSER_AUTOLOADER_PATH` from `$_SERVER` (falling back to a path walk),
load `.env` via Dotenv with `usePutenv(false)`, and instantiate `App\Kernel` unless
`NATIVEPHP_KERNEL_CLASS` says otherwise. A failure before the kernel exists is written with
`error_log()` prefixed `[NATIVE_EXCEPTION]` and answered with a hand-built 500, because at
that point there is no logger and no error page.

---

## Path A — the WebView render path (what most apps want)

Both platforms' `BootPlanner` decides per launch: native direct dispatch, or a WebView. It
falls back to the WebView whenever there is no native-route manifest, or the requested path
matches none of its patterns — its own docblock calls that path *"always safe,
byte-identical to the pre-native-first behavior"*. A Symfony app that declares no
`#[NativeScreen]` produces no manifest, so **every path takes the WebView by construction**,
and `NATIVEPHP_BOOT_MODE=web` at build time forces it.

That means the 17,316-line native-UI engine is never entered, and Twig in a WebView is just
Twig. Your controllers and templates are ordinary Symfony. The only new thing is the device
API:

```php
<?php

namespace App\Controller;

use Native\Symfony\Mobile\Api\Camera;
use Native\Symfony\Mobile\Api\Device;
use Native\Symfony\Mobile\Api\SecureStorage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ProfileController extends AbstractController
{
    public function __construct(
        private readonly Camera $camera,
        private readonly SecureStorage $storage,
        private readonly Device $device,
    ) {
    }

    #[Route('/profile/photo', name: 'profile_photo')]
    public function photo(): Response
    {
        // Asynchronous. `true` means the camera UI was presented, nothing more —
        // the picture arrives later as a native event.
        $this->camera->photo(quality: 85);

        return $this->redirectToRoute('profile');
    }

    #[Route('/profile', name: 'profile')]
    public function show(): Response
    {
        return $this->render('profile.html.twig', [
            'token' => $this->storage->get('token'),   // null when absent
            'device' => $this->device->info(),         // [] when the bridge is absent
        ]);
    }
}
```

All sixteen API services are autowired and public: `Biometric`, `Browser`, `Camera`,
`Device`, `Dialog`, `Files`, `Geolocation`, `Microphone`, `MobileWallet`, `NativeUi`,
`Network`, `Performance`, `PushNotifications`, `SecureStorage`, `Share`, `System`. See the
[mobile API reference](mobile-api.md).

### Developing without a device

```yaml
# config/packages/test/native_mobile.yaml
native_mobile:
    fake_bridge: true
```

That aliases `BridgeInterface` to `FakeBridge`, which records every call and answers with
whatever you scripted. It is a shipped part of the bundle rather than a test fixture, so it
works in `dev` in a browser too.

## Path B — the native-UI render path

Only if you want SwiftUI and Jetpack Compose rather than a WebView. This path is
implemented — element trees, the Tailwind-subset style parser, `#[NativeScreen]` routing, a
component lifecycle — and byte-verified against upstream wherever a comparison exists. It is
also the least device-verified part of the project.

Declare a screen:

```php
<?php

namespace App\Screen;

use Native\Symfony\Mobile\Ui\Routing\NativeScreen;

#[NativeScreen('/items/{id}')]
final class ItemScreen
{
    // …
}
```

The path uses **Laravel** placeholder syntax (`{id}`, `{id?}`), not Symfony's. That string
is copied verbatim into the app bundle and matched on the device by Kotlin's and Swift's
`BootPlanner`, so inventing a Symfony flavour would make the two sides disagree about which
paths boot natively — a WebView flash on a device and nothing at all in a test suite.

Discovery is explicit, never a scan of everything autoloadable, because the manifest is only
correct if the build-time walk and the runtime walk produce the same list:

```php
use Native\Symfony\Mobile\Ui\Routing\NativeScreenAttributeLoader;

// One class at a time…
$loader->loadClass(\App\Screen\ItemScreen::class);

// …or a PSR-4 root, sorted so two manifests are diffable.
$loader->loadDirectory(__DIR__.'/../src/Screen', 'App\\Screen');
```

Both fill the `NativeRouteRegistry`. `NativeRouteManifest` then exports the patterns in the
two shapes the device reads — the baked `bundle_meta.json` fragment (`native_routes`,
`entry_mode`) and the runtime dump at `storage/framework/native_routes.json` (`routes`).
Three failure modes there are silent and each costs a fallback to the WebView: the two
`version` values must be equal *as strings*, iOS needs the baked manifest to exist at all,
and the dump's directory must exist (`writeRuntimeDump()` creates it and throws on failure).

A screen's URI is not an HTTP route unless you make it one. If you want the same URI to work
in a browser — a shared link, a smoke test — register `NativeScreenRouteLoader` yourself as a
service tagged `routing.loader` and import it:

```yaml
# config/routes.yaml
native_screens:
    resource: .
    type: native_screens
```

It is not registered by the bundle, deliberately: an app that already puts `#[Route]` next
to `#[NativeScreen]` needs nothing from it.

Rendering is behind a seam. `NativeScreenResponder` turns a path into a published frame, but
it needs a `ScreenRendererInterface` implementation — routing's job ends at "this path is
screen X with these parameters", and turning X into an element tree is the component
lifecycle's job. The bundle registers the responder with `nullOnInvalid()` on the renderer,
so **if you have not registered a `ScreenRendererInterface` implementation, do not fetch
`NativeScreenResponder`** — its own constructor requires one. Either register a renderer, or
use `ComponentScreenFactory` directly:

```php
use Native\Symfony\Mobile\Ui\Component\ComponentScreenFactory;

$screen = $factory->open(new Counter());   // a fresh component instance, once per screen
$frame  = $screen->frame();                // first paint
$frame  = $screen->handle(['callback_id' => $id, 'type' => 0]);  // tap → repaint
```

See [Recipes](recipes.md#a-native-ui-screen-with-state) for a complete component, and
[the mobile API reference](mobile-api.md#native-ui) for elements, callbacks and styling.

## Building and running

```bash
bin/console native:mobile:manifest --dry-run        # what would the device boot?
bin/console native:mobile:build android --stage-only  # everything up to Gradle
bin/console native:mobile:build android --dry-run     # print the whole plan, touch nothing
bin/console native:mobile:build android --aab         # …then Gradle
bin/console native:mobile:build ios --export-options=auto --team-id=ABCDE12345
bin/console native:mobile:run android --list-devices
bin/console native:mobile:run android --device=emulator-5554
bin/console native:mobile:run ios --udid=…
```

These are split honestly in two, and the commands say so in their own output.

**What runs and is tested here:** staging the app (the same exclusion walk as desktop, the
same `.env` cleaning whose keep list beats its remove list so `APP_SECRET` survives, the CA
bundle, warming `var/cache` — which matters more here because the packaged PHP cannot load
opcache at all), the app archive, `bundle_meta.json` and the runtime route dump, the toolchain
detection with its remediation advice, and the exact plan of commands. `--stage-only` stops
precisely at that boundary and `--dry-run` executes nothing by construction.

**What has never been executed by these commands:** `./gradlew`, `adb`, `xcodebuild`,
`simctl`. There is no Android SDK and no Xcode in this environment. That is why `--dry-run` is
the primary interface for `native:mobile:run` rather than a convenience — it prints commands
you can paste into a terminal on a machine that does have the toolchain.

**Signing is not handled.** Gradle reads a keystore from the project's own `signingConfigs`
and Xcode from a provisioning profile. Passing either on a command line would put a password
in the process table, and a signing flow that cannot be tested here would be a liability sold
as a feature.

`native:mobile:run` does not stage — it assumes `native:mobile:build --stage-only` has run, or
that the project's existing bundle is current. Silently re-zipping a few hundred megabytes
would be the wrong default for a "change one file, look at the screen" loop.

`native:mobile:manifest` exists for the two cases a build does not cover: refreshing the route
list into an already-built project without a rebuild, and inspecting what the device would
boot. It fails rather than reporting success when there is no native project to bake into,
because Android can boot native from the runtime dump alone and iOS cannot — a dump without a
bake is a silent fallback to the WebView.

## Configuration

```yaml
# config/packages/native_mobile.yaml
native_mobile:
    app_id: com.example.app     # bundle identifier / Android application id
    name: App
    version: '1.0.0'            # QUOTE IT — see below
    fake_bridge: false          # true swaps in the recording fake
    platform: ~                 # 'ios' or 'android', for platform-variant style classes
    collect_garbage: false      # gc_collect_cycles() after each persistent request
```

**`version` must be a quoted string.** YAML `version: 1.0` is a float, and the container
coerces it to `"1"`. The hosts compare this value by string equality to decide whether to trust
the runtime route dump, so a coerced version silently disables it — and on iOS a non-string
version fails an `as? String` cast, compares as `""`, and does the same. The bundle rejects a
non-string here rather than normalising it, because by then PHP has already lost the difference
between `1.0` and `1`.

`platform: ~` drops every `ios:` / `android:` style variant. That is the safe default off a
device and the wrong one on it — set it from the device info if you use those variants.

`collect_garbage` is read into the container parameter `native_mobile.collect_garbage`, but
the shipped `persistent.php` calls `MobileRuntime::boot($kernel)` without it, so today it
only takes effect if you boot the runtime yourself.

## Commands

| Command | Purpose |
|---|---|
| `native:mobile:install --source=…` | Copy `androidstudio/` and `xcode/` into the app and retarget their bootstrap paths |
| `native:mobile:doctor` | Report what the integration can see; most useful run on a device |
| `native:mobile:manifest` | Write `bundle_meta.json` and the runtime route dump |
| `native:mobile:build <android\|ios>` | Stage the app and produce an APK/AAB or an iOS archive |
| `native:mobile:run <android\|ios>` | Debug build, install and launch |
