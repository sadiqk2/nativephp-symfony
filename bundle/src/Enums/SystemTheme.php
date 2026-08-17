<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Enums;

/** Electron's nativeTheme.themeSource. */
enum SystemTheme: string
{
    case System = 'system';
    case Light = 'light';
    case Dark = 'dark';
}
