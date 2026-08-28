<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Ui\Routing;

/**
 * Port of `BootPlanner.matches()` — the device-side decision about whether a path is a
 * native screen.
 *
 * This class exists to be *identical*, not merely correct. The native side matches the
 * start path against the baked patterns to choose NATIVE_DIRECT or WEB_LEGACY before PHP
 * runs at all; PHP then matches again to find the screen to render. If the two matchers
 * disagree the failure is asymmetric and silent:
 *
 *  - native says yes, PHP says no  → the runloop starts and has nothing to render (upstream
 *    treats an unresolved URI as "exit to web", so the user gets a blank frame then a
 *    WebView).
 *  - native says no, PHP says yes  → the screen is only ever reached through a WebView, and
 *    the native path it was written for is dead code.
 *
 * Neither shows up off-device, which is why this is a line-by-line port of
 * `resources/androidstudio/.../ui/BootPlanner.kt` (and its Swift twin) rather than a
 * reimplementation of what the doc comment there says it does. Behaviour worth naming,
 * because it is not what a Symfony developer would expect from a route pattern:
 *
 *  - Matching is segment-wise, and empty segments are dropped. Leading and trailing slashes
 *    are therefore insignificant, and `/items//42` matches `/items/{id}`.
 *  - A `{param}` segment matches *any* single non-empty segment. There are no requirements,
 *    no regex, no type coercion — `/items/{id}` matches `/items/not-a-number`.
 *  - `{param?}` is optional, and **one optional segment makes the whole remaining tail
 *    optional** — required placeholders and literal segments included. `/items/{a?}/edit`
 *    matches `/items`. That contradicts BootPlanner's own doc comment ("optional trailing
 *    matches") but it is what the code does, on both platforms: the short-path branch
 *    short-circuits on `isOptional` before it ever evaluates the "all remaining are optional"
 *    test, which makes that test dead code. Do not declare required segments after an
 *    optional one; the resolved screen would be mounted without them.
 *  - A path with extra segments never matches: the final segment-count equality check is
 *    what rejects `/items/42/edit` against `/items/{id}`.
 *  - A pattern is not anchored to a route parameter's name in any way, so two patterns can
 *    both match one path. Both `BootPlanner`s use "any pattern matches" for the boot
 *    decision; {@see NativeRouteRegistry::resolve()} defines the tie-break for rendering.
 */
final class NativeRouteMatcher
{
    /**
     * Does `$path` match this Laravel-style URI `$pattern`?
     *
     * Deliberately a faithful transcription of BootPlanner.matches(), including the
     * `$isOptional` short-circuit that swallows the loop below it. Simplifying this to what
     * upstream's doc comment describes would be a behaviour change PHP could not see and a
     * device would.
     */
    public static function matches(string $pattern, string $path): bool
    {
        $p = self::segments($pattern);
        $s = self::segments($path);

        $pCount = \count($p);
        $sCount = \count($s);

        for ($i = 0; $i < $pCount; ++$i) {
            $seg = $p[$i];
            $isParam = self::isParameter($seg);
            $isOptional = $isParam && str_ends_with($seg, '?}');

            if ($i < $sCount) {
                if (!$isParam && $seg !== $s[$i]) {
                    return false;
                }

                continue;
            }

            // Path ran out. Upstream accepts immediately when *this* segment is optional,
            // without looking at what follows it — see the class docblock.
            if ($isOptional) {
                return true;
            }

            // Unreachable in practice (a required $seg fails at $j === $i), kept because it is
            // in the original and a future upstream fix to the line above would revive it.
            for ($j = $i; $j < $pCount; ++$j) {
                if (!str_starts_with($p[$j], '{') || !str_ends_with($p[$j], '?}')) {
                    return false;
                }
            }

            return true;
        }

        // Rejects a longer path. Reached only when the path had at least as many segments
        // as the pattern, so this is the "no extra segments" rule.
        return $sCount === $pCount;
    }

    /**
     * Normalise a start path the way `BootPlanner.plan()` does before matching.
     *
     * The query string is stripped and an empty result becomes `/`. Kotlin does this with
     * `substringBefore('?').ifEmpty { "/" }`; Swift's `components(separatedBy:).first ?? "/"`
     * yields `""` instead of `"/"` for a query-only path, which is harmless only because
     * both collapse to zero segments. Fragments are *not* stripped — a `#` in a start path
     * would be compared literally, on device too.
     */
    public static function normalizeStartPath(string $startPath): string
    {
        $path = strstr($startPath, '?', true);

        if (false === $path) {
            $path = $startPath;
        }

        return '' === $path ? '/' : $path;
    }

    /**
     * Parameter values for a path already known to match, keyed by placeholder name.
     *
     * Segment-wise, so it agrees with {@see matches()} — including on `{id?}`. Upstream's
     * `NativeRouter::resolve()` extracts with `preg_replace('/\{(\w+)\}/', ...)`, and `\w`
     * does not match `?`: for a pattern containing `{id?}` the placeholder survives into the
     * regex as a literal, the match fails, and the screen is mounted with **no parameters at
     * all** even though `BootPlanner` correctly booted it natively. Reproducing that bug
     * would buy nothing — the extracted values never cross the wire, so PHP and the device
     * cannot disagree about them.
     *
     * A missing optional segment is omitted rather than set to null, so a screen can declare
     * its own default.
     *
     * @return array<string, string>
     */
    public static function parameters(string $pattern, string $path): array
    {
        $p = self::segments($pattern);
        $s = self::segments($path);

        $params = [];

        foreach ($p as $i => $seg) {
            if (!self::isParameter($seg)) {
                continue;
            }

            if (!isset($s[$i])) {
                continue;
            }

            $params[self::parameterName($seg)] = $s[$i];
        }

        return $params;
    }

    /**
     * Placeholder names in declaration order, optional ones included.
     *
     * @return list<string>
     */
    public static function parameterNames(string $pattern): array
    {
        $names = [];

        foreach (self::segments($pattern) as $seg) {
            if (self::isParameter($seg)) {
                $names[] = self::parameterName($seg);
            }
        }

        return $names;
    }

    public static function isOptionalParameter(string $segment): bool
    {
        return self::isParameter($segment) && str_ends_with($segment, '?}');
    }

    /**
     * The canonical form of a pattern: exactly one leading slash and the segments {@see matches()}
     * would compare, joined back up.
     *
     * Upstream's `NativeRouter::register()` only fixes the leading slash, which is enough for
     * a registry that lets the last registration win. It is not enough here, because this is
     * also the key {@see NativeRouteRegistry} looks a path up under, and matching is
     * segment-wise: `/items/new`, `/items/new/` and `/items//new` are one pattern to
     * `BootPlanner` and to `matches()`, so they have to be one key. Leaving them as three cost
     * two silent failures — the duplicate-pattern guard was bypassed by a spelling, and the
     * exact-over-placeholder precedence in `resolve()` flipped to declaration order for any
     * start path not spelled byte-for-byte like its pattern, which for a deep link is normal.
     *
     * The manifest carries these strings to the device, and the device compares segments too,
     * so canonicalising here changes no boot decision — it only makes the baked list and the
     * runtime dump comparable by eye when one goes wrong.
     */
    public static function normalizePattern(string $pattern): string
    {
        return '/'.implode('/', self::segments($pattern));
    }

    /**
     * Split on `/`, dropping empty segments — `trim('/')` then `filter { isNotEmpty() }`.
     *
     * @return list<string>
     */
    private static function segments(string $value): array
    {
        $trimmed = trim($value, '/');

        if ('' === $trimmed) {
            return [];
        }

        return array_values(array_filter(explode('/', $trimmed), static fn (string $s): bool => '' !== $s));
    }

    /**
     * Upstream's test is purely positional — `{` at the start, `}` at the end — so `{}` is a
     * parameter and `{a}b` is not.
     */
    private static function isParameter(string $segment): bool
    {
        return str_starts_with($segment, '{') && str_ends_with($segment, '}');
    }

    private static function parameterName(string $segment): string
    {
        return rtrim(substr($segment, 1, -1), '?');
    }
}
