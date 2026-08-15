<?php

declare(strict_types=1);

namespace Native\Symfony\Tests;

use Native\Symfony\Enums\MenuRole;
use Native\Symfony\Menu\Items\Checkbox;
use Native\Symfony\Menu\Items\Label;
use Native\Symfony\Menu\Items\Link;
use Native\Symfony\Menu\Items\Role;
use Native\Symfony\Menu\Menu;
use Native\Symfony\Menu\MenuManager;
use Native\Symfony\Window\UrlResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use PHPUnit\Framework\TestCase;

final class MenuTest extends TestCase
{
    public function testTemplateMatchesWhatCompileMenuExpects(): void
    {
        $menu = Menu::new()
            ->submenu(
                'File',
                Menu::new()
                    ->label('New', id: 'file.new', accelerator: 'CommandOrControl+N')
                    ->separator()
                    ->link('Docs', 'https://example.test', openInBrowser: true)
                    ->role(MenuRole::Quit),
            );

        self::assertSame([[
            'label' => 'File',
            'submenu' => [
                ['label' => 'New', 'id' => 'file.new', 'accelerator' => 'CommandOrControl+N'],
                ['type' => 'separator'],
                ['type' => 'link', 'label' => 'Docs', 'url' => 'https://example.test', 'openInBrowser' => true],
                ['type' => 'role', 'role' => 'quit'],
            ],
        ]], $menu->toArray());
    }

    public function testMenuIsImmutable(): void
    {
        $base = Menu::new()->label('One');
        $branch = $base->label('Two');

        self::assertCount(1, $base->toArray());
        self::assertCount(2, $branch->toArray());
    }

    public function testRoleItemsDropEverythingButRoleAndLabel(): void
    {
        // compileMenu reduces a role item to {role, label?} — an id or event on one
        // is silently discarded, so it can never report a click.
        self::assertSame(
            ['type' => 'role', 'role' => 'copy', 'label' => 'Copy that'],
            (new Role(MenuRole::Copy, 'Copy that'))->toArray(),
        );
    }

    public function testLabelOmitsDefaultsButKeepsExplicitFalses(): void
    {
        self::assertSame(['label' => 'Plain'], (new Label('Plain'))->toArray());
        self::assertSame(
            ['label' => 'Off', 'enabled' => false],
            (new Label('Off', enabled: false))->toArray(),
        );
    }

    public function testCheckboxAlwaysSendsCheckedIncludingFalse(): void
    {
        // The runtime flips this value on click and reports the new state; omitting
        // it would leave Electron to guess the initial one.
        self::assertSame(
            ['type' => 'checkbox', 'label' => 'Wrap', 'checked' => false],
            (new Checkbox('Wrap'))->toArray(),
        );
    }

    public function testLinkOmitsOpenInBrowserWhenFalse(): void
    {
        self::assertSame(
            ['type' => 'link', 'label' => 'Home', 'url' => '/'],
            (new Link('Home', '/'))->toArray(),
        );
    }

    public function testEventNamesRideAlongOnItems(): void
    {
        // An item carrying `event` comes back as a NativeEvent under that name,
        // which is how a listener subscribes to one action instead of filtering.
        $items = Menu::new()->label('Save', event: 'App\\Menu\\SaveRequested')->toArray();

        self::assertSame('App\Menu\SaveRequested', $items[0]['event']);
    }

    public function testManagerSendsApplicationAndContextMenus(): void
    {
        $client = new FakeClient();
        $manager = new MenuManager($client);
        $menu = Menu::new()->label('Item');

        $manager->set($menu);
        self::assertSame('menu', $client->lastCall()['endpoint']);
        self::assertSame(['items' => [['label' => 'Item']]], $client->lastCall()['data']);

        $manager->context($menu);
        self::assertSame('context', $client->lastCall()['endpoint']);
        self::assertArrayHasKey('entries', $client->lastCall()['data']);

        $manager->removeContext();
        self::assertSame('DELETE', $client->lastCall()['method']);
    }

    public function testLinkUrlsAreAbsolutisedBeforeTheyReachTheRuntime(): void
    {
        // A link's URL ends up in the runtime's goToUrl -> loadURL(), which rejects
        // a relative path with ERR_INVALID_URL. The menu item then does nothing at
        // all while its event still fires, so the failure looks like a broken
        // handler. WindowManager and PendingMenuBar both resolved already; this was
        // the one path that did not.
        $request = Request::create('http://127.0.0.1:8100/dashboard');
        $stack = new RequestStack();
        $stack->push($request);

        $client = new FakeClient();
        $manager = new MenuManager($client, new UrlResolver($stack, null));

        $manager->set(Menu::new()->submenu('File', Menu::new()->link('Settings', '/settings')));

        /** @var list<array<string, mixed>> $items */
        $items = $client->lastCall()['data']['items'];

        self::assertSame('http://127.0.0.1:8100/settings', $items[0]['submenu'][0]['url']);
    }
}
