<?php

declare(strict_types=1);

namespace Native\Symfony\Notification;

use Native\Symfony\Contract\ClientInterface;

final class NotificationManager
{
    public function __construct(private readonly ClientInterface $client)
    {
    }

    public function create(): PendingNotification
    {
        return new PendingNotification($this->client);
    }

    /** @return string The reference for correlating with notification events */
    public function send(string $title, string $body): string
    {
        return $this->create()->title($title)->body($body)->show();
    }
}
