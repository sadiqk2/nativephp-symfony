#!/usr/bin/env python3
"""
Patch a copy of NativePHP's Electron runtime so it can host a Symfony app.

This is the whole diff. Every hunk corresponds to an item in ANALYSIS.md §6 —
the places the runtime assumes Laravel (§6's seven, plus APP_ENV naming
found by actually running it). Nothing else in the 4,100-line
plugin needs to change.

Run against the *copied* project in the app's own nativephp/electron/ dir.
`ElectronServiceProvider::electronPath()` already prefers that copy over the
vendor one whenever a package.json exists there, so this needs no upstream
cooperation.
"""

import sys
import pathlib

CLI = "bin/console"


def patch_php_ts(text: str) -> tuple[str, list[str]]:
    applied = []

    # ---- Hunk 1: CLI entrypoint (ANALYSIS §6 items 1 and 2) ----------------
    # Five call sites, all of the form ['artisan', ...].
    before = text
    text = text.replace("['artisan',", f"['{CLI}',")
    if text != before:
        applied.append(f"hunk 1: ['artisan', ...] -> ['{CLI}', ...] "
                       f"({before.count(chr(91) + chr(39) + 'artisan' + chr(39) + ',')} sites)")

    # The secure-bundle guards compare argv[0] against the CLI name.
    before = text
    text = text.replace("args[0] === 'artisan'", f"args[0] === '{CLI}'")
    if text != before:
        applied.append(f"hunk 1b: args[0] === 'artisan' -> '{CLI}'")

    # ---- Hunk 2: router script + docroot (ANALYSIS §6 item 3) --------------
    needle = """        serverPath = join(
            appPath,
            'vendor',
            'laravel',
            'framework',
            'src',
            'Illuminate',
            'Foundation',
            'resources',
            'server.php',
        );
        cwd = join(appPath, 'public');"""
    replacement = """        serverPath = join(appPath, 'public', 'nativephp-router.php');
        cwd = join(appPath, 'public');"""
    if needle in text:
        text = text.replace(needle, replacement)
        applied.append("hunk 2: Laravel server.php -> public/nativephp-router.php")
    elif replacement in text:
        applied.append("hunk 2: already applied")
    else:
        raise SystemExit("hunk 2 did not match — php.ts router block changed upstream")

    # ---- Hunk 3: don't assume a storage/ dir exists (ANALYSIS §6 item 4) ---
    # Symfony has no storage/; copySync on a missing source throws and takes the
    # whole boot down. This guard is a genuine upstream improvement.
    needle = "        copySync(join(appPath, 'storage'), storagePath);"
    replacement = """        const appStorage = join(appPath, 'storage');
        if (existsSync(appStorage)) {
            copySync(appStorage, storagePath);
        } else {
            console.log('No storage/ dir in the app; skipping copy.');
        }"""
    if needle in text:
        text = text.replace(needle, replacement)
        applied.append("hunk 3: guard the storage/ copy with existsSync")
    elif "No storage/ dir in the app" in text:
        applied.append("hunk 3: already applied")
    else:
        raise SystemExit("hunk 3 did not match — ensureAppFoldersAreAvailable changed")

    # ---- Hunk 5: environment naming (an 8th Laravel-ism, found by running it) --
    # The runtime hardcodes Laravel's env names. Symfony 8 whitelists APP_ENV in
    # App\Kernel::getAllowedEnvs() and throws InvalidArgumentException on
    # anything else, so 'local' is a hard boot failure, not a warning:
    #   The environment "local" is not registered as allowed by
    #   "App\Kernel::getAllowedEnvs()".
    # This belongs in the manifest as env.{dev,prod}.
    needle = "APP_ENV: process.env.NODE_ENV === 'development' ? 'local' : 'production',"
    replacement = "APP_ENV: process.env.NODE_ENV === 'development' ? 'dev' : 'prod',"
    if needle in text:
        text = text.replace(needle, replacement)
        applied.append("hunk 5: APP_ENV local/production -> dev/prod")
    elif replacement in text:
        applied.append("hunk 5: already applied")
    else:
        raise SystemExit("hunk 5 did not match — APP_ENV assignment changed")

    return text, applied


def patch_child_process_ts(text: str) -> tuple[str, list[str]]:
    applied = []
    before = text
    text = text.replace("settings.cmd[0] === 'artisan'", f"settings.cmd[0] === '{CLI}'")
    if text != before:
        applied.append(f"hunk 4: childProcess cmd[0] === 'artisan' -> '{CLI}'")
    return text, applied


def main() -> int:
    if len(sys.argv) != 2:
        print("usage: patch-runtime.py <path to nativephp/electron>", file=sys.stderr)
        return 2

    root = pathlib.Path(sys.argv[1])
    src = root / "electron-plugin" / "src" / "server"

    if not src.is_dir():
        print(f"not a NativePHP electron project: {root}", file=sys.stderr)
        return 1

    all_applied = []

    for rel, fn in (("php.ts", patch_php_ts), ("api/childProcess.ts", patch_child_process_ts)):
        path = src / rel
        original = path.read_text()
        patched, applied = fn(original)
        if patched != original:
            path.write_text(patched)
        all_applied += applied

    print(f"Patched {root}:")
    for line in all_applied:
        print(f"  - {line}")
    print("\nNow rebuild the plugin:  npm run plugin:build")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
