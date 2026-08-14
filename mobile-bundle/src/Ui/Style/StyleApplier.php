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
        'safeArea' => 'safe_area',
        'overflow' => 'overflow',
    ];

    /** @var array<string, string> */
    private const STYLE = [
        // Not a rename to snake_case but a rename outright: the parser calls it `bg`
        // and the wire calls it `bg_color`.
        'bg' => 'bg_color',
        'borderRadius' => 'border_radius',
        'borderRadiusTopLeft' => 'border_radius_top_left',
        'borderRadiusTopRight' => 'border_radius_top_right',
        'borderRadiusBottomLeft' => 'border_radius_bottom_left',
        'borderRadiusBottomRight' => 'border_radius_bottom_right',
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

        foreach ($parsed as $key => $value) {
            // `dark` and `gradient` are nested companions, not properties. They are the
            // parser's own structure and belong to whatever consumes dark-mode output;
            // flattening them here would put a nested array on the wire as a style value.
            if ('dark' === $key || 'gradient' === $key) {
                continue;
            }

            if (isset(self::LAYOUT[$key])) {
                $layout[self::LAYOUT[$key]] = $value;

                continue;
            }

            if (isset(self::STYLE[$key])) {
                $style[self::STYLE[$key]] = $value;

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

        $this->collapseEdges($parsed, $layout, 'padding');
        $this->collapseEdges($parsed, $layout, 'margin');
        $this->collapseInset($parsed, $layout);

        if ([] !== $layout) {
            $element->layout($layout);
        }

        if ([] !== $style) {
            $element->style($style);
        }
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
            $edges[] = $value ?? (\is_array($uniform) ? 0 : $uniform ?? 0);
        }

        if ($any) {
            $layout[$property] = $edges;

            return;
        }

        if (null !== $uniform) {
            $layout[$property] = $uniform;
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
            $edges[] = $value;
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
