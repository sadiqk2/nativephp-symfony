<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Support;

use Symfony\Component\Filesystem\Path;

/**
 * Turns a user-supplied path into an absolute one, against the project directory.
 *
 * Every command that takes a path option needs this, and the naive version —
 * `str_starts_with($path, '/')` — is only correct on POSIX. `C:\dev\electron` and
 * `\\server\share` are both absolute and both failed it, so an option pointing at a real
 * directory was joined onto the project directory and then reported missing.
 *
 * `Path::isAbsolute()` gets that right, but it decides from `DIRECTORY_SEPARATOR`, which is
 * a constant: on Linux it can only ever answer for Linux. That is correct behaviour and
 * useless for a test — the Windows cases could only be asserted on Windows, so they were
 * skipped everywhere else, and a skipped assertion is just an unverified guess. This one
 * had two of them: they expected `C:/dev/electron` from code that returns the path
 * verbatim, so they would have *failed* on the platform they were written for.
 *
 * So the platform is injected, exactly as {@see Platform} already is for the macOS-only dock
 * paths, and the whole table runs on any host. The rule itself is a faithful port of
 * `Path::isAbsolute()`, and `ProjectPathTest` pins it to that function's real answers for
 * whichever platform the suite happens to run on.
 */
final class ProjectPath
{
    private readonly Platform $platform;

    public function __construct(
        private readonly string $projectDir,
        ?Platform $platform = null,
    ) {
        $this->platform = $platform ?? new Platform();
    }

    /**
     * The path as an absolute one: unchanged if it already is, joined onto the project
     * directory if not.
     *
     * Separators are rewritten **on Windows only**, and that condition is the whole
     * point of the method. There a backslash *is* a separator, so `C:\dev` and `C:/dev`
     * name the same directory and rewriting is free — worth doing, because a message
     * quoting `C:/dev` beats one quoting `C:\dev` mixed into a `/`-separated string.
     * On POSIX a backslash is an ordinary character in a filename, so rewriting one
     * addresses a *different* directory: this is the same fact `isAbsolute()` below
     * turns on, where `C:\dev` on Linux is one oddly-named relative directory rather
     * than a drive. Rewriting unconditionally would have made this method contradict
     * the one underneath it, and made `--electron-path='my\dir'` report a real
     * directory missing.
     *
     * Doing it here rather than leaving it to `Path::join()` is what keeps the answer
     * off the resolver: `Path::join()` used to rewrite backslashes and stopped in
     * symfony/filesystem 7.4 and 8.1, both inside the declared range, so this method
     * quietly answered two different things depending on which patch a consumer had.
     * One case is still `Path::join()`'s and cannot be taken back from it — a *relative*
     * POSIX path containing a literal backslash — which is pathological input on a
     * platform where the character is legal but nobody uses it.
     */
    public function absolute(string $path): string
    {
        if ($this->platform->isWindows()) {
            $path = str_replace('\\', '/', $path);
        }

        return $this->isAbsolute($path)
            ? $path
            : Path::join($this->projectDir, $path);
    }

    /**
     * A port of `Path::isAbsolute()` with the platform injected rather than read from
     * `DIRECTORY_SEPARATOR`.
     *
     * Drive letters and UNC prefixes are absolute *on Windows only*, and that is not a
     * detail to smooth over: on Linux `C:\dev` really is a relative path — one directory
     * with a colon and backslashes in its name — and resolving it against the project is
     * the right answer there.
     */
    public function isAbsolute(string $path): bool
    {
        if ('' === $path) {
            return false;
        }

        // Stream wrappers and URLs are absolute everywhere. This is also the one case where
        // this rule and a leading-slash test disagree on *every* platform, which is what
        // lets a test on any host catch a regression to the naive version.
        if (str_contains($path, '://') && null !== parse_url($path, \PHP_URL_SCHEME)) {
            return true;
        }

        if ('/' === $path[0]) {
            return true;
        }

        if (!$this->platform->isWindows()) {
            return false;
        }

        if ('\\' === $path[0]) {
            return true;
        }

        // "C:", and "C:/" or "C:\" followed by anything.
        return \strlen($path) > 1
            && ctype_alpha($path[0])
            && ':' === $path[1]
            && (2 === \strlen($path) || '/' === $path[2] || '\\' === $path[2]);
    }
}
