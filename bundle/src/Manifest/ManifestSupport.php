<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Manifest;

/**
 * Whether an installed Electron runtime reads `nativephp.json`.
 *
 * Three cases rather than a boolean, because "we could not tell" has to be
 * actionable: it is the state in which the bundle must fall back to patching,
 * and callers should be able to say so rather than silently treating it as
 * Absent.
 */
enum ManifestSupport
{
    /** The runtime resolves its paths through a manifest. Patching would be a no-op at best. */
    case Supported;

    /** The runtime still has Laravel's literals. It must be patched, or the app will not boot. */
    case Absent;

    /**
     * Unreadable, or manifest-aware in some places and not others — a half-applied
     * patch, a hand edit, or an upstream refactor.
     *
     * Treat this as Absent and patch: patching applies its strict hunks or throws,
     * so a wrong guess is loud. Guessing Supported when it is not produces an app
     * that boots into Laravel's router and 404s every route with nothing in the log.
     */
    case Unknown;
}
