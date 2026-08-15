<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Ui\Style;

use Native\Symfony\Mobile\Ui\Element;

/**
 * Routes `StyleParser` output onto an element.
 *
 * This is the piece without which the parser is decorative. `StyleParser` emits a flat
 * **camelCase** intermediate vocabulary (`flexGrow`, `paddingLeft`, `bg`), and the wire
 * wants **snake_case** keys partitioned across three separate buckets — `layout` for the
 * Yoga node, `style` for the view, `props` for the widget. Handing parser output straight
 * to `Element::layout()` puts camelCase keys on the wire, where every renderer silently
 * ignores them: the classes appear to do nothing and there is no error anywhere.
 *
 * Upstream splits this across `Element::class()`, three static
 * `NativeElementCollector::apply*` dispatchers, and one setter per property. The mapping
 * below was derived by running those dispatchers over every key the parser can emit and
 * observing where each landed, rather than by reading them — see the table in
 * NATIVE-UI-CONTRACT.md §4d.
 */
final class StyleApplier
{
    /**
     * Layout keys, camelCase in → snake_case out. Values pass through untouched.
     *
     * @var array<string, string>
     */
    private const LAYOUT = [
        'alignItems' => 'align_items',
        'alignSelf' => 'align_self',
        'justifyContent' => 'justify_content',
        'flexGrow' => 'flex_grow',
        'flexShrink' => 'flex_shrink',
        'flexWrap' => 'flex_wrap',
        'flexDirection' => 'flex_direction',
        'flexBasis' => 'flex_basis',
        'gap' => 'gap',
        'width' => 'width',
        'height' => 'height',
        'minWidth' => 'min_width',
        'maxWidth' => 'max_width',
        'minHeight' => 'min_height',
        'maxHeight' => 'max_height',
        'positionType' => 'position_type',
        'aspectRatio' => 'aspect_ratio',
        'overflow' => 'overflow',
    ];

    /**
     * How each wire key is typed, taken from upstream's applyLayout/applyStyle.
     *
     * The renderers read these through getFloat/getInt, so an int where upstream
     * sends a float most likely coerces — but "most likely" is not a contract, and
     * the C serialiser that decides is not in this checkout. Matching upstream
     * exactly costs one cast and turns the parity test into a strict equality that
     * catches the next drift for free.
     *
     * @var array<string, string>
     */
    private const CASTS = [
        'aspect_ratio' => 'float',
        'flex_basis' => 'float',
        'flex_direction' => 'int',
        'flex_grow' => 'float',
        'flex_shrink' => 'float',
        'flex_wrap' => 'int',
        'gap' => 'float',
        'max_height' => 'float',
        'max_width' => 'float',
        'min_height' => 'float',
        'min_width' => 'float',
        'position_type' => 'int',
        // 'fill' is a legal value for both, and cast() passes non-numerics through.
        'width' => 'float',
        'height' => 'float',
        'border_radius' => 'float',
        'border_width' => 'float',
        'elevation' => 'float',
        'opacity' => 'float',
    ];

    /**
     * Properties that belong to any element, handled before the per-key dispatch.
     *
     * @var array<string, true>
     */
    private const UNIVERSAL_PROPS = ['selectable' => true, 'glass' => true];

    /** @var array<string, string> */
    private const STYLE = [
        // Not a rename to snake_case but a rename outright: the parser calls it `bg`
        // and the wire calls it `bg_color`.
        'bg' => 'bg_color',
        'borderRadius' => 'border_radius',
        'borderWidth' => 'border_width',
        'borderColor' => 'border_color',
        'elevation' => 'elevation',
        'opacity' => 'opacity',
    ];

    /**
     * Element-specific properties. These are not universal — a `font_size` on a column
     * means nothing — so they are only applied to an element that declares a setter for
     * them, and silently skipped otherwise, which is how upstream's per-element
     * `applyAttributes()` behaves.
     *
     * @var array<string, string>
     */
    private const ELEMENT_PROPS = [
        'fontSize' => 'fontSize',
        'fontWeight' => 'fontWeight',
        'fontFamily' => 'fontFamily',
        'fontStyle' => 'fontStyle',
        'color' => 'color',
        'textAlign' => 'textAlign',
        'textTransform' => 'textTransform',
        'maxLines' => 'maxLines',
        'letterSpacing' => 'letterSpacing',
        'lineHeight' => 'lineHeight',
        'underline' => 'underline',
        'lineThrough' => 'lineThrough',
        'fit' => 'fit',
        'glass' => 'glass',
    ];

    /** Directional edges, in the order the wire expects a four-tuple: top, right, bottom, left. */
    private const EDGES = ['Top', 'Right', 'Bottom', 'Left'];

    public function __construct(private readonly StyleParser $parser = new StyleParser())
    {
    }

    /**
     * Parse a class string and apply it.
     *
     * @return list<string> Classes the parser did not recognise, so a caller can surface
     *                      them rather than wonder why nothing happened
     */
    public function applyClasses(Element $element, string $classes): array
    {
        $dropped = [];
        $this->apply($element, $this->parser->parse($classes, $dropped));

        return $dropped;
    }

    /**
     * Apply already-parsed output.
     *
     * @param array<string, mixed> $parsed
     */
    public function apply(Element $element, array $parsed): void
    {
        $layout = [];
        $style = [];
        $props = [
            ...$this->cornerRadiusProps($parsed),
            ...$this->darkProps($parsed),
        ];

        // Universal properties: upstream sets these in applyStyle rather than in any
        // per-element applyAttributes, so routing them through element setters found
        // nothing and dropped them. `selectable` is read by the renderers; `glass`
        // applies to any element.
        foreach (['selectable', 'glass'] as $universal) {
            if (isset($parsed[$universal])) {
                $props[$universal] = (int) $parsed[$universal];
            }
        }

        foreach ($parsed as $key => $value) {
            // `dark` and `gradient` are nested companions, not properties. `dark` is
            // consumed by darkProps() above; flattening either here would put a
            // nested array on the wire as a style value.
            if ('dark' === $key || 'gradient' === $key) {
                continue;
            }

            // safe_area is a u8 edge mask, not a boolean: 1 both, 2 top, 3 bottom.
            // Sending `true` collapsed all three to the same thing at best, and the
            // top-only and bottom-only variants had no mapping at all — so content
            // sat under the notch or the home indicator.
            if (\in_array($key, ['safeArea', 'safeAreaTop', 'safeAreaBottom'], true)) {
                if ($value) {
                    $layout['safe_area'] = match ($key) {
                        'safeArea' => 1,
                        'safeAreaTop' => 2,
                        'safeAreaBottom' => 3,
                    };
                }

                continue;
            }

            if (isset(self::UNIVERSAL_PROPS[$key])) {
                continue;
            }

            if (isset(self::LAYOUT[$key])) {
                $layout[self::LAYOUT[$key]] = $this->cast(self::LAYOUT[$key], $value);

                continue;
            }

            if (isset(self::STYLE[$key])) {
                $style[self::STYLE[$key]] = $this->cast(self::STYLE[$key], $value);

                continue;
            }

            // `w-full` is a boolean in the parser's vocabulary and a value on the wire.
            if ('fillWidth' === $key && $value) {
                $layout['width'] = 'fill';

                continue;
            }

            if ('fillHeight' === $key && $value) {
                $layout['height'] = 'fill';

                continue;
            }

            if ('fill' === $key && $value) {
                $layout['width'] = 'fill';
                $layout['height'] = 'fill';

                continue;
            }

            if (isset(self::ELEMENT_PROPS[$key])) {
                $this->applyElementProp($element, self::ELEMENT_PROPS[$key], $value);

                continue;
            }
            // Anything else is a parser key with no wire home. Dropping it silently
            // matches upstream, whose dispatchers simply do not mention it.
        }

        // A border colour with no width cannot paint: the packed node carries a
        // single scalar `border_width`, so there is nothing for the colour to apply
        // to. `border-t border-gray-200` is the shape that produces it — the wire
        // has no per-side width, so the directional class contributes nothing and
        // only the colour survives. Upstream drops both; dropping the colour is the
        // same outcome with none of the per-frame noise.
        //
        // The reverse is deliberately NOT symmetric. A width with no colour is kept,
        // because Tailwind's `border` does mean a visible border, and because that is
        // upstream-patches/0008: `border-2 border-theme-primary` resolves to a width
        // of 2 and no colour when there is no theme resolver, and upstream dropping
        // the width there is the bug we already fixed.
        if (isset($style['border_color']) && !isset($style['border_width'])) {
            unset($style['border_color']);
        }

        $this->collapseEdges($parsed, $layout, 'padding');
        $this->collapseEdges($parsed, $layout, 'margin');
        $this->collapseInset($parsed, $layout);

        if ([] !== $layout) {
            $element->layout($layout);
        }

        if ([] !== $style) {
            $element->style($style);
        }

        if ([] !== $props) {
            $element->props($props);
        }
    }

    private function cast(string $wireKey, mixed $value): mixed
    {
        if (!\is_int($value) && !\is_float($value)) {
            return $value;
        }

        return match (self::CASTS[$wireKey] ?? null) {
            'float' => (float) $value,
            'int' => (int) $value,
            default => $value,
        };
    }

    /**
     * The four corner radii, as the float props the renderers actually read.
     *
     * They were emitted as `border_radius_top_left` and friends into `style` — names
     * that appear nowhere in upstream, PHP, Swift or Kotlin. The renderers read
     * `radius_tl/tr/br/bl` as props, keyed on `radius_tl` being present, so every
     * asymmetric rounding (`rounded-t-*`, chat bubbles, sheet tops) rendered square.
     *
     * All four are emitted whenever any is authored, with the uniform `borderRadius`
     * filling the rest, because the renderers treat the first as the whole switch.
     *
     * @param array<string, mixed> $parsed
     *
     * @return array<string, float>
     */
    private function cornerRadiusProps(array $parsed): array
    {
        $corners = [
            'radius_tl' => 'borderRadiusTopLeft',
            'radius_tr' => 'borderRadiusTopRight',
            'radius_br' => 'borderRadiusBottomRight',
            'radius_bl' => 'borderRadiusBottomLeft',
        ];

        if ([] === array_filter($corners, static fn (string $attr): bool => isset($parsed[$attr]))) {
            return [];
        }

        $uniform = isset($parsed['borderRadius']) ? (float) $parsed['borderRadius'] : 0.0;
        $props = [];

        foreach ($corners as $prop => $attr) {
            $props[$prop] = isset($parsed[$attr]) ? (float) $parsed[$attr] : $uniform;
        }

        return $props;
    }

    /**
     * The `dark:` variants, which were parsed correctly and then thrown away.
     *
     * The parser nests them under a `dark` key and nothing emitted them, so a
     * light-mode background and border painted in dark mode. `dark_bg_color`,
     * `dark_border_color` and `dark_opacity` are all read by both renderers;
     * `dark_color` and `dark_font_size` are emitted for parity with upstream even
     * though neither renderer reads them in this checkout.
     *
     * @param array<string, mixed> $parsed
     *
     * @return array<string, mixed>
     */
    private function darkProps(array $parsed): array
    {
        if (!\is_array($dark = $parsed['dark'] ?? null)) {
            return [];
        }

        $props = [];

        foreach ([
            'bg' => 'dark_bg_color',
            'borderColor' => 'dark_border_color',
            'opacity' => 'dark_opacity',
            'color' => 'dark_color',
            'fontSize' => 'dark_font_size',
        ] as $from => $to) {
            if (!isset($dark[$from])) {
                continue;
            }

            $props[$to] = match ($to) {
                'dark_opacity' => (float) $dark[$from],
                'dark_font_size' => (int) $dark[$from],
                default => $dark[$from],
            };
        }

        return $props;
    }

    /**
     * Directional keys collapse into one four-tuple.
     *
     * `px-2 py-3` parses to four separate keys, but the wire carries a single `padding`.
     * A uniform value from `p-4` seeds the tuple so a mixed `p-4 pt-8` keeps the sides it
     * did not override.
     *
     * @param array<string, mixed>  $parsed
     * @param array<string, mixed>  $layout
     */
    private function collapseEdges(array $parsed, array &$layout, string $property): void
    {
        $uniform = $parsed[$property] ?? null;
        $edges = [];
        $any = false;

        foreach (self::EDGES as $edge) {
            $value = $parsed[$property.$edge] ?? null;
            $any = $any || null !== $value;
            // Floats throughout, as upstream casts every edge and the uniform value.
            $edges[] = (float) ($value ?? (\is_array($uniform) ? 0 : $uniform ?? 0));
        }

        if ($any) {
            $layout[$property] = $edges;

            return;
        }

        if (null !== $uniform) {
            $layout[$property] = \is_array($uniform) ? array_map('floatval', $uniform) : (float) $uniform;
        }
    }

    /**
     * Inset keys collapse the same way, but into `position` — and an explicit zero matters
     * here, so absence and 0 must stay distinguishable.
     *
     * @param array<string, mixed> $parsed
     * @param array<string, mixed> $layout
     */
    private function collapseInset(array $parsed, array &$layout): void
    {
        $edges = [];
        $any = false;

        foreach (self::EDGES as $edge) {
            $value = $parsed['position'.$edge] ?? null;
            $any = $any || null !== $value;
            // Upstream casts every edge with `(float) ($attrs[...] ?? 0)`. A null in
            // a float slot is 0 at best, and shortens the tuple at worst — which
            // shifts the other three edges.
            $edges[] = (float) ($value ?? 0);
        }

        if ($any) {
            $layout['position'] = $edges;
        }
    }

    private function applyElementProp(Element $element, string $setter, mixed $value): void
    {
        // Only elements that declare the setter get the property. A font size on a column
        // is meaningless, and upstream's per-element applyAttributes() ignores it the same
        // way — so this is a skip, not an error.
        if (!method_exists($element, $setter)) {
            return;
        }

        try {
            $element->{$setter}($value);
        } catch (\TypeError) {
            // The parser's value type and the setter's signature disagree. Dropping is
            // right: the alternative is a fatal from a stylesheet, and the renderer would
            // have ignored a wrongly-typed value anyway.
        }
    }
}
