<?php

declare(strict_types=1);

namespace Native\Symfony\Notification;

use Native\Symfony\Contract\ClientInterface;

/**
 * Builder for POST /api/notification.
 *
 * The returned reference correlates the notification with its four events. Supply
 * your own if you want to key application state on it; otherwise the runtime
 * generates `{timestamp}.{random}`.
 */
final class PendingNotification
{
    /** @var array<string, mixed> */
    private array $payload = [];

    public function __construct(private readonly ClientInterface $client)
    {
    }

    public function title(string $title): self
    {
        $this->payload['title'] = $title;

        return $this;
    }

    public function body(string $body): self
    {
        $this->payload['body'] = $body;

        return $this;
    }

    /** macOS only. */
    public function subtitle(string $subtitle): self
    {
        $this->payload['subtitle'] = $subtitle;

        return $this;
    }

    public function icon(string $path): self
    {
        $this->payload['icon'] = $path;

        return $this;
    }

    public function silent(bool $silent = true): self
    {
        $this->payload['silent'] = $silent;

        return $this;
    }

    /**
     * A sound name, or a path to an audio file.
     *
     * A value containing a slash (and not starting with http) is treated as a local
     * file: the runtime silences the native sound and plays the file itself. A
     * missing file is reported to the renderer console as a log message, **not** to
     * PHP — so a typo here fails silently on this side.
     */
    public function sound(string $sound): self
    {
        $this->payload['sound'] = $sound;

        return $this;
    }

    /** 'normal', 'critical' or 'low' — Linux only. */
    public function urgency(string $urgency): self
    {
        $this->payload['urgency'] = $urgency;

        return $this;
    }

    /** 'default' or 'never'. 'never' keeps the notification up until dismissed. */
    public function timeoutType(string $type): self
    {
        $this->payload['timeoutType'] = $type;

        return $this;
    }

    /** macOS only: adds an inline reply field, delivered as NotificationReply. */
    public function withReply(string $placeholder = ''): self
    {
        $this->payload['hasReply'] = true;

        if ('' !== $placeholder) {
            $this->payload['replyPlaceholder'] = $placeholder;
        }

        return $this;
    }

    /**
     * Action buttons (macOS). Each is `['type' => 'button', 'text' => '…']`; the
     * index in this list is what NotificationActionClicked reports.
     *
     * @param list<string> $labels
     */
    public function actions(array $labels): self
    {
        $this->payload['actions'] = array_map(
            static fn (string $text): array => ['type' => 'button', 'text' => $text],
            array_values($labels),
        );

        return $this;
    }

    public function closeButtonText(string $text): self
    {
        $this->payload['closeButtonText'] = $text;

        return $this;
    }

    /** Windows only: raw toast XML, overriding title/body entirely. */
    public function toastXml(string $xml): self
    {
        $this->payload['toastXml'] = $xml;

        return $this;
    }

    /** Override the event name pushed back on click. */
    public function event(string $event): self
    {
        $this->payload['event'] = $event;

        return $this;
    }

    public function reference(string $reference): self
    {
        $this->payload['reference'] = $reference;

        return $this;
    }

    /**
     * Show it.
     *
     * @return string The reference, for correlating with the four notification events
     */
    public function show(): string
    {
        $response = $this->client->post('notification', $this->payload);

        return (string) $response->value('reference', $this->payload['reference'] ?? '');
    }

    /** @return array<string, mixed> Exposed for assertions in tests. */
    public function payload(): array
    {
        return $this->payload;
    }
}
