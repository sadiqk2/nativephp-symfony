<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Tests;

use Native\Symfony\Desktop\Menu\Menu;
use Native\Symfony\Desktop\MenuBar\MenuBarManager;
use Native\Symfony\Desktop\Window\UrlResolver;
use Symfony\Component\HttpFoundation\RequestStack;
use PHPUnit\Framework\TestCase;

/**
 * `menu-bar/create` is the second builder in this bundle whose payload the runtime
 * reads without guards, after `window/open`.
 *
 * Three of its keys have no default on the runtime side. `label` and `tooltip` go
 * straight into `Tray::setTitle()` / `Tray::setToolTip()`, which take a required
 * string and refuse anything else; `url` becomes the popover window's `index`, and
 * the vendored menubar library substitutes `file://<appPath>/index.html` for it,
 * which does not exist in a NativePHP build. Upstream's `MenuBar` declares all
 * three (`string $label = ''`, `string $tooltip = ''`, `url('/')` in the
 * constructor) and serialises them on every create, which is why it never sees any
 * of this.
 */
final class MenuBarTest extends TestCase
{
    public function testTrayOnlyCreateAlwaysSendsALabelAndTooltip(): void
    {
        // menuBar.ts:110-111 calls tray.setToolTip(tooltip) then tray.setTitle(label)
        // on a bare Tray, with no guard. Electron's Tray takes a required string for
        // both, so an absent key throws there — after res.sendStatus(200) has already
        // gone out, and before `state.tray = tray`. The tray is left with no click
        // listeners, MenuBarCreated never fires, and every later menu-bar/* endpoint
        // is a `state.tray?.` no-op: an icon that does nothing, reported as success.
        [$manager, $client] = $this->manager();

        $manager->create()->onlyShowContextMenu()->contextMenu(Menu::new()->role('quit'))->create();

        self::assertSame('', $client->lastCall()['data']['label']);
        self::assertSame('', $client->lastCall()['data']['tooltip']);
    }

    public function testPopoverCreateAlwaysSendsALabel(): void
    {
        // The other branch reaches setTitle too, at menuBar.ts:161, inside the
        // library's 'ready' handler — so the throw also skips the 'hide' and 'show'
        // listeners registered right after it, and MenuBarHidden / MenuBarShown
        // never fire for the life of the app.
        [$manager, $client] = $this->manager();

        $manager->create()->url('/tray')->create();

        self::assertSame('', $client->lastCall()['data']['label']);
    }

    public function testCreateSendsTheAppRootWhenNoUrlIsSet(): void
    {
        // Without `url` the menubar library loads file://<app.getAppPath()>/index.html
        // and the popover comes up blank. PendingWindow::open() already defaults to
        // '/' for exactly this reason; this builder did not.
        [$manager, $client] = $this->manager();

        $manager->create()->create();

        self::assertSame('http://localhost/', $client->lastCall()['data']['url']);
    }

    public function testExplicitValuesStillWin(): void
    {
        [$manager, $client] = $this->manager();

        $manager->create()->label('7 open')->tooltip('My App')->url('/tray')->create();

        self::assertSame('7 open', $client->lastCall()['data']['label']);
        self::assertSame('My App', $client->lastCall()['data']['tooltip']);
        self::assertSame('http://localhost/tray', $client->lastCall()['data']['url']);
    }

    public function testTrayOnlyCreateNeedsNoUrlOutsideARequest(): void
    {
        // The runtime destructures `url` but never reads it in the tray-only branch,
        // so there is nothing to default — and defaulting it anyway would make a
        // console-time tray icon throw, since UrlResolver cannot invent the dev
        // server's port without a request or a configured base_url.
        $client = new FakeClient();
        $manager = new MenuBarManager($client, new UrlResolver(new RequestStack()));

        $manager->create()->onlyShowContextMenu()->create();

        self::assertArrayNotHasKey('url', $client->lastCall()['data']);
    }

    /** @return array{MenuBarManager, FakeClient} */
    private function manager(): array
    {
        $client = new FakeClient();

        return [new MenuBarManager($client, new UrlResolver(new RequestStack(), 'http://localhost')), $client];
    }
}
