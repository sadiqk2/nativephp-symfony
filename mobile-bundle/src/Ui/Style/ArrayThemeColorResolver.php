<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Ui\Style;

/**
 * A theme resolver backed by two plain token → hex maps.
 *
 * This is the shape a theme almost always has once it is loaded from configuration, so it
 * saves every application writing the same two-method adapter. Anything more dynamic
 * (per-tenant themes, tokens computed from a design-token file) implements
 * {@see ThemeColorResolverInterface} directly.
 */
final class ArrayThemeColorResolver implements ThemeColorResolverInterface
{
    /**
     * @param array<string, string> $light token → `#RRGGBB` / `#AARRGGBB`
     * @param array<string, string> $dark  the same tokens against the dark set; may be
     *                                    empty, in which case the class renders the same
     *                                    in both modes
     */
    public function __construct(
        private readonly array $light,
        private readonly array $dark = [],
    ) {
    }

    public function resolveLight(string $token): ?string
    {
        return $this->light[$token] ?? null;
    }

    public function resolveDark(string $token): ?string
    {
        return $this->dark[$token] ?? null;
    }
}
