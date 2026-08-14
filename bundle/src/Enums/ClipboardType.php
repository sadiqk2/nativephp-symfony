<?php

declare(strict_types=1);

namespace Native\Symfony\Enums;

/**
 * Clipboard buffer. `Selection` is the X11 primary selection and is ignored on
 * macOS and Windows.
 */
enum ClipboardType: string
{
    case Clipboard = 'clipboard';
    case Selection = 'selection';
}
