<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Event\Notifications;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * The notification body was clicked.
 *
 * Runtime payload: {reference, event} — named. `reference` correlates with the
 * value POST /api/notification returned. `event` is Electron's own event object
 * JSON-encoded as a string, not an array.
 *
 * Only arrives under this class when the notification did not override its event
 * name; with an override it becomes a NativeEvent.
 */
final class NotificationClicked extends Event
{
    public function __construct(
        public readonly string $reference,
        public readonly ?string $event,
    ) {
    }
}
