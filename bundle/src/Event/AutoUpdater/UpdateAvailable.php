<?php

declare(strict_types=1);

namespace Native\Symfony\Event\AutoUpdater;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * A newer version is available.
 *
 * Runtime payload: the 7 electron-updater UpdateInfo keys — named.
 *
 * Every optional key is defaulted: JSON.stringify drops undefined values and
 * electron-updater leaves most of UpdateInfo undefined on a real release. The
 * payload is spread as named arguments, so a dropped key is a missing argument —
 * without defaults this degraded to a generic NativeEvent and no typed listener
 * ever ran.
 */
final class UpdateAvailable extends Event
{
    public function __construct(
        public readonly string $version,
        public readonly array $files,
        public readonly ?string $releaseDate = null,
        public readonly ?string $releaseName = null,
        public readonly mixed $releaseNotes = null,
        public readonly ?int $stagingPercentage = null,
        public readonly ?string $minimumSystemVersion = null,
    ) {
    }
}
