<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Tests;

use Native\Symfony\Mobile\Api;
use Native\Symfony\Mobile\Bridge\FakeBridge;
use PHPUnit\Framework\TestCase;

final class ApiTest extends TestCase
{
    public function testSecureStorageRoundTrip(): void
    {
        $bridge = new FakeBridge();
        $bridge->willReturn('SecureStorage.Set', ['success' => true]);
        $bridge->willReturn('SecureStorage.Get', ['value' => 'tok_123']);
        $bridge->willReturn('SecureStorage.Delete', ['success' => true]);

        $storage = new Api\SecureStorage($bridge);

        self::assertTrue($storage->set('token', 'tok_123'));
        self::assertSame('tok_123', $storage->get('token'));
        self::assertTrue($storage->has('token'));
        self::assertTrue($storage->delete('token'));
    }

    public function testSecureStorageMissingKeyIsNullNotAnEmptyString(): void
    {
        $bridge = new FakeBridge();
        $bridge->willReturn('SecureStorage.Get', ['value' => null]);

        self::assertNull((new Api\SecureStorage($bridge))->get('nope'));
        self::assertFalse((new Api\SecureStorage($bridge))->has('nope'));
    }

    public function testAsynchronousMethodsReportPresentationNotOutcome(): void
    {
        // The distinction that matters most in this API: a true from prompt() means
        // the dialog was shown. Reading it as "the user authenticated" would be an
        // authentication bypass, which is why these live on dispatch().
        $bridge = new FakeBridge();

        self::assertTrue((new Api\Biometric($bridge))->prompt('Unlock'));
        self::assertTrue((new Api\Camera($bridge))->photo());

        self::assertSame(['Biometric.Prompt', 'Camera.GetPhoto'], $bridge->methods());
    }

    public function testCameraQualityIsClamped(): void
    {
        $bridge = new FakeBridge();
        $camera = new Api\Camera($bridge);

        $camera->photo(quality: 500);
        self::assertSame(100, $bridge->lastCall()['payload']['quality']);

        $camera->photo(quality: -20);
        self::assertSame(1, $bridge->lastCall()['payload']['quality']);
    }

    public function testCameraOmitsQualityWhenUnset(): void
    {
        $bridge = new FakeBridge();
        (new Api\Camera($bridge))->photo();

        self::assertArrayNotHasKey('quality', $bridge->lastCall()['payload']);
    }

    public function testNetworkAssumesConnectedWhenUnknown(): void
    {
        // Reporting "offline" on a missing answer would make an app refuse to try;
        // an attempt that fails is more useful than one never made.
        self::assertTrue((new Api\Network(new FakeBridge()))->isConnected());
    }

    public function testNetworkReportsAnExplicitDisconnection(): void
    {
        $bridge = new FakeBridge();
        $bridge->willReturn('Network.Status', ['connected' => false, 'type' => 'none']);

        $network = new Api\Network($bridge);

        self::assertFalse($network->isConnected());
        self::assertSame('none', $network->connectionType());
    }

    public function testSystemAppearanceDefaultsToLight(): void
    {
        self::assertSame('light', (new Api\System(new FakeBridge()))->appearance());
        self::assertFalse((new Api\System(new FakeBridge()))->isDarkMode());
    }

    public function testSystemAppearanceReadsDark(): void
    {
        $bridge = new FakeBridge();
        $bridge->willReturn('System.GetAppearance', ['appearance' => 'dark']);

        self::assertTrue((new Api\System($bridge))->isDarkMode());
    }

    public function testGeolocationDrainAcceptsEitherPayloadKey(): void
    {
        // The native side has been seen using both 'positions' and 'locations'.
        foreach (['positions', 'locations'] as $key) {
            $bridge = new FakeBridge();
            $bridge->willReturn('Geolocation.DrainWatchBuffer', [$key => [['lat' => 1.0, 'lng' => 2.0]]]);

            self::assertCount(1, (new Api\Geolocation($bridge))->drainWatchBuffer());
        }
    }

    public function testGeolocationDrainIsEmptyNotNullWhenNothingBuffered(): void
    {
        self::assertSame([], (new Api\Geolocation(new FakeBridge()))->drainWatchBuffer());
    }

    public function testWalletAmountsStayIntegerMinorUnits(): void
    {
        // A float amount here is how rounding bugs get into payments.
        $bridge = new FakeBridge();
        $bridge->willReturn('MobileWallet.CreatePaymentIntent', ['id' => 'pi_1']);

        (new Api\MobileWallet($bridge))->createPaymentIntent(1999, 'eur');

        self::assertSame(1999, $bridge->lastCall()['payload']['amount']);
        self::assertSame('EUR', $bridge->lastCall()['payload']['currency']);
    }

    public function testWalletPresentationIsNotAPaymentOutcome(): void
    {
        $bridge = new FakeBridge();
        $bridge->willReturn('MobileWallet.GetPaymentStatus', ['status' => 'requires_action']);

        $wallet = new Api\MobileWallet($bridge);

        self::assertTrue($wallet->presentPaymentSheet('pi_1'));
        self::assertSame('requires_action', $wallet->paymentStatus('pi_1')['status']);
    }

    public function testPushTokenIsNullBeforePermission(): void
    {
        $bridge = new FakeBridge();
        $bridge->willReturn('PushNotification.GetToken', ['token' => '']);

        self::assertNull((new Api\PushNotifications($bridge))->token());
    }

    public function testPushAuthorisationAcceptsEitherSpelling(): void
    {
        foreach (['granted', 'authorized'] as $key) {
            $bridge = new FakeBridge();
            $bridge->willReturn('PushNotification.CheckPermission', [$key => true]);

            self::assertTrue((new Api\PushNotifications($bridge))->isAuthorised());
        }
    }

    public function testDeviceVibrateSendsNoDurationBecauseNeitherHostHasOne(): void
    {
        // Android hardcodes createOneShot(200, DEFAULT_AMPLITUDE); iOS plays
        // kSystemSoundID_Vibrate, which has no length. The old signature took milliseconds
        // and sent them as `duration`, so the argument was accepted and dropped.
        $bridge = new FakeBridge();
        (new Api\Device($bridge))->vibrate();

        self::assertSame([], $bridge->lastCall()['payload']);
    }

    public function testTheInputSimulatorsSendWhatBothHostsActuallyRead(): void
    {
        // Both hosts require callback_id as a number and answer success: false without it,
        // so the previous `target` string meant every simulated interaction did nothing on
        // a device while returning true in PHP.
        $bridge = new FakeBridge();
        $perf = new Api\Performance($bridge);

        $perf->simulatePress(42);
        self::assertSame(['callback_id' => 42, 'node_id' => 0], $bridge->lastCall()['payload']);

        $perf->simulateTextChange(42, 'typed', 7);
        self::assertSame(['callback_id' => 42, 'node_id' => 7, 'text' => 'typed'], $bridge->lastCall()['payload']);

        $perf->simulateToggle(42, true);
        self::assertSame(['callback_id' => 42, 'node_id' => 0, 'value' => true], $bridge->lastCall()['payload']);
    }

    public function testFlashlightOmitsStateWhenToggling(): void
    {
        $bridge = new FakeBridge();
        (new Api\Device($bridge))->toggleFlashlight();

        self::assertSame([], $bridge->lastCall()['payload']);
    }

    public function testBrowserHasThreeDistinctModes(): void
    {
        $bridge = new FakeBridge();
        $browser = new Api\Browser($bridge);

        $browser->open('https://a.test');
        $browser->openInApp('https://b.test');
        $browser->openAuth('https://c.test', 'myapp');

        self::assertSame(['Browser.Open', 'Browser.OpenInApp', 'Browser.OpenAuth'], $bridge->methods());
        self::assertSame('myapp', $bridge->lastCall()['payload']['callbackScheme']);
    }

    public function testMicrophoneIsRecordingReadsStatus(): void
    {
        $bridge = new FakeBridge();
        $bridge->willReturn('Microphone.GetStatus', ['recording' => true]);

        self::assertTrue((new Api\Microphone($bridge))->isRecording());
    }

    public function testShareFileNamesThePathTheWayTheShareSheetReadsIt(): void
    {
        // Upstream's own wrapper (np-mobile src/Share.php) sends `filePath` and `message`
        // for a file — only a URL share is `url`/`title`/`text`. Sending `path` and `text`
        // meant the sheet opened with nothing attached while dispatch() returned true.
        $bridge = new FakeBridge();
        $share = new Api\Share($bridge);

        $share->file('/docs/invoice.pdf', 'Invoice', 'Here you go');
        self::assertSame(
            ['title' => 'Invoice', 'message' => 'Here you go', 'filePath' => '/docs/invoice.pdf'],
            $bridge->lastCall()['payload'],
        );

        $share->url('https://a.test', 'Link', 'Have a look');
        self::assertSame(['url', 'title', 'text'], array_keys($bridge->lastCall()['payload']));
    }
}
