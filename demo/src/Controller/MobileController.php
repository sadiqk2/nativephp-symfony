<?php

declare(strict_types=1);

namespace App\Controller;

use App\Mobile\CounterScreen;
use App\Mobile\ScreenCatalog;
use Native\Symfony\Mobile\Api\Device;
use Native\Symfony\Mobile\Api\Dialog;
use Native\Symfony\Mobile\Api\SecureStorage;
use Native\Symfony\Mobile\Bridge\BridgeInterface;
use Native\Symfony\Mobile\Bridge\FakeBridge;
use Native\Symfony\Mobile\Ui\Component\ComponentScreenRenderer;
use Native\Symfony\Mobile\Ui\Component\InteractionEvent;
use Native\Symfony\Mobile\Ui\Routing\NativeScreenResponder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The mobile half, shown in a desktop window — which needs a word of honesty.
 *
 * Mobile is one process with PHP compiled into it, and its API is a compiled
 * extension function, `nativephp_call()`. That function exists only inside a packaged
 * iOS or Android app. This environment has no Xcode and no Android SDK, so nothing
 * on these screens has run on a device: the bridge is the shipped recording fake, and
 * what the pages show is *what would be sent*.
 *
 * That is still worth showing, because it is the part a Symfony developer has to
 * write, and because the element trees below are byte-compared against upstream's own
 * collector in the bundle's test suite. The wire format is verified. The device is
 * not.
 */
final class MobileController extends AbstractController
{
    public function __construct(
        private readonly BridgeInterface $bridge,
        private readonly ScreenCatalog $screens,
    ) {
    }

    #[Route('/mobile', name: 'mobile', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('mobile/index.html.twig', [
            'fake' => $this->bridge instanceof FakeBridge,
            'available' => $this->bridge->isAvailable(),
            'screens' => $this->screens->all(),
        ]);
    }

    /**
     * The WebView path: ordinary Twig, ordinary controller, native APIs by injection.
     *
     * An app that declares no native screens takes this path by construction — both
     * platforms' BootPlanner falls back to a WebView when the manifest is empty — so
     * for most of a Symfony app, "mobile" means exactly this and nothing more.
     */
    #[Route('/mobile/device', name: 'mobile_device', methods: ['GET'])]
    public function device(Device $device, SecureStorage $storage, Dialog $dialog): Response
    {
        if ($this->bridge instanceof FakeBridge) {
            // Canned replies, so the page has something to render off a device. On a
            // device these come from the OS.
            $this->bridge->willReturn('Device.Info', [
                'platform' => 'ios', 'model' => 'iPhone 15 Pro', 'osVersion' => '17.4', 'locale' => 'en_GB',
            ]);
            $this->bridge->willReturn('SecureStorage.Get', ['value' => 'tok_live_9f2c']);
            $this->bridge->willReturn('Device.BatteryInfo', ['level' => 0.62, 'charging' => false]);
        }

        $info = $device->info();
        $battery = $device->batteryInfo();

        $storage->set('session_token', 'tok_live_9f2c');
        $token = $storage->get('session_token');

        // Returns true because the toast was *presented*, not because anyone saw it.
        // Camera, biometrics, media pickers and the payment sheet are the same shape,
        // and the bundle puts them on dispatch() rather than call() so the type says
        // so.
        $toasted = $dialog->toast('Saved locally');

        return $this->render('mobile/device.html.twig', [
            'fake' => $this->bridge instanceof FakeBridge,
            'info' => $info,
            'battery' => $battery,
            'token' => $token,
            'toasted' => $toasted,
            'calls' => $this->bridge instanceof FakeBridge ? $this->bridge->calls : [],
        ]);
    }

    /**
     * The native-UI path, authored in Twig.
     *
     * The template builds an element tree with `native()` and publishes it; what the
     * page shows is the wire format the SwiftUI and Compose renderers consume.
     */
    #[Route('/mobile/profile', name: 'mobile_profile', methods: ['GET'])]
    public function profile(): Response
    {
        return $this->render('mobile/profile.html.twig', [
            // Class strings, not parsed output. The template hands them to the `class`
            // option, which routes them through StyleApplier — the piece that knows
            // `bg` belongs in the style bucket and `flexGrow` on the wire is `flex_grow`.
            // Parsing here and passing the result as `layout` published camelCase keys
            // and a colour in the layout bucket: accepted, and silently ignored by every
            // renderer.
            'card' => 'flex-1 p-4 gap-3 bg-white rounded-xl',
            'muted' => 'gap-1',
        ]);
    }

    /**
     * A stateful component reached the way a device reaches it: through routing.
     *
     * This is the whole native-UI path in one action — `#[NativeScreen('/counter')]` is
     * matched by the same matcher that runs on device before PHP exists, the screen class
     * is built by the container (so it can have dependencies), the component renders, an
     * interaction id comes back, and the next frame is a delta against the last one.
     *
     * On a device the runloop holds this for as long as the screen is visible and `$count`
     * simply stays where it is. In a browser there is no runloop and each request is a
     * fresh process, so the taps are *replayed* to reach frame N — a demonstration of the
     * frame cycle, not a simulation of the device's lifetime. The page says so too.
     */
    #[Route('/mobile/counter', name: 'mobile_counter', methods: ['GET'])]
    public function counter(
        Request $request,
        NativeScreenResponder $responder,
        ComponentScreenRenderer $renderer,
    ): Response {
        $taps = min(20, max(0, $request->query->getInt('taps')));

        // Loads the #[NativeScreen] attributes into the route registry. On a device this
        // happens once at boot; here it is idempotent and cheap.
        $this->screens->all();

        $frames = [$responder->respond('/counter')];

        // The id the device would send back for this expression. It is a content hash, so
        // it is stable across frames — and it is only ever used as a lookup key: the
        // string 'increment' comes out of *our* registry, never off the wire.
        $incrementId = $responder->callbacks()?->idFor('increment');

        for ($tap = 0; $tap < $taps && null !== $incrementId; ++$tap) {
            // False back would mean no component is mounted for this pattern — routine on
            // a device, where a stale frame can still be on screen, and a bug here.
            if (!$renderer->dispatch('/counter', InteractionEvent::press($incrementId))) {
                break;
            }

            $frames[] = $responder->republish();
        }

        $root = $renderer->mounted('/counter');
        \assert($root instanceof CounterScreen);
        $count = $root->count();

        $renderer->forget('/counter');

        return $this->render('mobile/counter.html.twig', [
            'taps' => $taps,
            'count' => $count,
            'incrementId' => $incrementId,
            'firstFrame' => $frames[0],
            'lastFrame' => $frames[\count($frames) - 1],
            'frameCount' => \count($frames),
        ]);
    }
}
