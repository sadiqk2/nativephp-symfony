<?php

declare(strict_types=1);

namespace Native\Symfony\Enums;

/**
 * macOS dock bounce style. `Critical` bounces until the app is activated;
 * `Informational` bounces once.
 */
enum DockBounce: string
{
    case Critical = 'critical';
    case Informational = 'informational';
}
