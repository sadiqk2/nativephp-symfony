<?php

declare(strict_types=1);

namespace Native\Symfony\Event\Notifications;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * The notification was dismissed.
 *
 * Runtime payload: {reference, event} — named.
 *
 * The runtime holds a strong reference to every notification until it is clicked
 * or closed (working around electron#16922), so one that is never dismissed leaks
 * until the app exits.
 */
final class NotificationClosed extends Event
{
    public function __construct(
        public readonly string $reference,
        public readonly ?string $event,
    ) {
    }
}
