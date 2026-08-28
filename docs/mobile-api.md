# Mobile API reference

All classes live under `Native\Symfony\Mobile\`. The seventeen API services are registered
`public` and autowirable; they are thin, typed wrappers over
`nativephp_call($method, $jsonPayload)`.

For the native-UI wire format — node shapes, hashes, callback ids, style vocabulary — read
[`../NATIVE-UI-CONTRACT.md`](../NATIVE-UI-CONTRACT.md). This page is the guide to using it.

---

## The bridge

`Bridge/BridgeInterface` has four methods, and the distinction between two of them is the
most important thing on this page:

| Method | Returns | Use |
|---|---|---|
| `call($method, $payload)` | `?array` — decoded JSON, `null` on any failure | Reads, and writes whose result is known immediately |
| `dispatch($method, $payload)` | `bool` — **was the call made**, not what it achieved | Anything that presents UI |
| `raw($method, $payload)` | `?string` | The undecoded reply |
| `isAvailable()` | `bool` | False outside a packaged app; every call then no-ops |

**A dispatch is not an outcome.** Camera, biometrics, media pickers and the payment sheet all
return as soon as the UI is *presented*. The result arrives later as an event on the native
side. Those methods deliberately sit on `dispatch()` so the distinction is visible in the
type. `$biometric->prompt()` returning `true` means a prompt appeared — treating it as "the
user authenticated" is an authentication bypass.

Non-JSON replies and throws from the extension are logged (channel `native_mobile`) and
turned into `null`, never swallowed silently, on the same principle as the desktop client:
"the native layer did not answer" must not look like "the native layer said nothing".

### Where do the results arrive?

On the WebView path, the host's JS bridge exposes `window.Native.dispatch(eventName, payload)`
— the same shape as desktop's preload — so an asynchronous result reaches **the page**, not
PHP. **This bundle implements no PHP-side receiver for mobile events**; there is no mobile
equivalent of the desktop `EventsController` and no mobile event classes. If PHP needs to know
about a captured photo, the page has to tell it (a `fetch()` to one of your routes). Plan for
that when you design a flow around `Camera`, `Biometric`, `Microphone` or `MobileWallet`.

---

## The groups

### Camera — `Api\Camera`

```php
$camera->photo(quality: 85, front: false);          // quality clamped to 1–100
$camera->pickMedia(type: 'image', multiple: true, maxItems: 5);  // 'image' | 'video' | 'all'
$camera->recordVideo(maxSeconds: 30, front: true);
```

All three are dispatches. Nothing is returned but "the UI was presented".

### Device — `Api\Device`

```php
$device->info();          // array<string,mixed>; [] when the bridge is absent
$device->id();            // ?string
$device->batteryInfo();   // array
$device->vibrate();       // no duration: Android hardcodes 200ms, iOS has none
$device->toggleFlashlight();       // toggles; there is no way to ask for a state
```

### Secure storage — `Api\SecureStorage`

iOS Keychain and Android Keystore. Values survive app updates but not a reinstall or a move
to another device — suitable for tokens, not for anything a user would be upset to lose.

```php
$storage->set('token', $jwt, accessibility: null);   // bool
$storage->get('token');                              // ?string
$storage->has('token');
$storage->delete('token');
```

**Dots in a key are not part of the key** — the same dot-prop behaviour the desktop settings
store has. Use a flat separator if you care.

### Geolocation — `Api\Geolocation`

This reads unlike a browser's geolocation API on purpose. Background fixes are buffered
natively, because a phone can accumulate thousands while the app is asleep and waking PHP for
each would drain the battery.

Every method that acts on a watch takes its id, because several can run at once. The two
that start one return the id they minted — hold on to it, because it is the only way to stop,
drain or trim that watch later.

```php
$geo->requestPermissions();               // bool — the answer arrives as an event
$geo->currentPosition(fineAccuracy: true);// bool — a single fix, also as an event

$id = $geo->watchPosition(interval: 2000, minDistance: 5.0);   // ?string — foreground stream
$id = $geo->startBackgroundWatch();       // ?string — survives backgrounding, death, reboot

$watch = $geo->backgroundWatchStatus();   // array|null — the watch that outlived this process
$id = $watch['id'];

$page = $geo->drainWatch($id, $cursor);   // ['fixes' => list<array>, 'cursor' => int, 'size' => int]
// ... store $page['fixes'] durably ...
$geo->trimWatch($id, $page['cursor']);    // DESTRUCTIVE — reclaims the space; offsets rebase to 0

$geo->stopBackgroundWatch($id);           // keeps the buffer, so a final drain gets the tail
$geo->stopBackgroundWatch($id, clearBuffer: true);
$geo->clearWatch($id);                    // a foreground watch
```

`drainWatch()` does not remove what it returns: the buffer is append-only and `cursor` is a
byte offset into it, so persist the cursor that comes back and pass it in next time to read
only what is new. `trimWatch()` is the destructive half — call it only with a cursor a drain
returned, and only once those fixes are stored. Without trimming, the buffer grows until the
watch is stopped with `clearBuffer`.

`backgroundWatchStatus()` returns null when nothing is watching, rather than an array with an
`active` flag in it — an array is truthy either way.

`watchPosition()` and `startBackgroundWatch()` return null when nothing was started, which
off a device is the only outcome — no watch, so no id for one. The difference between them is
the native method: a foreground stream stops being delivered once the app is backgrounded,
and nothing here notices.

Results never come back as a return value. `currentPosition()`, `checkPermissions()` and
`requestPermissions()` are asynchronous — a true means the request reached the native layer,
and the fix or the permission status arrives as the event named by `$event`, defaulting to
the class upstream sends for that result. This bundle has no PHP-side receiver for mobile
events, so that event reaches the page through the host's JS bridge; the `id` sent with each
request is what a listener matches on, which is why one is always generated when you do not
supply one.

### Microphone — `Api\Microphone`

`start()`, `stop()`, `pause()`, `resume()` (dispatches), `status()`, `isRecording()`,
`recording()` (reads).

### Biometric — `Api\Biometric`

```php
$biometric->prompt();   // the OS supplies the wording; the bridge takes none
```

Asynchronous. See the warning above.

### Push notifications — `Api\PushNotifications`

`enroll($id = null, $event = TOKEN_GENERATED)` is the one call that prompts the user, and the
only way to learn the token on a first launch: it dispatches, and the token arrives as the
event named by `$event`. `token()` (`?string`), `checkPermission()` (array, reads the state
without prompting), `isAuthorised()`, `clearBadge()`.

### Browser — `Api\Browser`

`open($url)` hands off to the system browser; `openInApp($url)` uses the in-app browser;
`openAuth($url)` opens an ephemeral OAuth session, using the callback scheme from the app's
own deeplink configuration.

### Share — `Api\Share`

`url($url, $title, $text)`, `file($path, $title, $text)`.

### Dialog — `Api\Dialog`

`toast($message, $duration = 'long')` for a transient message.

```php
$dialog->alert('Delete file?', 'This cannot be undone', [
    'Cancel',
    ['label' => 'Delete', 'style' => 'destructive'],
], id: 'delete-42');
```

Asynchronous: the tap arrives as an event carrying `index`, `label` and the `id`. Button
styles are `default`, `cancel` and `destructive` — anything else is refused rather than
rendered as a plain button. Pass no buttons and both hosts substitute a single OK.

### Scanner — `Api\Scanner`

`scan($prompt = null, $continuous = false, $formats = ['qr'], $id = null, $event = CODE_SCANNED)`.
Opens the native scanner screen, which owns the camera until it closes. Codes arrive as
events, so a continuous scan reports each one as it is read instead of a list at the end; a
scanner the user closes without scanning reports `Scanner::SCANNER_CANCELLED`. Formats are
any of `qr`, `ean13`, `ean8`, `code128`, `code39`, `upca`, `upce`, `all`.

### Files — `Api\Files`

`copy($from, $to)`, `move($from, $to)`. Only for places PHP cannot reach — `content://` URIs
on Android, security-scoped URLs on iOS. For ordinary paths in the app sandbox use PHP's own
filesystem functions.

### Network — `Api\Network`

`status()` (array), `isConnected()`, `connectionType()` (`?string`).

### System — `Api\System`

`appearance()` (string), `isDarkMode()`, `minimize()`, `openAppSettings()`,
`setBackground($color)`.

### Wallet — `Api\MobileWallet`

```php
if ($wallet->isAvailable()) {
    $intent = $wallet->createPaymentIntent(1999, 'eur', ['order' => '123']);
    $wallet->presentPaymentSheet(
        $intent['client_secret'] ?? '',
        'Acme BV',
        $stripePublishableKey,
        'merchant.com.acme',
        'NL',
    );
    // …later, from a route the page calls back:
    $status = $wallet->paymentStatus($intent['id'] ?? '');
}
```

`presentPaymentSheet()` returning `true` is **not** a completed payment. Always read the
status back; never infer it from the presentation call.

### Performance — `Api\Performance`

`enable()`, `disable()`, `export()`, `showFpsOverlay()`, `startCaptureWindow()`,
`stopCaptureWindow()`, plus three input simulators — `simulatePress($callbackId, $nodeId = 0)`,
`simulateTextChange($callbackId, $text, $nodeId = 0)`,
`simulateToggle($callbackId, $value, $nodeId = 0)` — which drive the native UI for
instrumentation.

The callback id is the one the published frame carries, not a selector:
`ComponentScreen::callbackId('increment')` is how PHP gets it. Both hosts answer
`success: false` without it, which is what the previous `$target` string produced — a call
that succeeded in PHP and did nothing on the device.

### Native UI transitions — `Api\NativeUi`

`setTransition($transition)`, one of `fade`, `slide_from_right`, `slide_from_left`,
`slide_from_bottom`, `fade_from_bottom`, `scale_from_center`, `parallax_push`, `none`.

It has **no effect on the WebView path**, and is wrapped only so the 54-method surface is
complete rather than looking like an oversight.

---

## Testing the bridge

```php
use Native\Symfony\Mobile\Api\SecureStorage;
use Native\Symfony\Mobile\Bridge\FakeBridge;

$bridge = new FakeBridge();
$bridge->willReturn('SecureStorage.Get', ['value' => 'tok_123']);

self::assertSame('tok_123', (new SecureStorage($bridge))->get('token'));
self::assertSame('SecureStorage.Get', $bridge->lastCall()['method']);
self::assertContains('SecureStorage.Get', $bridge->methods());
```

`new FakeBridge(available: false)` models being outside a packaged app. Set
`native_mobile.fake_bridge: true` to use it through the container instead.

The real `Bridge` also takes an `$invoker` callable standing in for the extension function,
which is how the bundle tests the real class rather than only the fake:

```php
$bridge = new Bridge(null, fn (string $method, string $json): string => '{"value":1}');
```

---

## Native UI

Only relevant on the native-UI render path. Skip this if your app renders Twig into a
WebView.

### Elements

37 element classes covering all 36 wire types (`Fab` shares `pressable`) under
`Ui\Elements\`. Containers take children, leaves take their content:

```php
use Native\Symfony\Mobile\Ui\Elements\Button;
use Native\Symfony\Mobile\Ui\Elements\Column;
use Native\Symfony\Mobile\Ui\Elements\Text;
use Native\Symfony\Mobile\Ui\Elements\TextInput;

$tree = Column::make(
    Text::make('Hello')->fontSize(24)->fontWeight('bold'),
    TextInput::make()->placeholder('Name')->onChange('rename'),
    Button::make('Save')->onPress('save'),
)->layout(['gap' => 8, 'padding' => 16]);
```

Shared on every element: `key()`, `ref()`, `onPress()`, `onLongPress()`, `navigate()`,
`child()`, `layout()`, `style()`, `props()`.

Container and canvas setters, each byte-compared against upstream's equivalent:

| Element | Setters |
|---|---|
| `ScrollView` | `horizontal()`, `both()` for 2D panning, `showsIndicators()`, `autoScrollTo()` |
| `LazyGrid` | `columns()` (clamped at 1), `gap()`, `horizontal()`, `showsIndicators()` |
| `Line` | `from($x, $y)`, `to($x, $y)` |
| `Rect`, `Circle` | `at($left, $top)` |
| `Image` | `fit()`, `tintColor()`, `alt()` |
| `Button` | `color()`, `fontSize()`, `variant()`, `disabled()` |

`ScrollView::horizontal()` also sets `flex_direction`, because the main axis has to move
with the scroll axis — otherwise `overflow: scroll` applies to the height while the content
runs along the width, which is a carousel that does not scroll. `both()` deliberately does
not: 2D mode bypasses flex and the renderers honour each child's declared frame.

`Line` needs all four coordinates or it draws nothing.

**Give a `key()` to anything in a list that can reorder.** Node identity is otherwise
positional, and a reordered list reuses the wrong nodes — losing scroll position and input
focus. Node ids are FNV-1a over the key path, and both the hash and the 32-bit masking are
part of the wire format rather than an implementation detail.

Two element defaults exist because their absence made a tree diverge from upstream's:
`ScrollView`'s `overflow=2` and `Circle`'s `border_radius=9999`. `Spacer` defaults
`flex_grow=1`, so it works dropped in bare.

### Publishing a frame

```php
$tree = $publisher->publish($root, $callbacks);   // array<string,mixed>, the published tree
$publisher->resetDiffState();                     // on navigation — see below
```

`ElementPublisher` keeps the previous frame's node hashes, which is what makes subtree reuse
work: an interaction that changes one label republishes one node and marks the rest
`flags: 1`. Upstream leaves that to the caller, and without it every frame is a full repaint.

Off a device there is nothing to publish to, so frames are **captured** —
`capturedFrames()`, `lastFrame()` — which is what makes a native screen testable at all.

**Reset the diff state when the screen changes.** Node ids are only meaningful within one
screen's tree; carrying hashes across a navigation lets the renderer splice an unrelated
subtree it still has cached. `ComponentScreen` and `NativeScreenResponder` both do this for
you.

### Callbacks

`Ui\CallbackRegistry` maps a handler expression to the integer id that travels to the device
and back. Ids are content-derived (FNV-1a masked to 31 bits, because a callback id travels as
a signed Kotlin `Int`), so a registry can be rebuilt and the ids stay stable. The scope —
joined to the expression with `\x1F` — is what keeps two screens' `save` handlers apart.

`0` is never a valid id: the native side reads 0 as "no callback".

### Components

```php
use Native\Symfony\Mobile\Ui\Component\NativeAction;
use Native\Symfony\Mobile\Ui\Component\NativeComponent;
use Native\Symfony\Mobile\Ui\Element;
use Native\Symfony\Mobile\Ui\Elements\Button;
use Native\Symfony\Mobile\Ui\Elements\Column;
use Native\Symfony\Mobile\Ui\Elements\Text;

final class Counter extends NativeComponent
{
    private int $count = 0;

    #[NativeAction]
    public function increment(): void
    {
        ++$this->count;
    }

    protected function render(): Element
    {
        return Column::make(
            Text::make("Count: {$this->count}"),
            Button::make('+')->onPress('increment'),
        );
    }
}
```

- State is ordinary typed properties. The PHP process is long-lived in persistent mode, so
  there is nothing to serialise and nothing to rehydrate — unlike Livewire, which must,
  because HTTP throws the object away.
- **Only `#[NativeAction]` methods are reachable from the device.** Default-deny, and the
  allowlist is greppable. An id arriving from the native side is only ever a lookup key: it
  never becomes a method name.
- `render()` is `protected` on the base class. `mount(Child::class, ['prop' => $v], key: $k)`
  mounts a child and returns its subtree; identity is the explicit key, else the occurrence
  index of that class in this render. Nesting is capped at 32 levels, because a component that
  mounts itself would otherwise freeze the screen with no error.
- Props are assigned to **public, non-static, non-readonly** properties, and an unknown prop
  name throws. Upstream ignores unmatched props because its props are HTML attributes; here
  you wrote a PHP array, so an unmatched key is a typo — and a typo'd prop is a child
  rendering last frame's data forever.
- `onMount()`, `onUnmount()`, `onNavigate($key)` are the hooks. Navigation itself is not
  implemented: `__navigate` callbacks are recognised so a navigating element still renders and
  dispatches, and they arrive at `onNavigate()`. Nothing else happens.
- A component instance can be bound to exactly one screen. Reusing one throws, because the
  reuse would carry state and children across invisibly.

Driving a screen through routing — what a device does, and what the bundle wires by default:

```php
// ScreenRendererInterface is aliased to ComponentScreenRenderer, so this needs no glue.
$frame = $responder->respond('/counter');                        // first paint
$id    = $responder->callbacks()->idFor('increment');            // expression → id
$renderer->dispatch('/counter', InteractionEvent::press($id));   // false = nothing mounted
$frame = $responder->republish();                                // repaint, as a delta
$renderer->forget('/counter');                                   // navigating away
```

The renderer keeps one component instance per route pattern, and binds it to the registry the
responder handed it. Both matter and both fail silently otherwise: a per-frame instance gives a
screen whose state resets on every tap, and a self-made registry gives one whose buttons do
nothing. A screen with constructor dependencies is resolved from the container — every
`NativeComponent` is autoconfigured with the `native.screen` tag — and route parameters reach a
screen that declares `withRouteParameters(array $params)`.

Driving one directly, without routing:

```php
$screen = $factory->open(new Counter());          // ComponentScreenFactory
$frame  = $screen->frame();                       // first paint
$frame  = $screen->handle($eventArrayFromDevice); // ?array — null when the id is unknown
$id     = $screen->callbackId('increment');       // for tests: expression → id
$screen->close();                                 // unmounts the graph, drops diff state
```

`handle()` returning `null` is routine, not an error: the device may still be showing a
superseded frame and tap a node that has since gone away. A registered id that cannot be
dispatched throws `CallbackRefused`.

`frame()` calls `assertCallbacksDispatchable()` **after** publishing — expressions are
registered while the tree is serialised, not while it is built. That check is what turns
`->onPress('incremnt')` from a button that silently does nothing on a device into an
exception. Argument counts are deliberately not checked there, because how many values a call
receives depends on the event type, which is not known until the interaction arrives.

Interaction payloads are narrowed by type in `InteractionEvent`: `PRESS = 0`,
`LONG_PRESS = 1`, `TEXT_CHANGE = 2`, `TOGGLE_CHANGE = 3`, `SUBMIT = 4`,
`SLIDER_CHANGE = 9`, `CHECKBOX_CHANGE = 10`, `RADIO_CHANGE = 11`, `SELECT_CHANGE = 12`,
`TAB_CHANGE = 13`, `SHEET_DISMISS = 14`. A text handler cannot be handed an array, a toggle
handler cannot receive the string `"false"`, and an unknown type carries nothing at all.

### Twig authoring

```twig
{% set tree = native('column', {layout: {gap: 8}}, [
    native('text',   {text: 'Hello', fontSize: 24}),
    native('button', {label: 'Tap me', onPress: 'save'}),
    native('spacer'),
]) %}
```

`native(type, options, children)` and `native_types()` are the two Twig functions;
`children` may be one element, a list, or nothing. Passing a string where an element belongs
throws with the child's index and the type it was in, rather than failing deep in the tree
walk.

Upstream's Blade equivalent is a tag precompiler that rewrites `<native:*>` tags into
collector calls specifically to bypass Blade's component lifecycle for speed. Twig needs none
of that: its functions are already calls.

Note the Twig extension only *builds* a tree. Publishing is `ElementPublisher::publish()`,
which needs a `CallbackRegistry` — the bundle ships **no** `native_publish()` Twig function,
because the join carries two decisions that belong to the app: which registry a frame's ids
belong to (an interaction is looked up in the same one), and when to drop the diff state. It is
about ten lines; [`demo/src/Twig/NativePublishExtension.php`](../demo/src/Twig/NativePublishExtension.php)
is a working one.

### Styling

`Ui\Style\StyleParser` parses a Tailwind class string into the canonical camelCase map from
the contract's §4d — verified token-for-token against upstream across 684 tokens and 1,336
class strings, plus a 48,849-parse differential sweep:

```php
$parser->parse('flex-1 p-4 gap-2');
// ['flexGrow' => 1, 'flexShrink' => 1, 'flexBasis' => 0, 'padding' => 16, 'gap' => 8]

$parser->parse('bg-theme-surface', $dropped);   // $dropped lists tokens that produced nothing
```

Two things to be careful about:

**That map is not the wire vocabulary.** Layout keys on the wire are snake_case
(`flex_grow`), element props are camelCase (`fontSize`) — upstream's inconsistency, which the
element *setters* resolve. Upstream has an `applyLayout()` that dispatches each parsed key
through the right setter; **this bundle does not implement that dispatcher yet**. So
`->layout($parser->parse('flex-1'))` would put `flexGrow` on the wire, which the renderers do
not read. Until an applier exists, either set wire keys directly
(`->layout(['flex_grow' => 1])`) or route the parsed values through the typed setters
yourself.

**Theme colours need a resolver.** Without a `ThemeColorResolverInterface` service, every
`bg-/text-/border-theme-*` class resolves to nothing rather than to a guessed colour — 31 of
the 684 corpus tokens. `ArrayThemeColorResolver` is the simple implementation. Platform
variants (`ios:` / `android:`) drop unless `native_mobile.platform` is set.

There is one intentional divergence from upstream, and it is a bug fix: upstream's
theme-border branch emits `borderWidth: 1` unconditionally, so `border-2 border-theme-outline`
renders a 1pt border while the reverse order gives 2. Here both orderings give 2. See patch
`0008` in [`../upstream-patches/`](../upstream-patches/README.md).

## Commands

| Command | Purpose |
|---|---|
| `native:mobile:install --source=…` | Copy `androidstudio/` and `xcode/` into the app and retarget their bootstrap paths |
| `native:mobile:doctor` | Report what the integration can see; most useful run on a device |
| `native:mobile:manifest` | Write `bundle_meta.json` and the runtime route dump; `--dry-run` prints both |
| `native:mobile:build <android\|ios>` | Stage the app and produce an APK/AAB or an iOS archive; `--stage-only` stops before Gradle/Xcode |
| `native:mobile:run <android\|ios>` | Debug build, install and launch; `--dry-run` prints the plan |

See [getting started](getting-started-mobile.md#building-and-running) for what those commands
have and have not actually executed in this environment.
