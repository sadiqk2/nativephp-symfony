# Testing

Desktop apps are testable without Electron, and mobile apps without a device. Both bundles
put the double at the *transport* seam rather than in front of each manager, which is the
difference between testing your payloads and testing a stub.

Everything here needs `phpunit/phpunit`, which your app already has wherever you would use it.

- [Wiring: one flag and one trait](#wiring-one-flag-and-one-trait)
- [Faking the desktop runtime](#faking-the-desktop-runtime)
- [Asserting what the app asked for](#asserting-what-the-app-asked-for)
- [Pushing events into the app](#pushing-events-into-the-app)
- [Mobile](#mobile)
- [What these tests cannot tell you](#what-these-tests-cannot-tell-you)

---

## Wiring: one flag and one trait

```yaml
# config/packages/test/native_desktop.yaml
native_desktop:
    testing: true
```

That re-points the `ClientInterface` alias at `FakeRuntime` and **nothing else**: every
manager, every controller and the event bridge stay exactly as they are in production, so a
functional test exercises the real payload building against a runtime that records instead of
one that has to be running. It also registers `RuntimeEventSimulator`, and
`RuntimeExpectations` when PHPUnit is present — all three public.

Set it in `config/packages/test/` only. With it on, nothing reaches a real runtime.

The trait wires the three pieces in one `use`, and works both ways round:

```php
use Native\Symfony\Desktop\Testing\InteractsWithNativeRuntime;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BootstrapperTest extends KernelTestCase
{
    use InteractsWithNativeRuntime;

    public function testItOpensTheMainWindow(): void
    {
        self::bootKernel();

        self::getContainer()->get(\App\Native\Bootstrapper::class)->boot();

        $this->nativeExpects()->assertWindowOpened('main', ['width' => 1100]);
    }
}
```

- In a `KernelTestCase` with `testing: true`, `nativeRuntime()` finds the fake the container
  already injected everywhere — so the services under test are asserted against the same call
  log without the test handing any of them a double. Two fakes in one test, with assertions
  made against the one nobody called, is the failure mode this avoids.
- In a plain `TestCase` it builds its own `FakeRuntime`, and you construct managers with
  `new`. `nativeWindows()` is provided because `WindowManager` needs a `UrlResolver` and a
  `RequestStack`; every other manager is just `new XManager($this->nativeRuntime())`.

The trait's surface:

| | |
|---|---|
| `nativeRuntime(): FakeRuntime` | The fake every native call goes through |
| `nativeExpects(): RuntimeExpectations` | Assertions |
| `nativeEvents(?EventDispatcherInterface): RuntimeEventSimulator` | Push events; defaults to the container's dispatcher so the app's real listeners run |
| `nativeDispatcher(): EventDispatcher` | A dispatcher owned by the test, for a listener in isolation |
| `nativeWindows(?string $baseUrl = 'http://localhost'): WindowManager` | |
| `nativeRequestFromWindow(string $windowId, string $uri = '/')` | Pretend this request came from that window |
| `nativeRequests(): RequestStack` | |

`nativeRequestFromWindow()` matters more than it looks: the runtime appends `?_windowId=` to
everything it navigates, and every manager method with an optional id falls back to it.
Without a request carrying the parameter, an id-less call resolves to `'main'` and the test
cannot distinguish a correct fallback from a lost id.

## Faking the desktop runtime

`Native\Symfony\Desktop\Testing\FakeRuntime` implements `ClientInterface`, records every request, and
answers with whatever the test scripted. Because only the transport is faked, the real
`WindowManager` and the real `PendingWindow` build the payload — so what you assert on is the
wire, not a stub. All 116 endpoints are covered the day they are added, including the ones
with no manager.

Overriding the alias by hand still works if you prefer it:

```php
self::getContainer()->set(ClientInterface::class, FakeRuntime::available());
```

`FakeRuntime::unavailable()` models a process the runtime did not start: every call throws
`RuntimeNotAvailable`, exactly as the real client does, so `isAvailable()` guards are testable.

### Scripting replies

```php
$fake = FakeRuntime::available()
    ->willReturn('app/version', ['version' => '1.2.3'])
    ->willReturnStatus('window/get/ghost', 404)
    ->willRespondWith('settings/theme', new Response(200, ['value' => 'dark']))
    ->willRespondUsing('clipboard', fn (RecordedCall $c) => new Response(200, $c->payload));
```

- Endpoints may be exact or `*` wildcards (`window/get/*`). Exact wins over wildcard, and
  among wildcards the longest — so `window/*` cannot shadow `window/get/main` by declaration
  order.
- `willRespondWith()` takes several responses; consecutive calls get consecutive ones and the
  last repeats, so polling an endpoint twice needs only one script entry.
- **An unscripted endpoint answers a bare 200 with no data.** That is deliberate: it is
  contract shape 1 (express sends the status phrase, which the real client normalises to "no
  data"), so reads return their zero values unless you script them. Smoothing that would let a
  test pass against a runtime that would fail.
- **A POST or DELETE payload that cannot be JSON-encoded throws `RuntimeCallFailed`** instead
  of being recorded, because the real client cannot send one either — a string that is not
  UTF-8 (a filename, a legacy database column) fails while the request is being prepared. GET
  is exempt: query parameters go through `http_build_query`, which validates nothing.

### Scripting the user

Dialogs cannot be tested any other way — they block the runtime's event loop and their return
value *is* a human decision. These are `willReturn()` with the runtime's exact response shape
filled in:

```php
$fake->userPicksFiles('/tmp/a.csv', '/tmp/b.csv');
$fake->userCancelsFileSelection();     // {result: undefined} — a MISSING key, not []
$fake->userSavesFileAs('/tmp/out.pdf');
$fake->userCancelsSave();
$fake->userClicksAlertButton(2);
$fake->userConfirms();                 // index 0 — confirm() builds [confirm, cancel]
$fake->userDeclines();                 // index 1
```

**An unscripted dialog is a dismissal, not an answer.** `dialog/open` degrades to cancelled,
`dialog/save` to null, and a message box answers with the payload's own `cancelId` — which is
what Electron returns for Escape and the window close button, and which `confirm()` sets to
the *declining* button for exactly that reason. So a `confirm()` nobody scripted comes back
`false`. Answering with button 0 instead would make "deleting requires confirmation" — the
most valuable test anyone writes against this kit — pass against code missing the guard
altogether.

### Scripting windows

```php
$fake->windowIs('main', ['width' => 800, 'height' => 600]);   // window/get/main + window/all
$fake->currentWindowIs('main');                               // …and window/current
$fake->windowDoesNotExist('ghost');                           // 404 — and drops it from window/all
$fake->noCurrentWindow();                                     // window/current answers 500
```

`noCurrentWindow()` is not an edge case: it is `window/current`'s documented behaviour when
the app is backgrounded, since the runtime dereferences `getFocusedWindow().id` with no null
guard. Any code that reads the current window should have a test for it.

Any id works, including one with a space or a slash: `WindowManager::get()` rawurlencodes the
id because it reaches the URL, and both scripting helpers encode it the same way, so `get()`
and `all()` cannot disagree about a window.

### Inspecting the log

```php
$fake->calls();                    // list<RecordedCall>, in order
$fake->callsTo('child-process/*'); // filtered
$fake->forgetCalls();              // keep the script, clear the log — for multi-phase tests
```

`RecordedCall` carries `$sequence`, `$method`, `$endpoint`, `$payload`, plus
`matchesEndpoint($pattern)`, `payloadContains($subset)` and `describe()`.

## Asserting what the app asked for

`RuntimeExpectations` wraps a `FakeRuntime` and phrases assertions as intents. When one fails
it prints the whole call log, because the question a red test has to answer is "then what did
it do instead?".

```php
$expect = $this->nativeExpects();   // or new RuntimeExpectations($fake)

// …exercise the app…

$expect->assertWindowOpened('main', ['width' => 1000]);
$expect->assertWindowTitled('Ledger');
$expect->assertWindowResized(760, 520, 'main');
$expect->assertWindowNavigatedTo('/reports');
$expect->assertNoWindowClosed();

$expect->assertFileDialogShown(['multiSelections' => true]);
$expect->assertFileDialogShownAttachedTo('main');
$expect->assertNoDialogShown();
$expect->assertAlerted('Delete 4 items?');
$expect->assertErrorBoxShown('Could not start');

$expect->assertNotificationSent('Export finished');
$expect->assertNoNotificationSent();

$expect->assertProcessStarted('messenger_async');
$expect->assertProcessStopped('import');
$expect->assertMessageSentToProcess('import', ['pause' => true]);
$expect->assertProcessNotStarted();

$expect->assertQuitRequested();
$expect->assertNoQuitRequested();

// 116 endpoints will always outrun the named helpers:
$expect->assertCalled('settings/theme', ['value' => 'dark']);
$expect->assertNotCalled('app/relaunch');
$expect->assertCalledTimes(2, 'window/show');
$expect->assertNothingSent();          // the assertion for a guarded path
```

Passing `null` as an id asserts that *some* window was opened, resized, and so on.

**These assert requests, never outcomes.** The runtime ignores unknown window ids silently,
`menu-bar/*` and `context/*` answer `200` before doing any work, and no endpoint reports
whether the user saw anything. `assertWindowOpened('main')` means "the app asked for window
`main`" — the strongest claim any test without an Electron process can make. The one place a
real outcome is observable is a dialog's return value, and that is your own code to assert on.

## Pushing events into the app

`RuntimeEventSimulator` pushes an event exactly as the runtime's
`POST /_native/api/events` would — through the real `EventsController`, so JSON encoding, the
dual argument spreading and the double dispatch of caller-named events all apply. A simulator
that skipped those would prove nothing.

```php
$simulator = $this->nativeEvents();   // the container's dispatcher, so real listeners run

$simulator->windowResized('main', 760, 520);
$simulator->windowFocused('main');
$simulator->windowClosed('main');
$simulator->settingChanged('theme', 'dark');
$simulator->processExited('messenger_async', 137);
$simulator->processMessageReceived('import', "42 rows\n");
$simulator->menuItemClicked('save', label: 'Save');
$simulator->notificationClicked($reference);
$simulator->notificationReplied($reference, 'on my way');
$simulator->openedFromUrl('myapp://open/42');
$simulator->shortcutPressed('App\Shortcut\Palette', 'CommandOrControl+Shift+K');
$simulator->powerStateChanged('on-battery');
$simulator->screenLocked();
```

Anything not covered by a helper, including your own event names:

```php
$simulator->dispatch('Native\Desktop\Events\Windows\WindowMaximized', ['main']);
$simulator->dispatch('App\Menu\NewReport', ['from' => 'toolbar']);   // → NativeEvent
```

`dispatch()` returns nothing on purpose: the runtime discards your answer, so there is no
outcome to hand back. What a test asserts on is what its own listener did.

To check the mapping itself — that a payload really reaches the constructor argument you think
it does — use `make()`:

```php
$event = $simulator->make('Native\Desktop\Events\ChildProcess\ProcessExited', ['alias' => 'x', 'code' => 1]);
self::assertSame(1, $event->code);
```

If you construct the simulator yourself and test events your app dispatches by class name,
pass your app's own `EventFactory` — the one configured with `events.allowed_namespaces` — as
the second constructor argument. `nativeEvents()` and the container-registered service already
do that.

## Mobile

```php
use Native\Symfony\Mobile\Api\SecureStorage;
use Native\Symfony\Mobile\Bridge\FakeBridge;

$bridge = new FakeBridge();
$bridge->willReturn('SecureStorage.Get', ['value' => 'tok_123']);

self::assertSame('tok_123', (new SecureStorage($bridge))->get('token'));
self::assertSame('SecureStorage.Get', $bridge->lastCall()['method']);
```

`new FakeBridge(available: false)` models being outside a packaged app. Through the container,
set `native_mobile.fake_bridge: true` in `config/packages/test/native_mobile.yaml`.

Two things the fake copies from the real bridge rather than smoothing over. A payload that
cannot be JSON-encoded throws `InvalidArgumentException` instead of being recorded, because
the wire is JSON and `Bridge::raw()` refuses one too — an app reaches this with a filename, a
scanned barcode or a database column that is not UTF-8. And a scripted reply that is empty,
whitespace or not JSON reads as `null` from `call()`, which is what the real bridge does with
whatever the native side actually answered.

The real `Bridge` accepts an `$invoker` callable in place of the extension function, so the
real class can be tested too:

```php
$bridge = new Bridge(null, fn (string $method, string $json): ?string => '{"ok":true}');
```

### Native-UI screens

There is no device, so the assertion target is the **published frame**, which
`ElementPublisher` captures when the extension is absent:

```php
$screen = $factory->open(new TodoScreen());
$frame = $screen->frame();

self::assertSame('column', $frame['type']);
self::assertSame('Todo', $frame['children'][0]['props']['text']);

$next = $screen->handle([
    'callback_id' => $screen->callbackId('add'),
    'type' => InteractionEvent::PRESS,
]);

// Unchanged nodes come back as {id, type, flags: 1, _hash} — that is subtree reuse,
// not a missing node. Only what actually changed carries layout, style, props and children.
self::assertSame(1, $next['children'][0]['flags'] ?? null);
```

`ComponentScreen::callbackId($expression)` goes from expression to id, which is the direction
a test needs (the device learns ids from the frame). `frame()` also runs
`assertCallbacksDispatchable()`, so **rendering a screen in a test is what catches a typo'd
handler** — otherwise it is a dead button on a device with no error anywhere.

## What these tests cannot tell you

Both bundles carry coverage tests that parse upstream's own sources, so upstream drift becomes
a failing test rather than a discovery in production: `ContractCoverageTest` (the 116 express
routes and the 44 event names), `BridgeCoverageTest` (the 62 bridge methods, 5 of which nothing here wraps yet) and
`UiElementCoverageTest` (the 36 element types, asserted in **both** directions, since a type
the renderers do not know produces a missing region on the device and no error anywhere).
Those live in the bundles, not in your app.

What no test in your app can establish:

- that a window was *seen*. Every assertion above is about a request.
- that a `200` meant success. `menu-bar/*` and `context/*` acknowledge before acting.
- that the runtime behaves as the contract describes. That is what
  [`../SPIKE-RESULTS.md`](../SPIKE-RESULTS.md), [`../M2-RESULTS.md`](../M2-RESULTS.md) and
  [`../M3-RESULTS.md`](../M3-RESULTS.md) are: the record of running it and finding out.
- anything about mobile on a real device. Nothing in this project has run on one.
