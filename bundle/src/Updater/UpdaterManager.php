<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Updater;

use Native\Symfony\Desktop\Contract\ClientInterface;

/**
 * electron-updater control — 3 endpoints, plus 7 events.
 *
 * All three are fire-and-forget: progress and outcomes arrive as events, never as
 * responses. The updater only functions in a packaged build, and only when
 * `native_desktop.updater.enabled` is true — the runtime reads that at boot from
 * native:config and does not check again.
 */
final class UpdaterManager
{
    public function __construct(private readonly ClientInterface $client)
    {
    }

    /** Emits CheckingForUpdate, then UpdateAvailable or UpdateNotAvailable. */
    public function checkForUpdates(): void
    {
        $this->client->post('auto-updater/check-for-updates');
    }

    /** Emits DownloadProgress repeatedly, then UpdateDownloaded — or Error. */
    public function downloadUpdate(): void
    {
        $this->client->post('auto-updater/download-update');
    }

    /**
     * Quit and install. Terminates the app, so nothing after this runs — including
     * any shutdown work the app itself needs to do first.
     */
    public function quitAndInstall(): void
    {
        $this->client->post('auto-updater/quit-and-install');
    }
}
