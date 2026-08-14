<?php

declare(strict_types=1);

namespace App\Native;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Carrier for runtime events that have no dedicated class — the caller-named
 * events from global shortcuts, menu items and notification overrides.
 */
final class NativeEvent extends Event
{
    /** @param array<mixed> $payload */
    public function __construct(
        public readonly string $name,
        public readonly array $payload = [],
    ) {
    }
}
