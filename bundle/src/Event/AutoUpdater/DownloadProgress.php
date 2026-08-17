<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Event\AutoUpdater;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Runtime payload: {total, delta, transferred, percent, bytesPerSecond} — named.
 */
final class DownloadProgress extends Event
{
    public function __construct(
        public readonly int $total,
        public readonly int $delta,
        public readonly int $transferred,
        public readonly float $percent,
        public readonly float $bytesPerSecond,
    ) {
    }
}
