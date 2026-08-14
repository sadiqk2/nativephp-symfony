<?php

declare(strict_types=1);

namespace Native\Symfony\Event\ChildProcess;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * The process could not be started, or did not spawn within spawnTimeout
 * (30s by default).
 *
 * Runtime payload: {alias, error} — named.
 */
final class StartupError extends Event
{
    public function __construct(
        public readonly string $alias,
        public readonly string $error,
    ) {
    }
}
