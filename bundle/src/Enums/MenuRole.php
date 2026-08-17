<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Enums;

/**
 * Electron's built-in menu item roles.
 *
 * A role item is reduced by the runtime to `{role, label?}` — every other key you
 * set on it is discarded, including `id` and `event`, so a role item can never
 * report a click back to PHP. That is deliberate: the OS handles the behaviour.
 */
enum MenuRole: string
{
    case Undo = 'undo';
    case Redo = 'redo';
    case Cut = 'cut';
    case Copy = 'copy';
    case Paste = 'paste';
    case PasteAndMatchStyle = 'pasteAndMatchStyle';
    case SelectAll = 'selectAll';
    case Delete = 'delete';
    case Reload = 'reload';
    case ForceReload = 'forceReload';
    case ToggleDevTools = 'toggleDevTools';
    case ResetZoom = 'resetZoom';
    case ZoomIn = 'zoomIn';
    case ZoomOut = 'zoomOut';
    case ToggleFullScreen = 'togglefullscreen';
    case Minimize = 'minimize';
    case Close = 'close';
    case Quit = 'quit';
    case About = 'about';
    case Hide = 'hide';
    case HideOthers = 'hideOthers';
    case Unhide = 'unhide';
    case StartSpeaking = 'startSpeaking';
    case StopSpeaking = 'stopSpeaking';
    case Zoom = 'zoom';
    case Front = 'front';
    case AppMenu = 'appMenu';
    case FileMenu = 'fileMenu';
    case EditMenu = 'editMenu';
    case ViewMenu = 'viewMenu';
    case WindowMenu = 'windowMenu';
    case ShareMenu = 'shareMenu';
    case Services = 'services';
    case RecentDocuments = 'recentDocuments';
    case ClearRecentDocuments = 'clearRecentDocuments';
    case ToggleTabBar = 'toggleTabBar';
    case SelectNextTab = 'selectNextTab';
    case SelectPreviousTab = 'selectPreviousTab';
    case MergeAllWindows = 'mergeAllWindows';
    case MoveTabToNewWindow = 'moveTabToNewWindow';
    case Window = 'window';
    case Help = 'help';
}
