<?php

declare(strict_types=1);

namespace Native\Symfony\Event\MenuBar;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Runtime payload: {combo, bounds} — named.
 */
final class MenuBarDoubleClicked extends Event
{
    public function __construct(
        public readonly array $combo,
        public readonly array $bounds,
    ) {
    }
}
