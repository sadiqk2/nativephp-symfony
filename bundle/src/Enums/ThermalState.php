<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Enums;

enum ThermalState: string
{
    case Unknown = 'unknown';
    case Nominal = 'nominal';
    case Fair = 'fair';
    case Serious = 'serious';
    case Critical = 'critical';
}
