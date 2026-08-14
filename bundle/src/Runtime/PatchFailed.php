<?php

declare(strict_types=1);

namespace Native\Symfony\Runtime;

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
