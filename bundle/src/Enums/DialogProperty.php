<?php

declare(strict_types=1);

namespace Native\Symfony\Enums;

/**
 * Electron's open/save dialog `properties`.
 *
 * OpenFile and OpenDirectory are mutually exclusive on Windows and Linux; on
 * macOS both can be set at once. MultiSelections is what makes dialog/open
 * return more than one path.
 */
enum DialogProperty: string
{
    case OpenFile = 'openFile';
    case OpenDirectory = 'openDirectory';
    case MultiSelections = 'multiSelections';
    case ShowHiddenFiles = 'showHiddenFiles';
    case CreateDirectory = 'createDirectory';
    case PromptToCreate = 'promptToCreate';
    case NoResolveAliases = 'noResolveAliases';
    case TreatPackageAsDirectory = 'treatPackageAsDirectory';
    case DontAddToRecent = 'dontAddToRecent';
    case ShowOverwriteConfirmation = 'showOverwriteConfirmation';
}
