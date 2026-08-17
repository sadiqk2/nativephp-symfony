<?php

declare(strict_types=1);

namespace App\EventListener;

use Native\Symfony\Desktop\Event\App\ApplicationBooted;
use Native\Symfony\Desktop\Event\ChildProcess\ErrorReceived;
use Native\Symfony\Desktop\Event\ChildProcess\MessageReceived;
use Native\Symfony\Desktop\Event\ChildProcess\ProcessExited;
use Native\Symfony\Desktop\Event\ChildProcess\ProcessSpawned;
use Native\Symfony\Desktop\Event\Settings\SettingChanged;
use Native\Symfony\Desktop\Event\Windows\WindowClosed;
use Native\Symfony\Desktop\Event\Windows\WindowFocused;
use Native\Symfony\Desktop\Event\Windows\WindowResized;
use Native\Symfony\Desktop\Event\Windows\WindowShown;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * The reverse channel, typed.
 *
 * The runtime pushes 44 events to PHP over HTTP, and the payoff of mapping their
 * names onto classes is right here: `$event->width` instead of `$payload[1]`, and a
 * listener that is found by the container rather than registered by hand.
 *
 * Every event also goes to every renderer, which is why the pages listen for the
 * same names in JavaScript. Same events, two audiences — PHP for anything that has
 * to touch state, the renderer for anything that is only chrome.
 */
final class RuntimeEventLogger
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    #[AsEventListener]
    public function onBooted(ApplicationBooted $event): void
    {
        $this->logger->info('runtime: application booted');
    }

    #[AsEventListener]
    public function onShown(WindowShown $event): void
    {
        $this->logger->info('runtime: window {id} shown', ['id' => $event->id]);
    }

    #[AsEventListener]
    public function onFocused(WindowFocused $event): void
    {
        $this->logger->info('runtime: window {id} focused', ['id' => $event->id]);
    }

    #[AsEventListener]
    public function onClosed(WindowClosed $event): void
    {
        $this->logger->info('runtime: window {id} closed', ['id' => $event->id]);
    }

    /**
     * Worth knowing: this fires for a user drag, not for our own
     * WindowManager::resize(). The runtime listens for Electron's `resized`, which
     * setSize() does not emit — so a programmatic resize is visible in window/get
     * and silent here.
     */
    #[AsEventListener]
    public function onResized(WindowResized $event): void
    {
        $this->logger->info('runtime: window {id} resized to {w}x{h}', [
            'id' => $event->id,
            'w' => $event->width,
            'h' => $event->height,
        ]);
    }

    /** Payload is positional here — [alias, pid] — unlike the other three. */
    #[AsEventListener]
    public function onSpawned(ProcessSpawned $event): void
    {
        $this->logger->info('runtime: process {alias} spawned as pid {pid}', [
            'alias' => $event->alias,
            'pid' => $event->pid,
        ]);
    }

    #[AsEventListener]
    public function onProcessOutput(MessageReceived $event): void
    {
        $this->logger->info('runtime: process {alias} said {data}', [
            'alias' => $event->alias,
            'data' => trim($event->data),
        ]);
    }

    #[AsEventListener]
    public function onProcessError(ErrorReceived $event): void
    {
        $this->logger->warning('runtime: process {alias} stderr {data}', [
            'alias' => $event->alias,
            'data' => trim($event->data),
        ]);
    }

    #[AsEventListener]
    public function onProcessExited(ProcessExited $event): void
    {
        $this->logger->info('runtime: process {alias} exited with {code}', [
            'alias' => $event->alias,
            'code' => $event->code,
        ]);
    }

    /**
     * Logged, never written to. Every settings write emits this — including the
     * writes this app makes itself — so a listener that saved something here would
     * loop forever.
     */
    #[AsEventListener]
    public function onSettingChanged(SettingChanged $event): void
    {
        $this->logger->info('runtime: setting {key} changed', ['key' => $event->key]);
    }
}
