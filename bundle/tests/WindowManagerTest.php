<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Tests;

use Native\Symfony\Desktop\Window\UrlResolver;
use Native\Symfony\Desktop\Window\WindowManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class WindowManagerTest extends TestCase
{
    public function testOpenSendsAnAbsoluteUrlAndDefaultSize(): void
    {
        [$manager, $client] = $this->manager($this->requestFor('http://127.0.0.1:8100/'));

        $manager->open('main')->url('/dashboard')->open();

        $call = $client->lastCall();
        self::assertSame('window/open', $call['endpoint']);
        self::assertSame('http://127.0.0.1:8100/dashboard', $call['data']['url']);
        self::assertSame(1000, $call['data']['width']);
        self::assertSame(700, $call['data']['height']);
    }

    public function testOpenAlwaysSendsAZoomFactor(): void
    {
        // The runtime calls setZoomFactor(parseFloat(zoomFactor)) on dom-ready
        // with no guard: omitting it yields NaN and the page renders at an
        // absurd zoom. Observed, then traced.
        [$manager, $client] = $this->manager($this->requestFor('http://127.0.0.1:8100/'));

        $manager->open('main')->open();

        self::assertSame(1.0, $client->lastCall()['data']['zoomFactor']);
    }

    public function testAnExplicitZoomFactorWins(): void
    {
        [$manager, $client] = $this->manager($this->requestFor('http://127.0.0.1:8100/'));

        $manager->open('main')->zoomFactor(1.25)->open();

        self::assertSame(1.25, $client->lastCall()['data']['zoomFactor']);
    }

    public function testPendingWindowOmitsKeysThatWereNeverSet(): void
    {
        [$manager] = $this->manager();

        $payload = $manager->open('main')->size(400, 300)->payload();

        // The runtime distinguishes absent from false for showDevTools: absent
        // lets dev mode open them, false suppresses them. Sending nulls for
        // everything unset would change behaviour.
        self::assertArrayNotHasKey('showDevTools', $payload);
        self::assertArrayNotHasKey('vibrancy', $payload);
        self::assertSame(['id' => 'main', 'width' => 400, 'height' => 300], $payload);
    }

    public function testShowDevToolsFalseIsSentExplicitly(): void
    {
        [$manager] = $this->manager();

        self::assertFalse($manager->open('main')->showDevTools(false)->payload()['showDevTools']);
    }

    public function testResizeUsesTheExplicitIdWhenGiven(): void
    {
        [$manager, $client] = $this->manager();

        $manager->resize(800, 600, 'settings');

        self::assertSame(
            ['id' => 'settings', 'width' => 800, 'height' => 600],
            $client->lastCall()['data'],
        );
    }

    public function testWindowIdComesFromTheRefererBeforeTheCurrentUrl(): void
    {
        // A form POST carries the window id in its Referer, not its own URL, so
        // the Referer has to win. Both are present here and differ on purpose.
        $request = Request::create('http://127.0.0.1:8100/save?_windowId=wrong');
        $request->headers->set('Referer', 'http://127.0.0.1:8100/form?_windowId=settings');

        [$manager] = $this->manager($request);

        self::assertSame('settings', $manager->detectId());
    }

    public function testWindowIdFallsBackToTheCurrentUrl(): void
    {
        [$manager] = $this->manager($this->requestFor('http://127.0.0.1:8100/page?_windowId=popup'));

        self::assertSame('popup', $manager->detectId());
    }

    public function testWindowIdIsNullWhenAbsent(): void
    {
        [$manager] = $this->manager($this->requestFor('http://127.0.0.1:8100/page'));

        self::assertNull($manager->detectId());
    }

    public function testMutationsFallBackToMainWithoutARequest(): void
    {
        [$manager, $client] = $this->manager();

        $manager->minimize();

        self::assertSame(['id' => 'main'], $client->lastCall()['data']);
    }

    public function testGetReturnsNullOn404(): void
    {
        [$manager, $client] = $this->manager();
        $client->willReturnStatus('window/get/ghost', 404);

        self::assertNull($manager->get('ghost'));
    }

    public function testGetHydratesTheWindow(): void
    {
        [$manager, $client] = $this->manager();
        $client->willReturn('window/get/main', [
            'id' => 'main', 'x' => 10, 'y' => 20, 'width' => 760, 'height' => 520,
            'title' => 'App', 'url' => 'http://127.0.0.1:8100/?_windowId=main',
            'focused' => true, 'resizable' => true,
        ]);

        $window = $manager->get('main');

        self::assertNotNull($window);
        self::assertSame(760, $window->width);
        self::assertSame(520, $window->height);
        self::assertTrue($window->focused);
        // Fields the runtime omitted must not be nulls in a typed object.
        self::assertFalse($window->kiosk);
    }

    public function testCurrentReturnsNullWhenTheRuntimeFails(): void
    {
        // window/current dereferences getFocusedWindow() with no null guard, so
        // it throws on the runtime side whenever the app is backgrounded.
        [$manager, $client] = $this->manager();
        $client->willReturnStatus('window/current', 500);

        self::assertNull($manager->current());
    }

    public function testAllSkipsNonArrayEntries(): void
    {
        [$manager, $client] = $this->manager();
        $client->willReturn('window/all', [
            ['id' => 'main', 'width' => 100, 'height' => 100],
            'unexpected',
        ]);

        $windows = $manager->all();

        self::assertCount(1, $windows);
        self::assertSame('main', $windows[0]->id);
    }

    /** @return array{0: WindowManager, 1: FakeClient} */
    private function manager(?Request $request = null): array
    {
        $stack = new RequestStack();

        if (null !== $request) {
            $stack->push($request);
        }

        $client = new FakeClient();

        return [
            new WindowManager($client, new UrlResolver($stack, 'http://127.0.0.1:8100'), $stack),
            $client,
        ];
    }

    private function requestFor(string $uri): Request
    {
        return Request::create($uri);
    }
}
