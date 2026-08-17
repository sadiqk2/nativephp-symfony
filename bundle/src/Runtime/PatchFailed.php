<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Runtime;

final class PatchFailed extends \RuntimeException
{
    public static function notARuntime(string $path): self
    {
        return new self(sprintf(
            '"%s" does not look like a NativePHP Electron project: electron-plugin/src/server is missing.',
            $path,
        ));
    }

    public static function missingFile(string $path): self
    {
        return new self(sprintf('Expected runtime source file "%s" is missing.', $path));
    }

    /**
     * The patch was computed but could not be stored.
     *
     * Worth its own error rather than an ignored return value: an unwritable file leaves
     * the runtime with Laravel's hardcoded paths while the installer reports the hunks as
     * applied, which is the blank-window-with-no-diagnostic failure this class exists to
     * make impossible.
     */
    public static function writeFailed(string $path): self
    {
        return new self(sprintf(
            'Patched %s in memory but could not write it back. Check the file\'s permissions '.
            'and ownership — an unwritten patch leaves an app that launches and shows nothing.',
            $path,
        ));
    }

    public static function hunkDidNotMatch(string $hunk, string $path): self
    {
        return new self(sprintf(
            'Could not apply the "%s" patch to %s — the code it targets has changed upstream. '.
            'Re-read the file and update RuntimePatcher; do not skip the hunk, the app will not boot without it.',
            $hunk,
            $path,
        ));
    }
}
