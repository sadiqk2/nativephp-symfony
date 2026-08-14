<?php

declare(strict_types=1);

namespace Native\Symfony\Event\AutoUpdater;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * A newer version is available.
 *
 * Runtime payload: the 7 electron-updater UpdateInfo keys — named.
 */
final class UpdateAvailable extends Event
{
    public function __construct(
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
