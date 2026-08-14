<?php

declare(strict_types=1);

namespace Native\Symfony\Event\Notifications;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * The user typed a reply (macOS, notifications created with hasReply).
 *
 * Runtime payload: {reference, reply, event} — named.
 */
final class NotificationReply extends Event
{
    public function __construct(
        public readonly string $reference,
        public readonly string $reply,
        public readonly ?string $event,
    ) {
    }
}
