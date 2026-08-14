<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Ui\Component;

/**
 * A callback could not be dispatched to a method, and was refused.
 *
 * Refused, not ignored, and the distinction is deliberate. There are two ways a
 * callback can fail to land, and they want opposite treatment:
 *
 *  - An *unknown id* — an id the component graph has never registered. That is
 *    routine: the device may still hold a superseded frame and tap a node that no
 *    longer exists. `ComponentScreen::handle()` drops those and returns null.
 *  - A *known id whose expression does not resolve to a permitted action* — a
 *    malformed expression, an unmarked method, a wrong argument count. That can only
 *    happen because the application registered something it cannot dispatch, so it
 *    is a bug, and a bug that would otherwise present as a button that silently does
 *    nothing on a phone. This exception is how it becomes visible instead.
 */
final class CallbackRefused extends \RuntimeException
{
}
