<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Event\MenuBar;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Files were dropped onto the tray icon.
 *
 * Runtime payload: [[...paths]] — a single positional argument that is itself
 * a list, not one argument per file.
 */
final class MenuBarDroppedFiles extends Event
{
    public function __construct(
        public readonly array $files,
    ) {
    }
}
