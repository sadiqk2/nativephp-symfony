<?php

declare(strict_types=1);

namespace Native\Symfony\Event\AutoUpdater;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * The updater failed.
 *
 * Runtime payload: {name, message, stack} — named. Note the class name shadows
 * PHP's Error; always import it explicitly.
 */
final class Error extends Event
{
    public function __construct(
        public readonly string $name,
        public readonly string $message,
        public readonly ?string $stack,
    ) {
    }
}
