<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Ui\Component;

/**
 * One interaction arriving from the native side.
 *
 * This is the *only* place device input becomes PHP values, which is why it is a
 * value object rather than an array passed around: the payload is narrowed to
 * scalars here, by event type, and nothing downstream re-reads the raw array. A
 * handler therefore cannot be handed an array or an object it did not declare, and
 * the payload can never influence *which* method runs — only what it is told.
 *
 * The type codes are the native `EventType` enum, mirrored from upstream's
 * `Native\Mobile\Testing\TestableComponent`. They are part of the wire contract, so
 * they are constants here rather than magic numbers at the call site.
 */
final class InteractionEvent
{
    public const PRESS = 0;
    public const LONG_PRESS = 1;
    public const TEXT_CHANGE = 2;
    public const TOGGLE_CHANGE = 3;
    public const SUBMIT = 4;
    public const SLIDER_CHANGE = 9;
    public const CHECKBOX_CHANGE = 10;
    public const RADIO_CHANGE = 11;
    public const SELECT_CHANGE = 12;
    public const TAB_CHANGE = 13;
    public const SHEET_DISMISS = 14;

    /**
     * @param list<string|int|float|bool> $payload Arguments the event itself carries,
     *                                             appended after the expression's own
     *                                             literal arguments
     */
    private function __construct(
        public readonly int $callbackId,
        public readonly int $type,
        public readonly array $payload,
    ) {
    }

    /**
     * @param array<string, mixed> $event The raw event as the native layer delivers it
     */
    public static function fromArray(array $event): self
    {
        $type = (int) ($event['type'] ?? self::PRESS);

        // Casting rather than passing through is the point: a `toggle` handler
        // declaring `bool` must not receive the string "false", and a text handler
        // must not receive an array. An unknown type carries nothing, so a future
        // native event code degrades to a plain press instead of leaking its payload
        // into an argument slot.
        $payload = match ($type) {
            self::TEXT_CHANGE, self::SUBMIT => [(string) ($event['text'] ?? '')],
            self::TOGGLE_CHANGE, self::CHECKBOX_CHANGE => [(bool) ($event['value'] ?? false)],
            self::SLIDER_CHANGE => [(float) ($event['value'] ?? 0.0)],
            self::RADIO_CHANGE, self::SELECT_CHANGE => [(string) ($event['value'] ?? '')],
            self::TAB_CHANGE => [(int) ($event['value'] ?? 0)],
            default => [],
        };

        return new self((int) ($event['callback_id'] ?? 0), $type, $payload);
    }

    /** For tests and for the native-side driver, which builds events by hand. */
    public static function press(int $callbackId): self
    {
        return new self($callbackId, self::PRESS, []);
    }
}
