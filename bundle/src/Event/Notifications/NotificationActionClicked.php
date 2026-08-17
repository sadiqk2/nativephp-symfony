<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Event\Notifications;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * One of the notification's action buttons was clicked.
 *
 * Runtime payload: {reference, index, event} — named. `index` is the position in
 * the `actions` array sent with the notification.
 */
final class NotificationActionClicked extends Event
{
    public function __construct(
        public readonly string $reference,
        public readonly int $index,
        public readonly ?string $event,
    ) {
    }
}
