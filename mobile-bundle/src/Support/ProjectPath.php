<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Support;

use Symfony\Component\Filesystem\Path;

/**
 * Turns a user-supplied path into an absolute one, against the project directory.
 *
 * `--stage-dir` is the reason this exists here. Staging creates whatever directory it is
 * handed and fills it with a copy of the whole application, so deciding "is this absolute"
 * with `str_starts_with($path, '/')` — which is only true on POSIX — put several hundred
 * megabytes inside the project directory for anyone passing `D:\builds`, silently.
 *
 * `Path::isAbsolute()` answers correctly but reads `DIRECTORY_SEPARATOR`, a constant: on
 * Linux it can only answer for Linux, so the Windows cases could only be asserted on
 * Windows and were skipped everywhere else. A skipped assertion is an unverified guess.
 * The platform is therefore injected, the same way {@see \Native\Symfony\Mobile\Build\Toolchain}
 * already takes its `$osFamily`, and `MobileProjectPathTest` pins this rule to
 * `Path::isAbsolute()`'s real answers for whichever platform the suite runs on.
 *
 * Deliberately duplicated from the desktop bundle rather than shared: the two bundles have
 * no dependency on each other and either can be installed alone.
 */
final class ProjectPath
{
    public function __construct(
        private readonly string $projectDir,
        private readonly string $osFamily = \PHP_OS_FAMILY,
    ) {
    }

    /**
     * The path as an absolute one, with separators normalised **on Windows only**, so
     * output and messages carry one shape rather than two.
     *
     * The condition is the point. On Windows a backslash is a separator, so rewriting it
     * is free. On POSIX it is an ordinary character in a filename, so rewriting one names
     * a different directory — and this method's answer is what staging passes to `mkdir`
     * before copying the whole application into it, which is the reason `--stage-dir`
     * needed this class in the first place. It is also the fact `isAbsolute()` below turns
     * on, where `D:\builds` off Windows is one oddly-named relative directory.
     *
     * Doing it here rather than leaving it to `Path::join()` is what keeps the answer off
     * the resolver: `Path::join()` used to rewrite backslashes and stopped in
     * symfony/filesystem 7.4 and 8.1, both inside the declared range. The one case still
     * left to it is a *relative* POSIX path containing a literal backslash, which is
     * pathological input on a platform where the character is legal but nobody uses it.
     */
    public function absolute(string $path): string
    {
        if ('Windows' === $this->osFamily) {
            $path = str_replace('\\', '/', $path);
        }

        return $this->isAbsolute($path)
            ? $path
            : Path::join($this->projectDir, $path);
    }

    /**
     * As {@see absolute()}, then appended to.
     *
     * The appending is done here rather than by `Path::join()` for the same reason the rule
     * above is: `Path::join()` recognises roots through `DIRECTORY_SEPARATOR` too, so on Linux
     * it collapsed a UNC `//build/share` to `/build/share` — turning a resolved Windows path
     * into a plausible-looking Linux one. Concatenating leaves the prefix alone, so the answer
     * depends on the platform passed in and nothing else.
     */
    public function join(string $path, string ...$segments): string
    {
        $joined = $this->absolute($path);

        foreach ($segments as $segment) {
            $segment = trim(str_replace('\\', '/', $segment), '/');

            if ('' !== $segment) {
                $joined = rtrim($joined, '/').'/'.$segment;
            }
        }

        return $joined;
    }

    /**
     * A port of `Path::isAbsolute()` with the platform injected instead of read from
     * `DIRECTORY_SEPARATOR`. Drive letters and UNC prefixes are absolute on Windows only —
     * on Linux `D:\builds` really is one oddly-named relative directory.
     */
    public function isAbsolute(string $path): bool
    {
        if ('' === $path) {
            return false;
        }

        // The one case where this rule and a leading-slash test disagree on every platform,
        // which is what lets a test on any host catch a regression to the naive version.
        if (str_contains($path, '://') && null !== parse_url($path, \PHP_URL_SCHEME)) {
            return true;
        }

        if ('/' === $path[0]) {
            return true;
        }

        if ('Windows' !== $this->osFamily) {
            return false;
        }

        if ('\\' === $path[0]) {
            return true;
        }

        return \strlen($path) > 1
            && ctype_alpha($path[0])
            && ':' === $path[1]
            && (2 === \strlen($path) || '/' === $path[2] || '\\' === $path[2]);
    }
}
