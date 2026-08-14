<?php

declare(strict_types=1);

namespace App;

use Native\Symfony\Contract\AppBootstrapper;
use Native\Symfony\Enums\MenuRole;
use Native\Symfony\Menu\Menu;
use Native\Symfony\Menu\MenuManager;
use Native\Symfony\Shortcut\GlobalShortcutManager;
use Native\Symfony\Window\WindowManager;

/**
 * The whole app-startup contract, in one class.
 *
 * The runtime POSTs /_native/api/booted at the end of its boot; the bundle turns
 * that into a call to this method. There is no other startup hook, and there does
 * not need to be — whatever should exist when the app appears is created here.
 *
 * Autowired: implementing the interface is the registration. The bundle
 * autoconfigures and aliases it.
 */
final class Bootstrapper implements AppBootstrapper
{
    public function __construct(
        private readonly WindowManager $windows,
        private readonly MenuManager $menus,
        private readonly GlobalShortcutManager $shortcuts,
    ) {
    }

    public function boot(): void
    {
        // window/open is idempotent by id: an id that already exists is shown and
        // focused rather than duplicated. That matters because the runtime POSTs
        // /booted again on macOS `activate`, so this method runs more than once in
        // a normal session and must be safe to repeat.
        $this->windows->open('main')
            ->url('/')
            ->size(1180, 800)
            ->minSize(900, 600)
            ->title('Deskpad')
            ->rememberState()
            // Absent and false are different here: dev mode opens the devtools
            // unless something says otherwise, and a demo screenshot with a
            // devtools pane in it is a worse demo.
            ->showDevTools(false)
            ->open();

        $this->menus->set($this->applicationMenu());

        // Right-click anywhere in a window. Same event mechanism as the menu bar.
        $this->menus->context(
            Menu::new()
                ->label('New note', event: Event\MenuAction::NewNote->value)
                ->label('Copy latest note', event: Event\MenuAction::CopyLatest->value)
                ->separator()
                ->role(MenuRole::Reload),
        );

        // A global shortcut fires even when the app is not focused. It comes back
        // as a caller-named event, exactly like a menu item, so one listener can
        // serve both.
        $this->shortcuts->register('CommandOrControl+Shift+N', Event\MenuAction::NewNote->value);
    }

    /**
     * Every item that *does* something carries an `event`, and
     * {@see EventListener\MenuActionListener} is where the doing happens — so the
     * menu is a list of intentions and the behaviour lives in one place, rather
     * than being spread across closures the runtime cannot serialise anyway.
     */
    private function applicationMenu(): Menu
    {
        return Menu::new()
            ->submenu('Deskpad', Menu::new()
                ->label('About Deskpad', event: Event\MenuAction::About->value)
                ->separator()
                // Roles are Electron's built-ins: the runtime maps them, and they
                // work without a round trip to PHP. Only cross-platform roles are
                // used here — Electron's macOS-only ones (hide, zoom, front,
                // services…) are accepted on Linux but do nothing, and a menu
                // full of dead items is a worse demo than a short one.
                ->role(MenuRole::Quit))
            ->submenu('Notes', Menu::new()
                ->label('New note', event: Event\MenuAction::NewNote->value, accelerator: 'CommandOrControl+N')
                ->label('Copy latest to clipboard', event: Event\MenuAction::CopyLatest->value, accelerator: 'CommandOrControl+Shift+C')
                ->separator()
                ->label('Seed three notes', event: Event\MenuAction::Seed->value))
            ->submenu('Window', Menu::new()
                ->label('Open inspector', event: Event\MenuAction::OpenInspector->value, accelerator: 'CommandOrControl+I')
                ->label('Show the mobile screens', event: Event\MenuAction::MobileScreens->value)
                ->separator()
                ->role(MenuRole::Minimize)
                ->role(MenuRole::ToggleFullScreen))
            ->submenu('Edit', Menu::new()
                ->role(MenuRole::Undo)
                ->role(MenuRole::Redo)
                ->separator()
                ->role(MenuRole::Cut)
                ->role(MenuRole::Copy)
                ->role(MenuRole::Paste)
                ->role(MenuRole::SelectAll))
            ->submenu('Help', Menu::new()
                // openInBrowser sends this to the user's browser instead of
                // navigating the app window, which is what you almost always want
                // for an external link.
                ->link('NativePHP', 'https://nativephp.com', openInBrowser: true)
                ->link('Symfony', 'https://symfony.com', openInBrowser: true));
    }
}
