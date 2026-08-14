<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Ui\Style;

/**
 * Resolves the `theme-*` half of the utility vocabulary.
 *
 * `bg-theme-primary`, `text-theme-on-surface` and friends are *not* Tailwind classes and
 * are not part of the parser's table surface: the token set is whatever the application
 * declares. Hardcoding the ones a demo happens to use would be exactly the "partial
 * hand-written subset" NATIVE-UI-CONTRACT.md §4d warns about, so the parser delegates
 * here instead and drops the class when nothing resolves.
 *
 * Two lookups rather than one, because a theme token carries a light *and* a dark hex and
 * the parser emits both (the dark one as a `dark` companion the collector turns into
 * `dark_*` props). Returning null from either side is normal — an unknown token, or a
 * theme with no dark variant.
 *
 * Implementations must return wire-format hex: `#RRGGBB` or `#AARRGGBB`.
 */
interface ThemeColorResolverInterface
{
    /**
     * @param string $token the part after `theme-`, e.g. `primary`, `on-surface-variant`
     */
    public function resolveLight(string $token): ?string;

    public function resolveDark(string $token): ?string;
}
