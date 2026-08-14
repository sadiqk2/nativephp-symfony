<?php

declare(strict_types=1);

namespace Native\Symfony\Event\ChildProcess;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * A chunk of the process's stderr. Not necessarily an error — many programs
 * write progress there.
 *
 * Runtime payload: {alias, data} — named.
 */
final class ErrorReceived extends Event
{
    public function __construct(
        public readonly string $alias,
        public readonly string $data,
    ) {
    }
}
