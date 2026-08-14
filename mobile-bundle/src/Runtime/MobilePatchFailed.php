<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Runtime;

final class MobilePatchFailed extends \RuntimeException
{
    public static function missingFile(string $path): self
    {
        return new self(sprintf('Expected native source file "%s" is missing.', $path));
    }

    public static function noIosSources(string $projectPath): self
    {
        return new self(sprintf(
            'No patchable iOS sources under "%s/NativePHP". Run the mobile install so the Xcode '.
            'project is copied into the application first.',
            $projectPath,
        ));
    }

    public static function hunkDidNotMatch(string $label, string $path): self
    {
        return new self(sprintf(
            'Could not retarget any bootstrap path in %s (%s) — the hardcoded paths have changed '.
            'upstream. Re-read the file and update MobileRuntimePatcher; skipping this leaves an app '.
            'that launches to a blank screen with no diagnostic.',
            $label,
            $path,
        ));
    }
}
