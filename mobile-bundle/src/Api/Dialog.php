<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Api;

use Native\Symfony\Mobile\Bridge\BridgeInterface;

final class Dialog
{
    /**
     * The event name both hosts fall back to when the payload names none.
     *
     * It is a Laravel class name because that is all the hosts do with it: the string
     * goes out in `event` and comes back as the name of the event the app receives.
     * Nothing here has to be able to load it.
     */
    public const BUTTON_PRESSED = 'Native\\Mobile\\Events\\Alert\\ButtonPressed';

    /** The only styles the hosts map; anything else would render as a plain button. */
    public const BUTTON_STYLES = ['default', 'cancel', 'destructive'];

    public function __construct(private readonly BridgeInterface $bridge)
    {
    }

    /** A transient message. 'short' or 'long' — Android durations; iOS approximates. */
    public function toast(string $message, string $duration = 'long'): bool
    {
        return $this->bridge->dispatch('Dialog.Toast', [
            'message' => $message,
            'duration' => $duration,
        ]);
    }

    /**
     * A native alert with buttons.
     *
     * Asynchronous: which button the user tapped arrives later as the event named by
     * $event, carrying `index`, `label` and the `id` below. A true here means the alert
     * was presented, nothing more.
     *
     * Buttons are plain strings, or `['label' => 'Delete', 'style' => 'destructive']`
     * for a styled one. Pass none and both hosts substitute a single OK.
     *
     * @param list<string|array{label: string, style?: string}> $buttons
     * @param string|null                                      $id      Echoed back in the event, so a
     *                                                                  listener can tell which alert
     *                                                                  answered. Generated when absent,
     *                                                                  as upstream's PendingAlert does,
     *                                                                  rather than left off the wire
     */
    public function alert(string $title, string $message, array $buttons = [], ?string $id = null, string $event = self::BUTTON_PRESSED): bool
    {
        return $this->bridge->dispatch('Dialog.Alert', [
            'title' => $title,
            'message' => $message,
            'buttons' => $this->normaliseButtons($buttons),
            'id' => $id ?? bin2hex(random_bytes(8)),
            'event' => $event,
        ]);
    }

    /**
     * @param array<array-key, mixed> $buttons
     *
     * @return list<string|array{label: string, style: string}>
     */
    private function normaliseButtons(array $buttons): array
    {
        // array_values is load-bearing, and upstream does the same: a string- or
        // gap-keyed array encodes as a JSON object, which neither host recognises as a
        // list, so it substitutes a single OK and the app's own buttons vanish.
        return array_map($this->normaliseButton(...), array_values($buttons));
    }

    /** @return string|array{label: string, style: string} */
    private function normaliseButton(mixed $button): string|array
    {
        if (\is_string($button)) {
            return $button;
        }

        if (!\is_array($button) || !\is_string($button['label'] ?? null)) {
            throw new \InvalidArgumentException('An alert button is a string, or an array with a string "label" key.');
        }

        $style = $button['style'] ?? 'default';

        if (!\in_array($style, self::BUTTON_STYLES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Alert button style must be one of: %s.',
                implode(', ', self::BUTTON_STYLES),
            ));
        }

        return ['label' => $button['label'], 'style' => $style];
    }
}
