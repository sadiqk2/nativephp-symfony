<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Ui\Style;

/**
 * Parses a Tailwind class string into the canonical style map described in
 * NATIVE-UI-CONTRACT.md §4d.
 *
 * The output vocabulary is camelCase and deliberately *not* the wire format:
 * `{flexGrow: 1, paddingLeft: 12, bg: '#0F172A', fontSize: 24}`. The element setters do the
 * translation to snake_case wire keys, which is why grepping for a key map finds nothing.
 * Enums arrive as the integers the renderers switch on, shadows become `elevation`, and
 * `w-full` becomes a boolean `fillWidth` rather than a width — those are the renderers'
 * conventions, not ours, and they are what makes upstream's Kotlin and Swift usable
 * unchanged.
 *
 * This is a reimplementation of upstream's `Native\Mobile\Edge\TailwindParser`, verified
 * token-for-token against a fixture generated from it (`tests/fixtures/tailwind-corpus.json`:
 * 684 tokens and 1,336 class strings measured over upstream's kitchen-sink demo). Two
 * structural facts from that measurement shape the code:
 *
 *  1. Parsing is a **pure per-token function plus exactly one merge rule** — the `dark` and
 *     `gradient` sub-arrays must be deep-merged. A flat `array_merge` diverges on 27 of the
 *     1,336 class strings, every one of them silently dropping a `dark` key.
 *  2. Everything else is table lookup. There is no arithmetic anywhere in the scales: the
 *     tables *are* the specification (Tailwind's own palette and scale), which is why
 *     copying them is not a dependency on someone else's internals.
 *
 * Differences from upstream, all deliberate:
 *
 *  - **No static state.** Upstream keeps the theme resolvers, the platform and a parse cache
 *    in static properties. In a long-running Symfony worker that is a shared mutable
 *    singleton and an unbounded cache keyed by author input, so the resolver and platform are
 *    constructor state on an immutable service and there is no cache — per-token dispatch is
 *    a handful of string comparisons, and callers that need memoisation can wrap this.
 *  - **One bug fixed**, see {@see THEME_BORDER_WIDTH}.
 *  - Diagnostics are returned to the caller instead of being logged from inside the parser.
 *
 * Upstream quirks *are* reproduced, because the renderers and the demo templates depend on
 * them. Each one is flagged at the point it happens so a reader does not mistake it for a
 * bug of ours.
 */
final class StyleParser
{
    /**
     * Tailwind's spacing scale: the shared value table behind padding, margin, gap, sizing
     * and insets.
     *
     * A **whitelist, not a formula.** Every entry except `px` is `4 × n`, so it is tempting
     * to compute it — but Tailwind only generates the utilities in its own scale, so `p-13`
     * is not a class and must drop rather than silently become 52. Arithmetic here would
     * quietly accept typos and invent geometry the author never wrote; authors who really
     * want 52 have the arbitrary form `p-[52]`.
     *
     * @var array<string, int>
     */
    private const SPACING = [
        '0' => 0, 'px' => 1, '0.5' => 2, '1' => 4, '1.5' => 6, '2' => 8, '2.5' => 10,
        '3' => 12, '3.5' => 14, '4' => 16, '5' => 20, '6' => 24, '7' => 28, '8' => 32,
        '9' => 36, '10' => 40, '11' => 44, '12' => 48, '14' => 56, '16' => 64, '20' => 80,
        '24' => 96, '28' => 112, '32' => 128, '36' => 144, '40' => 160, '44' => 176,
        '48' => 192, '52' => 208, '56' => 224, '60' => 240, '64' => 256, '72' => 288,
        '80' => 320, '96' => 384,
    ];

    /** @var array<string, int> */
    private const FONT_SIZES = [
        'xs' => 12, 'sm' => 14, 'base' => 16, 'lg' => 18, 'xl' => 20,
        '2xl' => 24, '3xl' => 30, '4xl' => 36, '5xl' => 48, '6xl' => 60,
    ];

    /**
     * Weights are the renderers' 1..7 ordinals, not CSS 100..900.
     *
     * `font-black` and `font-extralight` are real Tailwind weights with no ordinal here, so
     * they drop. Upstream's table has the same hole; extending it would put a value on the
     * wire that neither renderer maps.
     *
     * @var array<string, int>
     */
    private const FONT_WEIGHTS = [
        'thin' => 1, 'light' => 2, 'normal' => 3, 'medium' => 4,
        'semibold' => 5, 'bold' => 6, 'extrabold' => 7,
    ];

    /** @var array<string, int> */
    private const BORDER_RADIUS = [
        'none' => 0, 'sm' => 2, 'md' => 6, 'lg' => 8,
        'xl' => 12, '2xl' => 16, '3xl' => 24, 'full' => 9999,
    ];

    /**
     * Which corners each `rounded-<side>-*` suffix touches; sides expand to their two
     * corners as Tailwind's longhand does.
     *
     * Physical spellings only. The logical variants (`rounded-s-*`, `rounded-ee-*`) resolve
     * against writing direction and neither renderer flips corners for RTL, so accepting
     * them would render LTR geometry in an RTL layout. They drop into the diagnostics
     * instead.
     *
     * @var array<string, list<string>>
     */
    private const BORDER_RADIUS_CORNERS = [
        'tl' => ['borderRadiusTopLeft'],
        'tr' => ['borderRadiusTopRight'],
        'br' => ['borderRadiusBottomRight'],
        'bl' => ['borderRadiusBottomLeft'],
        't' => ['borderRadiusTopLeft', 'borderRadiusTopRight'],
        'r' => ['borderRadiusTopRight', 'borderRadiusBottomRight'],
        'b' => ['borderRadiusBottomRight', 'borderRadiusBottomLeft'],
        'l' => ['borderRadiusTopLeft', 'borderRadiusBottomLeft'],
    ];

    /** Shadows collapse to a single `elevation`, the one knob both renderers expose. @var array<string, int> */
    private const SHADOW = [
        'sm' => 1, 'md' => 6, 'lg' => 8, 'xl' => 12, '2xl' => 16, 'none' => 0,
    ];

    /**
     * Tailwind's container scale (`max-w-*`, and `min-w-*` in v4), in points at the 16px
     * root Tailwind assumes. Most are wider than any phone, but they are what authors type
     * and a constraint that never binds still beats a dropped class.
     *
     * @var array<string, int>
     */
    private const CONTAINER_SIZES = [
        '3xs' => 256, '2xs' => 288, 'xs' => 320, 'sm' => 384, 'md' => 448,
        'lg' => 512, 'xl' => 576, '2xl' => 672, '3xl' => 768, '4xl' => 896,
        '5xl' => 1024, '6xl' => 1152, '7xl' => 1280,
    ];

    /**
     * Fractional widths, as percent **strings** — the renderers read a `%` suffix as
     * "relative to parent" and a bare number as points.
     *
     * Pre-rounded to whole percents, upstream's quirk kept on purpose: `2/3` is `"67%"`,
     * not `66.667%`. Rounding at the table rather than at render time means both platforms
     * round identically, which matters more for a two-column row lining up than the third
     * decimal does.
     *
     * @var array<string, string>
     */
    private const WIDTH_FRACTIONS = [
        '1/2' => '50%', '1/3' => '33%', '2/3' => '67%',
        '1/4' => '25%', '2/4' => '50%', '3/4' => '75%',
        '1/5' => '20%', '2/5' => '40%', '3/5' => '60%', '4/5' => '80%',
        '1/6' => '17%', '5/6' => '83%',
    ];

    /**
     * Border widths are a four-value whitelist, and there is no directional width at all:
     * `border-t`, `border-x-2` and friends drop. That is a real gap against Tailwind — the
     * packed node carries one width for all four edges — and it stays a visible drop rather
     * than a silent approximation to a uniform border.
     *
     * @var array<string, int>
     */
    private const BORDER_WIDTHS = ['0' => 0, '2' => 2, '4' => 4, '8' => 8];

    /**
     * The gradient's axis: the unit vector it travels *toward*, in view space where y grows
     * downward. The native side turns it into start/end points.
     *
     * @var array<string, array{float, float}>
     */
    private const GRADIENT_DIRECTIONS = [
        't' => [0.0, -1.0],
        'b' => [0.0, 1.0],
        'l' => [-1.0, 0.0],
        'r' => [1.0, 0.0],
        'tl' => [-1.0, -1.0],
        'tr' => [1.0, -1.0],
        'bl' => [-1.0, 1.0],
        'br' => [1.0, 1.0],
    ];

    /**
     * Cross-axis alignment (`items-*`). **0 means UNSET**, which is why start is 4: a node
     * with no `items-*` class arrives as 0 and each renderer applies its own default, so
     * start cannot share that byte with "the author said nothing".
     *
     * @var array<string, int>
     */
    private const ALIGN_ITEMS = ['center' => 1, 'end' => 2, 'stretch' => 3, 'start' => 4];

    /** Same domain and same unset rule as {@see ALIGN_ITEMS}. @var array<string, int> */
    private const ALIGN_SELF = ['center' => 1, 'end' => 2, 'stretch' => 3, 'start' => 4];

    /**
     * Main-axis distribution (`justify-*`). Tailwind drops the `space-` prefix, so the keys
     * are the utility spellings rather than the CSS ones.
     *
     * @var array<string, int>
     */
    private const JUSTIFY_CONTENT = [
        'start' => 0, 'center' => 1, 'end' => 2,
        'between' => 3, 'around' => 4, 'evenly' => 5,
    ];

    /** @var array<string, int> */
    private const TEXT_ALIGN = ['left' => 0, 'center' => 1, 'right' => 2];

    /**
     * Keys a leading `-` may flip. Mirrors Tailwind: margins and insets take negatives,
     * padding / gap / sizing never do, so `-p-4` is a typo and drops instead of emitting
     * geometry nobody asked for.
     *
     * @var list<string>
     */
    private const NEGATABLE = [
        'margin', 'marginTop', 'marginRight', 'marginBottom', 'marginLeft',
        'positionTop', 'positionRight', 'positionBottom', 'positionLeft',
    ];

    /**
     * Tokens with a fixed output. Consulted first, which is safe because no token here is
     * also reachable through a prefix rule with a different meaning — and it keeps three
     * ordering constraints from mattering at all (`font-sans` before the `font-` weight
     * rule, `w-full` before `w-`, `flex-1` before nothing in particular).
     *
     * Integers are enum ordinals the renderers switch on: fontFamily 0 sans / 1 serif /
     * 2 mono; fontStyle and the decoration flags 0 off / 1 on; textTransform 0 none /
     * 1 upper / 2 lower / 3 capitalize; fit 0 none / 1 contain / 2 cover / 3 fill;
     * positionType 0 relative / 1 absolute. `tracking-*` is em, `leading-*` a unitless
     * multiple of the font size.
     *
     * @var array<string, array<string, bool|float|int>>
     */
    private const EXACT = [
        // Direction — lets any container (notably pressable, which defaults to column)
        // flip axis via class, matching web flexbox. Reverse spellings share a value
        // because the wire has no reverse flag.
        'flex-row' => ['flexDirection' => 1],
        'flex-row-reverse' => ['flexDirection' => 1],
        'flex-col' => ['flexDirection' => 0],
        'flex-col-reverse' => ['flexDirection' => 0],
        'flex-1' => ['flexGrow' => 1, 'flexShrink' => 1, 'flexBasis' => 0],
        'flex-grow' => ['flexGrow' => 1],
        'grow' => ['flexGrow' => 1],
        'flex-grow-0' => ['flexGrow' => 0],
        'grow-0' => ['flexGrow' => 0],
        'flex-shrink' => ['flexShrink' => 1],
        'shrink' => ['flexShrink' => 1],
        'flex-shrink-0' => ['flexShrink' => 0],
        'shrink-0' => ['flexShrink' => 0],
        'flex-wrap' => ['flexWrap' => 1],
        'flex-nowrap' => ['flexWrap' => 0],
        'flex-wrap-reverse' => ['flexWrap' => 2],

        'w-full' => ['fillWidth' => true],
        'h-full' => ['fillHeight' => true],

        'border' => ['borderWidth' => 1],
        'rounded' => ['borderRadius' => 4],
        'shadow' => ['elevation' => 3],

        'safe-area' => ['safeArea' => true],
        'safe-area-top' => ['safeAreaTop' => true],
        'safe-area-bottom' => ['safeAreaBottom' => true],

        'absolute' => ['positionType' => 1],
        'relative' => ['positionType' => 0],

        'font-sans' => ['fontFamily' => 0],
        'font-serif' => ['fontFamily' => 1],
        'font-mono' => ['fontFamily' => 2],

        'italic' => ['fontStyle' => 1],
        'not-italic' => ['fontStyle' => 0],

        // Independent flags, so `underline line-through` combines; `no-underline` clears both.
        'underline' => ['underline' => 1],
        'line-through' => ['lineThrough' => 1],
        'no-underline' => ['underline' => 0, 'lineThrough' => 0],

        'uppercase' => ['textTransform' => 1],
        'lowercase' => ['textTransform' => 2],
        'capitalize' => ['textTransform' => 3],
        'normal-case' => ['textTransform' => 0],

        // Opt-in text selection, container-scoped on both platforms.
        'select-text' => ['selectable' => 1],
        'select-none' => ['selectable' => 0],

        'tracking-tighter' => ['letterSpacing' => -0.05],
        'tracking-tight' => ['letterSpacing' => -0.025],
        'tracking-normal' => ['letterSpacing' => 0],
        'tracking-wide' => ['letterSpacing' => 0.025],
        'tracking-wider' => ['letterSpacing' => 0.05],
        'tracking-widest' => ['letterSpacing' => 0.1],

        'leading-none' => ['lineHeight' => 1.0],
        'leading-tight' => ['lineHeight' => 1.25],
        'leading-snug' => ['lineHeight' => 1.375],
        'leading-normal' => ['lineHeight' => 1.5],
        'leading-relaxed' => ['lineHeight' => 1.625],
        'leading-loose' => ['lineHeight' => 2.0],

        'object-none' => ['fit' => 0],
        'object-contain' => ['fit' => 1],
        'object-cover' => ['fit' => 2],
        'object-fill' => ['fit' => 3],
        'object-scale-down' => ['fit' => 1],

        'aspect-square' => ['aspectRatio' => 1.0],
        'aspect-video' => ['aspectRatio' => 16 / 9],
    ];

    /**
     * Internal marker for the width a `border-theme-*` class implies.
     *
     * **This is where we diverge from upstream on purpose.** Upstream's theme-border branch
     * emits `borderWidth: 1` unconditionally, so `border-2 border-theme-outline` renders a
     * 1pt border — the theme class clobbers the explicit width that precedes it, while the
     * reverse order works. The pattern occurs twice in upstream's own demo, rendering 1pt
     * where it asks for 2pt. Here the implied width is carried under this key and applied
     * only when no explicit `border-<n>` has been seen, so both orderings give 2.
     *
     * It is a *default*, not a no-op: a bare `border-theme-outline` still has to produce a
     * visible border, since a colour with zero width paints nothing.
     */
    private const THEME_BORDER_WIDTH = "\0defaultBorderWidth";

    /**
     * @param ThemeColorResolverInterface|null $themeColors resolves `bg-/text-/border-theme-*`;
     *                                                      without one those classes drop
     *                                                      (31 of the 684 corpus tokens,
     *                                                      9.7% of its uses)
     * @param string|null                      $platform    `'ios'`, `'android'`, or null for
     *                                                      "neither", which drops every
     *                                                      platform-variant class
     */
    public function __construct(
        private readonly ?ThemeColorResolverInterface $themeColors = null,
        private readonly ?string $platform = null,
    ) {
    }

    /**
     * The platform is per-parse in effect but per-instance in storage, so a caller that
     * renders for one device does not have to thread it through every call.
     */
    public function withPlatform(?string $platform): self
    {
        return new self($this->themeColors, $platform);
    }

    public function withThemeColors(?ThemeColorResolverInterface $themeColors): self
    {
        return new self($themeColors, $this->platform);
    }

    /**
     * Parse a whole class attribute.
     *
     * Whitespace-separated, order-significant only where two tokens write the same key
     * (later wins, as in a stylesheet). Unknown tokens are skipped, never fatal: authors
     * paste class strings from web templates and a screen that renders unstyled is far more
     * debuggable than one that 500s.
     *
     * @param list<string>|null $droppedClasses out-param for diagnostics — the tokens that
     *                                          produced nothing. Platform variants aimed at
     *                                          the *other* platform are excluded: they are
     *                                          intentional no-ops, not typos.
     *
     * @return array<string, mixed> camelCase style map; `dark` and `gradient` are nested
     */
    public function parse(string $classString, ?array &$droppedClasses = null): array
    {
        $result = [];
        $dropped = [];

        foreach (preg_split('/\s+/', trim($classString), -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            $parsed = $this->parseToken($token);

            if (null === $parsed) {
                if (!$this->isInactivePlatformVariant($token)) {
                    $dropped[$token] = true;
                }

                continue;
            }

            // THE merge rule. `dark` and `gradient` are the only nested keys, and both are
            // accumulated across several classes: `bg-theme-surface` and
            // `border-theme-outline` each contribute one `dark` key, and a gradient's
            // direction and its stops arrive as three or four separate classes. A flat
            // array_merge would let the last of them erase the rest — measured at 27
            // divergences over the 1,336-string corpus, every one a lost `dark` key.
            foreach (['dark', 'gradient'] as $nested) {
                if (isset($parsed[$nested])) {
                    // `dark:border-theme-outline` puts a themed border inside the companion,
                    // so the implied-width rule has to resolve against the dark scope too —
                    // otherwise the marker would leak onto the wire.
                    if ('dark' === $nested) {
                        $parsed[$nested] = $this->resolveImpliedBorderWidth($parsed[$nested], $result[$nested] ?? []);
                    }

                    $result[$nested] = isset($result[$nested])
                        ? array_merge($result[$nested], $parsed[$nested])
                        : $parsed[$nested];
                    unset($parsed[$nested]);
                }
            }

            $parsed = $this->resolveImpliedBorderWidth($parsed, $result);

            $result = array_merge($result, $parsed);
        }

        $droppedClasses = array_keys($dropped);

        return $result;
    }

    /**
     * Turn the `border-theme-*` width marker into a real `borderWidth`, unless an explicit
     * one is already in the result. Keeps the key in place rather than appending it, so the
     * output key order is unaffected — element hashes are computed over key order, so a
     * reordering here would look like a content change to the renderer.
     *
     * @param array<string, mixed> $parsed
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function resolveImpliedBorderWidth(array $parsed, array $result): array
    {
        if (!\array_key_exists(self::THEME_BORDER_WIDTH, $parsed)) {
            return $parsed;
        }

        $rewritten = [];

        foreach ($parsed as $key => $value) {
            if (self::THEME_BORDER_WIDTH !== $key) {
                $rewritten[$key] = $value;
            } elseif (!\array_key_exists('borderWidth', $result)) {
                $rewritten['borderWidth'] = $value;
            }
        }

        return $rewritten;
    }

    /**
     * Platform variants targeting another platform are intentional no-ops rather than
     * unsupported utilities. A malformed class naming both targets is still reported when
     * one of them matches.
     */
    private function isInactivePlatformVariant(string $token): bool
    {
        $targets = array_intersect(explode(':', $token), ['ios', 'android']);

        return [] !== $targets && !\in_array($this->platform, $targets, true);
    }

    /**
     * One token → its style map, or null when the token is not in the vocabulary.
     *
     * Pure: same input, same output, no dependence on the tokens around it. That is what
     * makes {@see parse()} a fold, and what the fixture's 684 per-token assertions pin.
     *
     * @return array<string, mixed>|null
     */
    private function parseToken(string $token): ?array
    {
        // The `/N` opacity modifier is pulled off before dispatch so every colour rule
        // beneath — palette, hex, theme, named — gets it for free. Only colour-bearing
        // prefixes are eligible: `w-1/2` uses the slash for a fraction.
        [$token, $alphaHex] = $this->extractColorAlpha($token);

        $result = $this->dispatch($token);

        if (null !== $result && null !== $alphaHex) {
            $result = $this->applyAlpha($result, $alphaHex);
        }

        return $result;
    }

    /**
     * The dispatch table. Order matters in five places, all called out below; everything
     * else is independent and grouped for reading.
     *
     * @return array<string, mixed>|null
     */
    private function dispatch(string $token): ?array
    {
        if (isset(self::EXACT[$token])) {
            return self::EXACT[$token];
        }

        // Variants recurse rather than getting their own rule set, so `dark:` composes with
        // every rule below for free — including `android:dark:bg-white`, since the recursion
        // re-enters at the top. A platform variant for the other platform is a silent no-op,
        // which leaves any unprefixed class of the same kind to win.
        if (str_starts_with($token, 'ios:')) {
            return 'ios' === $this->platform ? $this->parseToken(substr($token, 4)) : null;
        }

        if (str_starts_with($token, 'android:')) {
            return 'android' === $this->platform ? $this->parseToken(substr($token, 8)) : null;
        }

        if (str_starts_with($token, 'dark:')) {
            $inner = $this->parseToken(substr($token, 5));

            return null === $inner ? null : ['dark' => $inner];
        }

        // Negatives are the positive form with the sign flipped. AFTER the variants so
        // `ios:-right-8` works, BEFORE arbitrary values so `-left-[12]` does too.
        if (str_starts_with($token, '-')) {
            return $this->negate($this->dispatch(substr($token, 1)));
        }

        // Arbitrary values: `prefix-[value]`. A token containing a bracket is never
        // anything else, so a malformed one (`bg-[`, `text-[]]0`) drops here rather than
        // falling through to a rule that would misread it.
        if (str_contains($token, '[')) {
            return preg_match('/^(.+?)-\[([^\]]+)\]$/', $token, $m)
                ? $this->parseArbitrary($m[1], $m[2])
                : null;
        }

        return match (true) {
            // Liquid Glass material — a bitmask, and the only non-table rule here.
            'glass' === $token || str_starts_with($token, 'glass:') => $this->parseGlass($token),

            // Padding / margin. The axis and side rules must precede the uniform one,
            // which would otherwise match `px-3` as the value "x-3".
            str_starts_with($token, 'px-') => $this->spacingAxis('padding', 'x', substr($token, 3)),
            str_starts_with($token, 'py-') => $this->spacingAxis('padding', 'y', substr($token, 3)),
            str_starts_with($token, 'pt-') => $this->spacing('paddingTop', substr($token, 3)),
            str_starts_with($token, 'pr-') => $this->spacing('paddingRight', substr($token, 3)),
            str_starts_with($token, 'pb-') => $this->spacing('paddingBottom', substr($token, 3)),
            str_starts_with($token, 'pl-') => $this->spacing('paddingLeft', substr($token, 3)),
            str_starts_with($token, 'p-') => $this->spacing('padding', substr($token, 2)),

            str_starts_with($token, 'mx-') => $this->spacingAxis('margin', 'x', substr($token, 3)),
            str_starts_with($token, 'my-') => $this->spacingAxis('margin', 'y', substr($token, 3)),
            str_starts_with($token, 'mt-') => $this->spacing('marginTop', substr($token, 3)),
            str_starts_with($token, 'mr-') => $this->spacing('marginRight', substr($token, 3)),
            str_starts_with($token, 'mb-') => $this->spacing('marginBottom', substr($token, 3)),
            str_starts_with($token, 'ml-') => $this->spacing('marginLeft', substr($token, 3)),
            str_starts_with($token, 'm-') => $this->spacing('margin', substr($token, 2)),

            // Sizing. The constraints are grouped ahead of the bare `w-`/`h-` rules for
            // readability; they do not actually collide (`max-w-4` does not start with `w-`).
            str_starts_with($token, 'gap-') => $this->spacing('gap', substr($token, 4)),
            str_starts_with($token, 'min-w-') => $this->sizeConstraint('minWidth', substr($token, 6)),
            str_starts_with($token, 'max-w-') => $this->sizeConstraint('maxWidth', substr($token, 6)),
            str_starts_with($token, 'min-h-') => $this->sizeConstraint('minHeight', substr($token, 6)),
            str_starts_with($token, 'max-h-') => $this->sizeConstraint('maxHeight', substr($token, 6)),
            str_starts_with($token, 'w-') => $this->parseWidth(substr($token, 2)),
            str_starts_with($token, 'h-') => $this->spacing('height', substr($token, 2)),

            // Insets. `inset-x-`/`inset-y-` must precede the bare `inset-`, which would
            // otherwise read their axis letter as part of the value.
            str_starts_with($token, 'inset-x-') => $this->explicitInsets($this->parseInset(substr($token, 8), ['positionLeft', 'positionRight'])),
            str_starts_with($token, 'inset-y-') => $this->explicitInsets($this->parseInset(substr($token, 8), ['positionTop', 'positionBottom'])),
            str_starts_with($token, 'inset-') => $this->explicitInsets($this->parseInset(substr($token, 6), ['positionTop', 'positionRight', 'positionBottom', 'positionLeft'])),
            str_starts_with($token, 'left-') => $this->explicitInsets($this->spacing('positionLeft', substr($token, 5))),
            str_starts_with($token, 'top-') => $this->explicitInsets($this->spacing('positionTop', substr($token, 4))),
            str_starts_with($token, 'right-') => $this->explicitInsets($this->spacing('positionRight', substr($token, 6))),
            str_starts_with($token, 'bottom-') => $this->explicitInsets($this->spacing('positionBottom', substr($token, 7))),

            // Theme tokens must precede the palette rules, or `bg-theme-primary` reaches
            // the palette lookup as the family "theme".
            str_starts_with($token, 'bg-theme-') => $this->themeColor('bg', substr($token, 9)),
            str_starts_with($token, 'text-theme-') => $this->themeColor('color', substr($token, 11)),
            str_starts_with($token, 'border-theme-') => $this->themeColor('borderColor', substr($token, 13)),

            // Gradients must precede the generic `bg-` rule, which would otherwise try to
            // resolve "gradient-to-t" as a colour. `bg-linear-*` is the v4 spelling.
            str_starts_with($token, 'bg-gradient-to-') => $this->gradientDirection(substr($token, 15)),
            str_starts_with($token, 'bg-linear-to-') => $this->gradientDirection(substr($token, 13)),
            str_starts_with($token, 'from-') => $this->gradientStop('from', substr($token, 5)),
            str_starts_with($token, 'via-') => $this->gradientStop('via', substr($token, 4)),
            str_starts_with($token, 'to-') => $this->gradientStop('to', substr($token, 3)),

            str_starts_with($token, 'bg-') => $this->parseColor('bg', substr($token, 3)),
            str_starts_with($token, 'text-') => $this->parseText(substr($token, 5)),
            str_starts_with($token, 'font-') => $this->lookup('fontWeight', self::FONT_WEIGHTS, substr($token, 5)),

            str_starts_with($token, 'border-') => $this->parseBorder(substr($token, 7)),
            str_starts_with($token, 'rounded-') => $this->parseRounded(substr($token, 8)),
            str_starts_with($token, 'shadow-') => $this->lookup('elevation', self::SHADOW, substr($token, 7)),
            str_starts_with($token, 'opacity-') => $this->parseOpacity(substr($token, 8)),

            str_starts_with($token, 'items-') => $this->lookup('alignItems', self::ALIGN_ITEMS, substr($token, 6)),
            str_starts_with($token, 'justify-') => $this->lookup('justifyContent', self::JUSTIFY_CONTENT, substr($token, 8)),
            str_starts_with($token, 'self-') => $this->lookup('alignSelf', self::ALIGN_SELF, substr($token, 5)),

            default => null,
        };
    }

    /**
     * Pull a trailing `/N` (or `/[N]`) opacity modifier off a colour class.
     *
     * @return array{0: string, 1: ?string} the stripped token and a two-char alpha byte
     */
    private function extractColorAlpha(string $token): array
    {
        if (!str_starts_with($token, 'bg-') && !str_starts_with($token, 'text-') && !str_starts_with($token, 'border-')) {
            return [$token, null];
        }

        $slashPos = strrpos($token, '/');

        if (false === $slashPos) {
            return [$token, null];
        }

        $alphaHex = ColorGrammar::opacityToAlphaHex(substr($token, $slashPos + 1));

        // A slash followed by something non-numeric is not an alpha modifier; leave the
        // token intact so the rules below see it whole.
        return null === $alphaHex ? [$token, null] : [substr($token, 0, $slashPos), $alphaHex];
    }

    /**
     * Push an alpha byte into every hex value of a parsed token, recursing into `dark` so a
     * theme token gets both modes modified. Non-colour values pass through untouched, so
     * this is safe to run over any result.
     *
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function applyAlpha(array $result, string $alphaHex): array
    {
        foreach ($result as $key => $value) {
            if ('dark' === $key && \is_array($value)) {
                $result[$key] = $this->applyAlpha($value, $alphaHex);
            } elseif (\is_string($value)) {
                // Overwrites any alpha the value already carried: `bg-[#12345678]/50` is
                // the author saying 50% last.
                $result[$key] = ColorGrammar::withAlpha($value, $alphaHex);
            }
        }

        return $result;
    }

    /**
     * @param array<string, bool|float|int|string> $table
     *
     * @return array<string, bool|float|int|string>|null
     */
    private function lookup(string $key, array $table, string $value): ?array
    {
        return isset($table[$value]) ? [$key => $table[$value]] : null;
    }

    /** @return array<string, int>|null */
    private function spacing(string $key, string $value): ?array
    {
        return $this->lookup($key, self::SPACING, $value);
    }

    /** @return array<string, int>|null */
    private function spacingAxis(string $prop, string $axis, string $value): ?array
    {
        if (!isset(self::SPACING[$value])) {
            return null;
        }

        $v = self::SPACING[$value];

        return 'x' === $axis
            ? [$prop.'Left' => $v, $prop.'Right' => $v]
            : [$prop.'Top' => $v, $prop.'Bottom' => $v];
    }

    /** @return array<string, int|string>|null */
    private function parseWidth(string $value): ?array
    {
        return $this->lookup('width', self::WIDTH_FRACTIONS, $value)
            ?? $this->spacing('width', $value);
    }

    /**
     * `min-w-*` / `max-w-*` / `min-h-*` / `max-h-*`: the spacing scale, plus the container
     * scale for the width constraints, plus `none` (which the wire's 0 already means).
     *
     * `full` / `screen*` / `fit` / `min` / `max` are deliberately not accepted — the packed
     * node carries min/max as bare floats with no companion size mode, so there is nowhere
     * to put "100% of the parent". Dropping them is visible; accepting them would silently
     * do nothing.
     *
     * @return array<string, int>|null
     */
    private function sizeConstraint(string $key, string $value): ?array
    {
        if ('none' === $value) {
            return [$key => 0];
        }

        if (isset(self::SPACING[$value])) {
            return [$key => self::SPACING[$value]];
        }

        return \in_array($key, ['maxWidth', 'minWidth'], true)
            ? $this->lookup($key, self::CONTAINER_SIZES, $value)
            : null;
    }

    /**
     * @param list<string> $edges
     *
     * @return array<string, int>|null
     */
    private function parseInset(string $value, array $edges): ?array
    {
        if (!isset(self::SPACING[$value])) {
            return null;
        }

        return array_fill_keys($edges, self::SPACING[$value]);
    }

    /**
     * Mark an explicitly authored zero inset as IEEE -0.0.
     *
     * The packed node has no spare byte to distinguish "unset" from "explicit 0", so +0.0
     * means unset and the sign bit carries "the author wrote `bottom-0`" — which must anchor
     * to the bottom edge instead of falling through to the top default. -0.0 survives the
     * f32 wire bit-exactly and the native layout tests `!= 0 || signbit`.
     *
     * @param array<string, mixed>|null $parsed
     *
     * @return array<string, mixed>|null
     */
    private function explicitInsets(?array $parsed): ?array
    {
        if (null === $parsed) {
            return null;
        }

        foreach ($parsed as $key => $value) {
            if (0 === $value || 0.0 === $value) {
                $parsed[$key] = -0.0;
            }
        }

        return $parsed;
    }

    /**
     * Flip the sign of a parsed spacing result, or reject it: null in, or any key outside
     * {@see NEGATABLE}, means the negative spelling was a typo.
     *
     * @param array<string, mixed>|null $parsed
     *
     * @return array<string, float>|null
     */
    private function negate(?array $parsed): ?array
    {
        if (null === $parsed || [] === $parsed) {
            return null;
        }

        $negated = [];

        foreach ($parsed as $key => $value) {
            if (!\in_array($key, self::NEGATABLE, true) || !is_numeric($value)) {
                return null;
            }

            $negated[$key] = -(float) $value;
        }

        return $negated;
    }

    /**
     * `glass`, with modifiers chained after colons in any order:
     * `glass:prominent` (button-only), `glass:interactive`, `glass:clear`, and combinations.
     *
     * Packed into one int in the existing `glass` prop slot so no new wire keys are needed:
     * bit 0 enabled, bit 1 prominent, bit 2 interactive, bit 3 clear. Unknown segments are
     * ignored rather than failing the token — a typo should not cost the base glass effect.
     *
     * @return array{glass: int}
     */
    private function parseGlass(string $token): array
    {
        $flags = 1;

        foreach (\array_slice(explode(':', $token), 1) as $modifier) {
            $flags |= match ($modifier) {
                'prominent' => 2,
                'interactive' => 4,
                'clear' => 8,
                default => 0,
            };
        }

        return ['glass' => $flags];
    }

    /** @return array<string, string>|null */
    private function parseColor(string $key, string $value): ?array
    {
        $hex = ColorGrammar::namedHex($value) ?? ColorGrammar::paletteHex($value);

        return null === $hex ? null : [$key => $hex];
    }

    /**
     * `text-*` is three rules sharing a prefix: font size, alignment, colour. Sizes first
     * because they are the most used, and none of the three vocabularies overlap.
     *
     * @return array<string, int|string>|null
     */
    private function parseText(string $value): ?array
    {
        return $this->lookup('fontSize', self::FONT_SIZES, $value)
            ?? $this->lookup('textAlign', self::TEXT_ALIGN, $value)
            ?? $this->parseColor('color', $value);
    }

    /** @return array<string, int|string>|null */
    private function parseBorder(string $value): ?array
    {
        return $this->lookup('borderWidth', self::BORDER_WIDTHS, $value)
            ?? $this->parseColor('borderColor', $value);
    }

    /**
     * `bg-theme-*` / `text-theme-*` / `border-theme-*`.
     *
     * Emits the light hex plus, when the theme defines a different dark one, a `dark`
     * companion the collector turns into `dark_bg_color` / `dark_color` /
     * `dark_border_color` for the native side to pick at draw time. Identical light and dark
     * hexes emit no companion — the class then behaves the same in both modes, which is also
     * what happens when the theme has no dark set at all.
     *
     * An unresolvable token returns null and lands in the diagnostics, by design: a theme
     * typo (upstream's own demo contains `text-theme-shane`) should be reportable, not
     * silently rendered as some fallback colour.
     *
     * @return array<string, mixed>|null
     */
    private function themeColor(string $key, string $token): ?array
    {
        $light = $this->themeColors?->resolveLight($token);

        if (null === $light) {
            return null;
        }

        $out = [$key => $light];

        // A colour with no width paints nothing, so a theme border implies one — but only
        // as a default. See THEME_BORDER_WIDTH for the upstream bug this avoids.
        if ('borderColor' === $key) {
            $out[self::THEME_BORDER_WIDTH] = 1;
        }

        $dark = $this->themeColors?->resolveDark($token);

        if (null !== $dark && $dark !== $light) {
            $out['dark'] = [$key => $dark];
        }

        return $out;
    }

    /** @return array{gradient: array{direction: array{float, float}}}|null */
    private function gradientDirection(string $edge): ?array
    {
        return isset(self::GRADIENT_DIRECTIONS[$edge])
            ? ['gradient' => ['direction' => self::GRADIENT_DIRECTIONS[$edge]]]
            : null;
    }

    /**
     * `from-black`, `via-black/10`, `to-transparent` — one colour stop, accepting the whole
     * colour grammar plus `theme-<token>`.
     *
     * A stop without a direction is inert rather than wrong: the native side paints only
     * once it has an axis and at least two stops.
     *
     * @return array{gradient: array<string, string>}|null
     */
    private function gradientStop(string $position, string $value): ?array
    {
        $hex = str_starts_with($value, 'theme-')
            ? $this->themeColors?->resolveLight(substr($value, 6))
            : ColorGrammar::resolveValue($value);

        return null === $hex ? null : ['gradient' => [$position => $hex]];
    }

    /**
     * `rounded-*`, uniform / per-side / per-corner.
     *
     * The scale keys carry no dashes, so a dash unambiguously separates a side from its
     * size: `2xl` is uniform, `br-none` is one corner. A bare side (`rounded-t`) takes the
     * same 4pt default as a bare `rounded`.
     *
     * Corner keys are emitted *alongside* any uniform `borderRadius` rather than folded into
     * it, so `rounded-2xl rounded-br-none` keeps both and the collector resolves precedence.
     * That makes the result independent of the order the two classes appear in — matching
     * Tailwind, where the longhand always follows the shorthand in the generated stylesheet
     * however the author ordered the attribute.
     *
     * @return array<string, int>|null
     */
    private function parseRounded(string $value): ?array
    {
        if (isset(self::BORDER_RADIUS[$value])) {
            return ['borderRadius' => self::BORDER_RADIUS[$value]];
        }

        [$side, $size] = array_pad(explode('-', $value, 2), 2, null);

        $corners = self::BORDER_RADIUS_CORNERS[$side] ?? null;
        $radius = null === $size ? 4 : (self::BORDER_RADIUS[$size] ?? null);

        return null === $corners || null === $radius ? null : array_fill_keys($corners, $radius);
    }

    /**
     * `opacity-<n>`, as a 0..1 fraction.
     *
     * Unclamped, upstream's behaviour kept: `opacity-200` is 2.0. Both renderers clamp on
     * their side, and clamping here would hide the class that made no sense.
     *
     * @return array{opacity: float}|null
     */
    private function parseOpacity(string $value): ?array
    {
        return is_numeric($value) ? ['opacity' => (float) $value / 100] : null;
    }

    /**
     * Arbitrary values, `prefix-[value]`.
     *
     * Every numeric form is a bare `(float)` cast — points, no unit handling. That is why
     * `w-[50%]` becomes `width: 50` *points*: the cast stops at the `%`. Upstream does the
     * same and it is a genuine trap next to `w-1/2`, which is `"50%"` — but the renderers
     * receive the same numbers either way, so changing it here would only move the surprise.
     * Percent-width authors want the fraction spelling.
     *
     * @return array<string, mixed>|null
     */
    private function parseArbitrary(string $prefix, string $value): ?array
    {
        $isColor = str_starts_with($value, '#');

        return match ($prefix) {
            'p' => ['padding' => (float) $value],
            'px' => ['paddingLeft' => (float) $value, 'paddingRight' => (float) $value],
            'py' => ['paddingTop' => (float) $value, 'paddingBottom' => (float) $value],
            'pt' => ['paddingTop' => (float) $value],
            'pr' => ['paddingRight' => (float) $value],
            'pb' => ['paddingBottom' => (float) $value],
            'pl' => ['paddingLeft' => (float) $value],
            'm' => ['margin' => (float) $value],
            'mx' => ['marginLeft' => (float) $value, 'marginRight' => (float) $value],
            'my' => ['marginTop' => (float) $value, 'marginBottom' => (float) $value],
            'mt' => ['marginTop' => (float) $value],
            'mr' => ['marginRight' => (float) $value],
            'mb' => ['marginBottom' => (float) $value],
            'ml' => ['marginLeft' => (float) $value],
            'gap' => ['gap' => (float) $value],
            'w' => ['width' => (float) $value],
            'h' => ['height' => (float) $value],
            'min-w' => ['minWidth' => (float) $value],
            'max-w' => ['maxWidth' => (float) $value],
            'min-h' => ['minHeight' => (float) $value],
            'max-h' => ['maxHeight' => (float) $value],
            'top' => ['positionTop' => (float) $value],
            'right' => ['positionRight' => (float) $value],
            'bottom' => ['positionBottom' => (float) $value],
            'left' => ['positionLeft' => (float) $value],
            'opacity' => ['opacity' => (float) $value],
            'rounded' => ['borderRadius' => (float) $value],
            // `rounded-br-[4]`. The arbitrary regex is non-greedy up to the final `-[`, so
            // the whole `rounded-<side>` arrives as the prefix.
            'rounded-tl', 'rounded-tr', 'rounded-br', 'rounded-bl',
            'rounded-t', 'rounded-r', 'rounded-b', 'rounded-l' => $this->arbitraryRounded(substr($prefix, 8), (float) $value),
            'bg' => $isColor ? $this->arbitraryColor('bg', $value) : null,
            // The one prefix whose arbitrary value is overloaded: a hex is a colour, a
            // number is a font size.
            'text' => $isColor ? $this->arbitraryColor('color', $value) : ['fontSize' => (float) $value],
            'border' => $isColor ? $this->arbitraryColor('borderColor', $value) : ['borderWidth' => (float) $value],
            'aspect' => ['aspectRatio' => $this->parseRatio($value)],
            // `leading-[24px]` is absolute, `leading-[1.4]` a multiplier of the font size —
            // different wire keys, so the unit has to be inspected.
            'leading' => str_ends_with($value, 'px')
                ? ['lineHeightPx' => (float) substr($value, 0, -2)]
                : (is_numeric($value) ? ['lineHeight' => (float) $value] : null),
            default => null,
        };
    }

    /** @return array<string, float>|null */
    private function arbitraryRounded(string $side, float $radius): ?array
    {
        $corners = self::BORDER_RADIUS_CORNERS[$side] ?? null;

        return null === $corners ? null : array_fill_keys($corners, $radius);
    }

    /** @return array<string, string>|null */
    private function arbitraryColor(string $key, string $value): ?array
    {
        $hex = ColorGrammar::normalizeHex($value);

        return null === $hex ? null : [$key => $hex];
    }

    /**
     * `aspect-[16/9]` or `aspect-[1.5]`. A plain cast cannot do the ratio form — PHP stops
     * at the slash and `"16/9"` would become 16.0. Malformed input gives 0.0, which the
     * native aspect-ratio modifiers ignore.
     */
    private function parseRatio(string $value): float
    {
        if (!str_contains($value, '/')) {
            return (float) $value;
        }

        [$w, $h] = array_pad(explode('/', $value, 2), 2, '1');
        $h = (float) $h;

        return 0.0 !== $h ? (float) $w / $h : 0.0;
    }
}
