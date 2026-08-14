<?php

declare(strict_types=1);

namespace Native\Symfony\Enums;

/** Electron's dialog.showMessageBox `type`. */
enum AlertType: string
{
    case None = 'none';
    case Info = 'info';
    case Error = 'error';
    case Question = 'question';
    case Warning = 'warning';
}
