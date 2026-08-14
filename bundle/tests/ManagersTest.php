<?php

declare(strict_types=1);

namespace Native\Symfony\Tests;

use Native\Symfony\Clipboard\ClipboardManager;
use Native\Symfony\Dock\DockManager;
use Native\Symfony\Enums\ClipboardType;
use Native\Symfony\Enums\SystemTheme;
use Native\Symfony\Menu\Menu;
use Native\Symfony\Notification\NotificationManager;
use Native\Symfony\PowerMonitor\PowerMonitorManager;
use Native\Symfony\Screen\ScreenManager;
use Native\Symfony\Settings\SettingsManager;
use Native\Symfony\Shell\ShellManager;
use Native\Symfony\Shortcut\GlobalShortcutManager;
use Native\Symfony\Support\Platform;
use Native\Symfony\System\ProgressBar;
use Native\Symfony\System\SystemManager;
use PHPUnit\Framework\TestCase;

final class ManagersTest extends TestCase
{
    public function testNotificationReturnsTheReferenceForCorrelation(): void
    {
        $client = new FakeClient();
        $client->willReturn('notification', ['reference' => '123.abc']);

        $reference = (new NotificationManager($client))->send('Done', 'Import finished');

        self::assertSame('123.abc', $reference);
        self::assertSame(['title' => 'Done', 'body' => 'Import finished'], $client->lastCall()['data']);
    }

    public function testNotificationActionsUseElectronsButtonShape(): void
    {
        $client = new FakeClient();

        (new NotificationManager($client))->create()
            ->title('Deploy')
            ->actions(['Approve', 'Reject'])
            ->withReply('Say something')
            ->show();

        $data = $client->lastCall()['data'];

        self::assertSame([
            ['type' => 'button', 'text' => 'Approve'],
            ['type' => 'button', 'text' => 'Reject'],
        ], $data['actions']);
        self::assertTrue($data['hasReply']);
        self::assertSame('Say something', $data['replyPlaceholder']);
    }

    public function testDockIsAnoOpOffMac(): void
    {
        // app.dock is undefined on Windows and Linux — the request throws there
        // rather than no-oping, so the guard has to be on this side.
        $client = new FakeClient();
        $dock = new DockManager($client, new Platform('Linux'));

        $dock->show();
        $dock->setBadge('3');
        $dock->menu(Menu::new()->label('X'));

        self::assertSame([], $client->calls);
        self::assertSame('', $dock->badge());
    }

    public function testDockWorksOnMac(): void
    {
        $client = new FakeClient();
        (new DockManager($client, new Platform('Darwin')))->setBadge('3');

        self::assertSame('dock/badge', $client->lastCall()['endpoint']);
    }

    public function testProgressBarClearsWithMinusOne(): void
    {
        // Electron's convention for "no bar" is -1, not 0 — 0 shows an empty bar.
        $client = new FakeClient();
        (new ProgressBar($client))->clear();

        self::assertSame(-1, $client->lastCall()['data']['percent']);
    }

    public function testClipboardSelectsTheBufferByQueryString(): void
    {
        $client = new FakeClient();
        $clipboard = new ClipboardManager($client);

        $clipboard->setText('hi', ClipboardType::Selection);

        self::assertSame('clipboard/text?type=selection', $client->lastCall()['endpoint']);
    }

    public function testClipboardImageIsNullWhenEmpty(): void
    {
        $client = new FakeClient();
        $client->willReturn('clipboard/image', ['image' => null]);

        self::assertNull((new ClipboardManager($client))->image());
    }

    public function testScreenCursorPositionIsUnwrapped(): void
    {
        // One of only two endpoints that return a bare object with no envelope.
        $client = new FakeClient();
        $client->willReturn('screen/cursor-position', ['x' => 42, 'y' => 99]);

        self::assertSame(['x' => 42, 'y' => 99], (new ScreenManager($client))->cursorPosition());
    }

    public function testSettingsUseThePrefixedEndpoint(): void
    {
        $client = new FakeClient();
        $settings = new SettingsManager($client);

        $settings->get('theme');
        self::assertSame('settings/theme', $client->lastCall()['endpoint']);

        $settings->set('theme', 'dark');
        self::assertSame('settings/theme', $client->lastCall()['endpoint']);

        $settings->forget('theme');
        self::assertSame('DELETE', $client->lastCall()['method']);
    }

    public function testSettingsFallBackToTheDefault(): void
    {
        $client = new FakeClient();
        $client->willReturn('settings/missing', ['value' => null]);

        self::assertSame('fallback', (new SettingsManager($client))->get('missing', 'fallback'));
    }

    public function testShellOpenPathReturnsTheOsErrorString(): void
    {
        // The runtime answers 200 whether or not it worked; the string is the
        // only signal.
        $client = new FakeClient();
        $client->willReturn('shell/open-item', ['result' => 'No application found']);

        self::assertSame('No application found', (new ShellManager($client))->openPath('/nope'));
    }

    public function testSystemThemeFallsBackForAnUnknownValue(): void
    {
        $client = new FakeClient();
        $client->willReturn('system/theme', ['result' => 'sepia']);

        self::assertSame(SystemTheme::System, (new SystemManager($client))->theme());
    }

    public function testPrintToPdfDecodesBase64(): void
    {
        $client = new FakeClient();
        $client->willReturn('system/print-to-pdf', ['result' => base64_encode('%PDF-1.7')]);

        self::assertSame('%PDF-1.7', (new SystemManager($client))->printToPdf('<p>hi</p>'));
    }

    public function testPrintToPdfIsNullOnFailure(): void
    {
        $client = new FakeClient();
        $client->willReturn('system/print-to-pdf', ['error' => 'boom'], 400);

        self::assertNull((new SystemManager($client))->printToPdf('<p>hi</p>'));
    }

    public function testEncryptReturnsNullWhenUnavailable(): void
    {
        $client = new FakeClient();
        $client->willReturn('system/encrypt', ['error' => 'not available'], 400);

        self::assertNull((new SystemManager($client))->encrypt('secret'));
    }

    public function testPowerMonitorMapsEnumsAndFallsBack(): void
    {
        $client = new FakeClient();
        $client->willReturn('power-monitor/get-system-idle-state', ['result' => 'idle']);
        $client->willReturn('power-monitor/get-current-thermal-state', ['result' => 'nonsense']);
        $monitor = new PowerMonitorManager($client);

        self::assertSame('idle', $monitor->idleState()->value);
        self::assertSame('unknown', $monitor->thermalState()->value);
    }

    public function testShortcutRegistrationIsConfirmedSeparately(): void
    {
        // register() always answers 200 even when another app owns the accelerator,
        // so the only way to know is to ask.
        $client = new FakeClient();
        $client->willReturn('global-shortcuts/CommandOrControl%2BK', ['isRegistered' => false]);

        $granted = (new GlobalShortcutManager($client))
            ->registerChecked('CommandOrControl+K', 'App\\Shortcut\\Palette');

        self::assertFalse($granted);
    }

    public function testShortcutUnregisterSendsABodyOnDelete(): void
    {
        $client = new FakeClient();
        (new GlobalShortcutManager($client))->unregister('CommandOrControl+K');

        self::assertSame('DELETE', $client->lastCall()['method']);
        self::assertSame(['key' => 'CommandOrControl+K'], $client->lastCall()['data']);
    }
}
