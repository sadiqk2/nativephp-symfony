<?php

declare(strict_types=1);

namespace Native\Symfony\Enums;

enum IdleState: string
{
    case Active = 'active';
    case Idle = 'idle';
    case Locked = 'locked';
    case Unknown = 'unknown';
}
