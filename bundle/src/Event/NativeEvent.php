<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Carrier for runtime events with no dedicated class.
 *
 * Two populations end up here, and both matter:
 *
 *  1. Caller-named events. Global shortcuts, menu items and notification
 *     overrides all let the app choose the name the runtime pushes back, so
 *     there is no fixed class to map to. This is a load-bearing path, not a
 *     fallback — an implementation that only handles the 44 known names drops
 *     every keyboard shortcut and menu click.
 *  2. Known runtime events the bundle has not yet given a typed class.
 *
 * Dispatched under two names, because there is no class to key on:
 *
 *     #[AsEventListener(event: 'native.App\Menu\Hello')]   // one action
 *     public function onHello(NativeEvent $event): void {}
 *
 *     #[AsEventListener]                                     // every one of them
 *     public function onAny(NativeEvent $event): void {}
 *
 * Register for one or the other — a listener on both runs twice.
 */
final class NativeEvent extends Event
{
    /** @param array<array-key, mixed> $payload */
    public function __construct(
        public readonly string $name,
        public readonly array $payload = [],
    ) {
    }

    public function dispatchName(): string
    {
        return 'native.'.$this->name;
    }

    public function get(string|int $key, mixed $default = null): mixed
    {
        return $this->payload[$key] ?? $default;
    }
}
