<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Event\ChildProcess;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * The process exited.
 *
 * Runtime payload: {alias, code} — named.
 *
 * If it was started with persistent: true, the runtime restarts it 1s later — a
 * watchdog with a fixed delay, not a supervisor with backoff.
 */
final class ProcessExited extends Event
{
    public function __construct(
        public readonly string $alias,
        public readonly ?int $code,
    ) {
    }
}
