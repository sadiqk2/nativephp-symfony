<?php

declare(strict_types=1);

namespace Native\Symfony\App;

/**
 * Electron's app.getPath() names, as accepted by GET app/path/{name}.
 *
 * Note these are the paths queried live from the runtime. Ten of them are also
 * pushed into the environment at boot as NATIVEPHP_*_PATH — prefer
 * NativePaths for those, since it needs no round-trip.
 */
enum AppPath: string
{
    case Home = 'home';
    case AppData = 'appData';
    case UserData = 'userData';
    case SessionData = 'sessionData';
    case Temp = 'temp';
    case Exe = 'exe';
    case Module = 'module';
    case Desktop = 'desktop';
    case Documents = 'documents';
    case Downloads = 'downloads';
    case Music = 'music';
    case Pictures = 'pictures';
    case Videos = 'videos';
    case Recent = 'recent';
    case Logs = 'logs';
    case CrashDumps = 'crashDumps';
}
