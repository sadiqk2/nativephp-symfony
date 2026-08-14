<?php

declare(strict_types=1);

namespace Native\Symfony\Event\ChildProcess;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * The process started and has a pid.
 *
 * Runtime payload: [alias, pid] — POSITIONAL, unlike every other ChildProcess
 * event. A genuine upstream inconsistency, encoded rather than tidied.
 *
 * This is the first point at which the pid is reliable: the synchronous response
 * to child-process/start returns null for it, because `spawn` has not fired yet.
 */
final class ProcessSpawned extends Event
{
    public function __construct(
        public readonly string $alias,
        public readonly int $pid,
    ) {
    }
}
