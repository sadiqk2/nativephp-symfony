<?php

declare(strict_types=1);

namespace Native\Symfony\Event\AutoUpdater;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * The update is on disk and ready to install via auto-updater/quit-and-install.
 *
 * Runtime payload: the 7 UpdateInfo keys plus downloadedFile — named.
 */
final class UpdateDownloaded extends Event
{
    public function __construct(
        public readonly string $downloadedFile,
        public readonly string $version,
        public readonly array $files,
        public readonly ?string $releaseDate,
        public readonly ?string $releaseName,
        public readonly mixed $releaseNotes,
        public readonly ?int $stagingPercentage,
        public readonly ?string $minimumSystemVersion,
    ) {
    }
}
