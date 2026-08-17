<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Event\AutoUpdater;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * An update check started. Runtime payload: none.
 */
final class CheckingForUpdate extends Event
{}
