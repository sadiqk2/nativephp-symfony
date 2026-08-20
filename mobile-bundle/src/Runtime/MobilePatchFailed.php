<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Runtime;

final class MobilePatchFailed extends \RuntimeException
{
    public static function missingFile(string $path): self
    {
        return new self(sprintf('Expected native source file "%s" is missing.', $path));
    }

    /**
     * The retarget was computed but could not be stored. Same reasoning as everything else
     * in this class: an unwritten patch is indistinguishable, on a device, from one that
     * was never attempted.
     */
    public static function writeFailed(string $path): self
    {
        return new self(sprintf(
            'Retargeted %s in memory but could not write it back. Check the file\'s permissions '.
            'and ownership — an unwritten patch leaves an app that launches to a blank screen.',
            $path,
        ));
    }

    public static function unreadableBundleMeta(string $path): self
    {
        return new self(sprintf(
            'The bundle manifest at %s is not a JSON object, so entry_mode cannot be set without '.
            'discarding whatever it does contain. Rebuild it with native:mobile:build rather than '.
            'letting this overwrite it.',
            $path,
        ));
    }

    public static function noIosSources(string $projectPath): self
    {
        return new self(sprintf(
            'No patchable iOS sources under "%s/NativePHP". Run the mobile install so the Xcode '.
            'project is copied into the application first.',
            $projectPath,
        ));
    }

    /**
     * The host's compiled-in PHP still names a Laravel class after the rewrite.
     *
     * Reported rather than written, because a partially rewritten eval is worse than an
     * untouched one: the boot check would pass and the dispatch would fatal, which reads
     * on a device as an app that starts and then shows nothing.
     */
    public static function laravelEvalSurvived(string $path): self
    {
        return new self(sprintf(
            'Rewrote the host evaluations in %s but a reference to the Laravel runtime survived, '.
            'so the shape of those literals has changed upstream. Re-read the file and update '.
            'MobileRuntimePatcher::HOST_EVAL_REPLACEMENTS.',
            $path,
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
