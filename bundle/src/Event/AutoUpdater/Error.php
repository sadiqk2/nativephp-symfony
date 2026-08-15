<?php

declare(strict_types=1);

namespace Native\Symfony\Event\AutoUpdater;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * The updater failed.
 *
 * Runtime payload: {name, message, stack} — named. Note the class name shadows
 * PHP's Error; always import it explicitly.
 *
 * Every optional key is defaulted: JSON.stringify drops undefined values and
 * electron-updater leaves most of UpdateInfo undefined on a real release. The
 * payload is spread as named arguments, so a dropped key is a missing argument —
 * without defaults this degraded to a generic NativeEvent and no typed listener
 * ever ran.
 */
final class Error extends Event
{
    public function __construct(
        public readonly string $name,
        public readonly string $message,
        public readonly ?string $stack = null,
    ) {
    }
}
