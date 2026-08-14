<?php

declare(strict_types=1);

namespace Native\Symfony\Event\ChildProcess;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * A chunk of the process's stdout.
 *
 * Runtime payload: {alias, data} — named. Chunks are not line-buffered: one
 * event may carry several lines or part of one.
 */
final class MessageReceived extends Event
{
    public function __construct(
        public readonly string $alias,
        public readonly string $data,
    ) {
    }
}
