<?php

declare(strict_types=1);

namespace Native\Symfony\Event\Settings;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * A value in the runtime's settings store changed.
 *
 * Runtime payload: {key, value} — named. `value` is null when the key was deleted.
 *
 * Fires for writes the app makes itself, too: the runtime watches the store, not
 * the caller. A listener that writes on change will loop.
 */
final class SettingChanged extends Event
{
    public function __construct(
        public readonly string $key,
        public readonly mixed $value,
    ) {
    }
}
