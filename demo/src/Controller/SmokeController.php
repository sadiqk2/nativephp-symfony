<?php

declare(strict_types=1);

namespace App\Controller;

use App\Event\NoteSaved;
use App\Note\NoteStore;
use Native\Symfony\Desktop\Clipboard\ClipboardManager;
use Native\Symfony\Desktop\Contract\ClientInterface;
use Native\Symfony\Desktop\Notification\NotificationManager;
use Native\Symfony\Desktop\PowerMonitor\PowerMonitorManager;
use Native\Symfony\Desktop\Process\ChildProcessManager;
use Native\Symfony\Desktop\Settings\SettingsManager;
use Native\Symfony\Desktop\Shortcut\GlobalShortcutManager;
use Native\Symfony\Desktop\System\RuntimeInfo;
use Native\Symfony\Desktop\System\SystemManager;
use Native\Symfony\Desktop\Window\WindowManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * The demo's integration test, run against the live runtime.
 *
 * Every check is a real round trip and reports what came back rather than whether
 * it threw, so `run.sh` can assert on values: the clipboard read has to equal
 * the marker that was written, the setting has to survive a write and vanish after a
 * forget, the window has to report the size it was resized to.
 *
 * Nothing here blocks. All four dialog calls hold the runtime's main thread until a
 * human answers, so an unattended run that touched one would hang rather than fail —
 * they are exercised from the UI instead, and the README says so.
 */
final class SmokeController
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly WindowManager $windows,
        private readonly ClipboardManager $clipboard,
        private readonly SettingsManager $settings,
        private readonly NotificationManager $notifications,
        private readonly ChildProcessManager $processes,
        private readonly SystemManager $system,
        private readonly PowerMonitorManager $power,
        private readonly GlobalShortcutManager $shortcuts,
        private readonly RuntimeInfo $runtime,
        private readonly NoteStore $notes,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    #[Route('/_smoke', name: 'smoke', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        if (!$this->client->isAvailable()) {
            // Ten identical "NATIVEPHP_API_URL is not set" errors say the same thing
            // once, badly. Outside the runtime this endpoint has nothing to report.
            return new JsonResponse([
                'runtime' => 'unavailable — this endpoint only means anything inside the app',
            ]);
        }

        $results = [];

        foreach ($this->checks() as $name => $check) {
            try {
                $results[$name] = $check();
            } catch (\Throwable $e) {
                // Reported, not thrown: one broken endpoint should not hide the
                // answers from the other nine.
                $results[$name] = ['error' => $e::class.': '.$e->getMessage()];
            }
        }

        return new JsonResponse($results, json: false);
    }

    /** @return iterable<string, callable(): array<string, mixed>> */
    private function checks(): iterable
    {
        yield 'runtime' => fn (): array => $this->runtime->get();

        yield 'windows' => function (): array {
            $this->windows->resize(1180, 800, 'main');

            // Read back through the runtime. A programmatic resize emits no
            // WindowResized event — the runtime listens for Electron's `resized`,
            // which fires on user drags only — so window/get is the only witness.
            $main = $this->windows->get('main');

            return [
                'ids' => array_map(static fn ($w): string => $w->id, $this->windows->all()),
                'main_size' => null === $main ? null : $main->width.'x'.$main->height,
                'main_title' => $main?->title,
            ];
        };

        yield 'clipboard' => function (): array {
            $marker = 'deskpad-'.bin2hex(random_bytes(4));
            $this->clipboard->setText($marker);

            return ['wrote' => $marker, 'read' => $this->clipboard->text()];
        };

        yield 'settings' => function (): array {
            // A colon, not a dot: dots are object paths in electron-store, so
            // `smoke.probe` would be stored nested and the change event would name
            // the root key instead of this one.
            $this->settings->set('deskpad:smoke', 'probe');
            $read = $this->settings->get('deskpad:smoke');
            $this->settings->forget('deskpad:smoke');

            return [
                'read' => $read,
                'after_forget' => $this->settings->get('deskpad:smoke', 'gone'),
            ];
        };

        yield 'notes' => function (): array {
            $note = $this->notes->add('Written by the smoke run', 'Persisted in the runtime settings store.');
            $this->dispatcher->dispatch(new NoteSaved($note->title, $this->notes->count()));

            return ['saved' => $note->title, 'total' => $this->notes->count()];
        };

        yield 'notification' => fn (): array => [
            'reference' => $this->notifications->send('Deskpad', 'Sent from the smoke run'),
        ];

        yield 'child_process' => function (): array {
            $handle = $this->processes->php('smoke', ['-r', 'echo "hello from a child\n";']);

            return ['alias' => $handle->alias, 'pid_on_start' => $handle->pid];
        };

        yield 'system' => fn (): array => [
            'theme' => $this->system->theme()->value,
            'can_encrypt' => $this->system->canEncrypt(),
        ];

        yield 'power' => fn (): array => [
            'idle_state' => $this->power->idleState()->value,
            'on_battery' => $this->power->onBatteryPower(),
        ];

        yield 'shortcut' => fn (): array => [
            // Registered in Bootstrapper::boot(). If this is false the boot hook did
            // not run, which is the failure this whole file exists to catch.
            'registered' => $this->shortcuts->isRegistered('CommandOrControl+Shift+N'),
        ];
    }
}
