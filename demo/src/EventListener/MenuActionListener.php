<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Event\MenuAction;
use App\Event\NoteSaved;
use App\Note\NoteStore;
use Native\Symfony\Desktop\Clipboard\ClipboardManager;
use Native\Symfony\Desktop\Event\NativeEvent;
use Native\Symfony\Desktop\Notification\NotificationManager;
use Native\Symfony\Desktop\Window\WindowManager;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * What the menu bar, the context menu and the global shortcut actually do.
 *
 * Menu items carry a *name*, not a callback — the item lives in Electron's process
 * and there is nothing to serialise a closure into — so clicking one produces a
 * caller-named event, and this is the other end of it.
 *
 * These handlers run inside an HTTP request the runtime makes to PHP, which is why
 * they can call straight back into the runtime (open a window, write the clipboard)
 * as if they were a controller. That round trip is the entire mechanism: menu →
 * runtime → POST /_native/api/events → listener → runtime.
 */
final class MenuActionListener
{
    public function __construct(
        private readonly WindowManager $windows,
        private readonly ClipboardManager $clipboard,
        private readonly NotificationManager $notifications,
        private readonly NoteStore $notes,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * One listener for all of them.
     *
     * The bundle dispatches caller-named events twice: once under
     * `native.{name}` for a listener that wants exactly one action, and once under
     * NativeEvent::class for a catch-all. Subscribing to *one* of those is
     * important — registering for both would run this handler twice per click.
     */
    #[AsEventListener]
    public function onNativeEvent(NativeEvent $event): void
    {
        $action = MenuAction::tryFrom($event->name);

        if (null === $action) {
            // Not ours. Worth logging rather than ignoring: while building a menu,
            // "nothing happened" is otherwise indistinguishable from a typo in the
            // event name.
            $this->logger->info('unhandled caller-named event {name}', ['name' => $event->name]);

            return;
        }

        match ($action) {
            MenuAction::About => $this->about(),
            MenuAction::NewNote => $this->newNote(),
            MenuAction::CopyLatest => $this->copyLatest(),
            MenuAction::Seed => $this->seed(),
            MenuAction::OpenInspector => $this->openInspector(),
            MenuAction::MobileScreens => $this->windows->navigate('/mobile/profile', 'main'),
        };
    }

    private function about(): void
    {
        // Deliberately a notification and not dialogs->alert(): every dialog blocks
        // the runtime's event loop until a human answers it, and a menu handler is
        // running inside a request the runtime is waiting on. Alerts belong on a
        // user action in a window, where blocking is what the user asked for.
        $this->notifications->create()
            ->title('Deskpad')
            ->body('A demo app for native-symfony/desktop-bundle')
            ->show();
    }

    private function newNote(): void
    {
        // The menu can drive navigation because a window is addressable by id.
        // The runtime appends ?_windowId=main on its way there, which is what makes
        // WindowManager::detectId() work on the page that lands.
        $this->windows->navigate('/notes?new=1', 'main');
        $this->windows->show('main');
    }

    private function copyLatest(): void
    {
        $note = $this->notes->latest();

        if (null === $note) {
            $this->notifications->send('Nothing to copy', 'Deskpad has no notes yet.');

            return;
        }

        $this->clipboard->setText($note->asText());
        $this->notifications->send('Copied', $note->title);
    }

    private function seed(): void
    {
        foreach ([
            ['Shipping list', "electron-vite dev\nnative:build linux x64 --dir"],
            ['Windows are addressable', 'Every mutation takes an optional id and falls back to _windowId.'],
            ['Dots are object paths', 'settings.set("a.b") stores {"a":{"b":…}} and SettingChanged reports "a".'],
        ] as [$title, $body]) {
            $note = $this->notes->add($title, $body);
            // Dispatched, not broadcast by hand: the decorated dispatcher forwards it
            // to every renderer because the class implements BroadcastsToRuntime.
            $this->dispatcher->dispatch(new NoteSaved($note->title, $this->notes->count()));
        }

        $this->windows->navigate('/notes', 'main');
    }

    private function openInspector(): void
    {
        $this->windows->open('inspector')
            ->url('/inspector')
            ->size(520, 620)
            // Offset so it does not land exactly on top of the main window: the
            // runtime centres a window with no position, and two centred windows look
            // like one.
            ->position(870, 170)
            ->title('Deskpad — Inspector')
            ->showDevTools(false)
            ->open();
    }
}
