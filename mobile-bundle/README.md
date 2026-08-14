# native-symfony/mobile-bundle

Build iOS and Android applications with Symfony, on NativePHP's mobile runtime.

Status: **the WebView render path is implemented, and the native-UI wire format is
implemented and verified.** All **54 native bridge methods** are wrapped, the SAPI
shim and persistent runtime are in place, element trees are byte-identical to
upstream's, and 76 tests cover it.

> **Verified by tests, not by a device.** Unlike the desktop bundle — whose claim is
> backed by a screenshot of a running packaged app — nothing here has run on a phone
> or an emulator. This environment has no Xcode and no Android SDK. Treat mobile as a
> well-tested design awaiting device verification, and read that distinction as load
> bearing.

> **Licence.** NativePHP Mobile is a commercial product, unlike the MIT-licensed
> desktop runtime. Read its terms before distributing anything built this way.

## Why there is no Edge port here

Mobile has two render paths. The native-UI one draws SwiftUI and Jetpack Compose from
Blade templates, and its engine is 17,316 LOC — the reason an earlier version of this
project wrote mobile off entirely.

It is optional. `BootPlanner`, on both platforms, falls back to a WebView whenever an
app registers no `Route::native` patterns, and `NATIVEPHP_BOOT_MODE=web` forces it at
build time. Its own docblock calls that path *"always safe, byte-identical to the
pre-native-first behavior"*. A Symfony app registers no native routes, so it takes the
WebView path by construction — and Twig in a WebView is just Twig.

Full reasoning: [`../MOBILE-ANALYSIS.md`](../MOBILE-ANALYSIS.md).

## What it gives you

```php
final class ProfileController extends AbstractController
{
    public function __construct(
        private readonly Camera $camera,
        private readonly SecureStorage $storage,
        private readonly Device $device,
    ) {}

    #[Route('/profile/photo')]
    public function photo(): Response
    {
        // Asynchronous: the result arrives as an event once the user has acted.
        // A true here means the camera was opened, nothing more.
        $this->camera->photo(quality: 85);

        return $this->redirectToRoute('profile');
    }
}
```

Sixteen autowired API services: `Biometric`, `Browser`, `Camera`, `Device`, `Dialog`,
`Files`, `Geolocation`, `Microphone`, `MobileWallet`, `NativeUi`, `Network`,
`Performance`, `PushNotifications`, `SecureStorage`, `Share`, `System`.

## Install

```bash
composer require native-symfony/mobile-bundle

git clone --depth 1 https://github.com/NativePHP/mobile-air /tmp/np-mobile
bin/console native:mobile:install --source=/tmp/np-mobile/resources

bin/console native:mobile:doctor    # what the integration can see
```

Then build with Android Studio or Xcode. There is no headless path — both toolchains
are required.

## Configuration

```yaml
native_mobile:
    app_id: com.example.app
    name: 'My App'
    version: '1.0.0'
    fake_bridge: false      # true swaps in a recording fake — see below
    collect_garbage: false  # gc_collect_cycles() after each persistent request
```

## Testing without a device

`nativephp_call()` is a compiled extension that exists only inside a packaged app, so
outside one the bridge is always unavailable and every call returns null. Two ways
around that, both shipped rather than kept in this package's own tests:

```yaml
# config/packages/test/native_mobile.yaml
native_mobile:
    fake_bridge: true
```

```php
$bridge = new FakeBridge();
$bridge->willReturn('SecureStorage.Get', ['value' => 'tok_123']);

self::assertSame('tok_123', (new SecureStorage($bridge))->get('token'));
self::assertSame('SecureStorage.Get', $bridge->lastCall()['method']);
```

The real `Bridge` also accepts an `$invoker` callable standing in for the extension
function, which is how this package tests the real class rather than only the fake.

## The two things most likely to bite

**A dispatch is not an outcome.** Camera, biometrics, media pickers and the payment
sheet all return once the UI is *presented*. The result arrives later as an event.
These deliberately live on `dispatch()` rather than `call()` so the distinction is
visible in the type.

**Dots in a `SecureStorage` key are not part of the key** — the same dot-prop
behaviour the desktop bundle documents for settings.

## Native UI from Twig

The second render path. `Ui/` produces the element tree the SwiftUI and Jetpack
Compose renderers consume — the `super-native` equivalent, authored from Twig:

```twig
{% do native_publish(native('column', {layout: {gap: 8}}, [
    native('text',   {text: 'Hello', fontSize: 24}),
    native('button', {label: 'Tap me', onPress: 'save'}),
    native('spacer'),
])) %}
```

Upstream's Blade equivalent is a tag precompiler that rewrites `<native:*>` tags into
collector calls specifically to *bypass* Blade's component lifecycle for speed. Twig
needs none of that: its functions are already calls.

**Verified against upstream, not just modelled on it.** `UiWireFormatTest` loads
upstream's own collector through a stub autoloader — its `Edge` classes have no
framework dependencies — and byte-compares trees, content hashes, callback ids and
navigation keys. That comparison caught three things a careful reading had got wrong;
they are written up in [`../NATIVE-UI-CONTRACT.md`](../NATIVE-UI-CONTRACT.md).

What is implemented: the tree builder with the identity, Merkle-hash and reuse rules,
a callback registry, 13 of 37 element types, a factory, the Twig extension, and a
publisher that keeps the diff state between frames. What is not: the Tailwind-subset
style parser, `Route::native` equivalents, and a component lifecycle — that last being
the real design question, since Symfony has nothing Livewire-shaped to borrow.

## Architecture

| | |
|---|---|
| `Bridge/` | `nativephp_call()` wrapper, its interface, and a recording fake |
| `Api/` | 16 typed services covering all 54 methods |
| `Runtime/MobileRuntime` | the persistent kernel — boot once, serve many |
| `Runtime/ServerRequestFactory` | a `Request` from the host's superglobals |
| `Runtime/ResponseEmitter` | a raw HTTP message on stdout, header-injection safe |
| `Runtime/MobileRuntimePatcher` | retargets the hosts' hardcoded bootstrap paths |
| `Resources/bootstrap/` | `native.php`, `persistent.php`, `dispatch.php`, `console.php` |
| `Ui/` | the native element tree: builder, registry, 13 elements, factory, publisher |
| `Ui/Twig/` | `native()` for authoring trees from templates |

`native.php` pays for the autoloader and container per request; `persistent.php` plus
`dispatch.php` pay once. Prefer the persistent pair wherever the host supports it.

## Tests

```bash
composer install && vendor/bin/phpunit
```

76 tests. `UiWireFormatTest` byte-compares element trees against upstream's own
collector. `BridgeCoverageTest` parses the upstream sources and fails if a native
method has no wrapper, so upstream drift breaks the suite. `MobileRuntimeTest` drives
a real Symfony kernel through the persistent runtime and proves state does not leak
between requests and that a throwing controller is contained rather than fatal.
