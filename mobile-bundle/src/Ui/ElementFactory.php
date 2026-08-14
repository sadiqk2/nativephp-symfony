<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Ui;

use Native\Symfony\Mobile\Ui\Style\StyleApplier;

/**
 * Builds elements from a type string.
 *
 * Needed because the authoring layer is data-driven: a Twig template names a type,
 * and 37 element types is too many to expose as 37 separate functions without the
 * template language becoming the bottleneck.
 *
 * Unknown types fail loudly with the list of what is available. The alternative —
 * silently emitting an unrecognised type — produces a screen with a missing region
 * and no error anywhere, which is the worst possible failure on a device.
 */
final class ElementFactory
{
    /** @var array<string, class-string<Element>> */
    private array $types;

    /**
     * @param array<string, class-string<Element>> $extra  Additional or overriding types
     * @param StyleApplier|null                    $styles Enables the `class` option.
     *        Optional so the factory stays usable without the style layer.
     */
    public function __construct(array $extra = [], private readonly ?StyleApplier $styles = null)
    {
        $this->types = [
            'activity_indicator' => Elements\ActivityIndicator::class,
            'bottom_bar' => Elements\BottomBar::class,
            'bottom_nav' => Elements\BottomNav::class,
            'bottom_nav_item' => Elements\BottomNavItem::class,
            'bottom_sheet' => Elements\BottomSheet::class,
            'button' => Elements\Button::class,
            'canvas' => Elements\Canvas::class,
            'circle' => Elements\Circle::class,
            'column' => Elements\Column::class,
            'divider' => Elements\Divider::class,
            'gesture_area' => Elements\GestureArea::class,
            'icon' => Elements\Icon::class,
            'image' => Elements\Image::class,
            'lazy_grid' => Elements\LazyGrid::class,
            'line' => Elements\Line::class,
            'native_root_stack' => Elements\NativeRootStack::class,
            'native_root_tabs' => Elements\NativeRootTabs::class,
            'pressable' => Elements\Pressable::class,
            'rect' => Elements\Rect::class,
            'refreshable' => Elements\Refreshable::class,
            'row' => Elements\Row::class,
            'scroll_view' => Elements\ScrollView::class,
            'search_item' => Elements\SearchItem::class,
            'side_nav' => Elements\SideNav::class,
            'side_nav_group' => Elements\SideNavGroup::class,
            'side_nav_header' => Elements\SideNavHeader::class,
            'side_nav_item' => Elements\SideNavItem::class,
            'spacer' => Elements\Spacer::class,
            'stack' => Elements\Stack::class,
            'tab_accessory' => Elements\TabAccessory::class,
            'text' => Elements\Text::class,
            'text_input' => Elements\TextInput::class,
            'toggle' => Elements\Toggle::class,
            'top_bar' => Elements\TopBar::class,
            'top_bar_action' => Elements\TopBarAction::class,
            'top_bar_title' => Elements\TopBarTitle::class,
            // Fab is not a wire type of its own: upstream emits `pressable` for it, so
            // it is reachable as a class but not by name. Aliasing it here would make
            // native('pressable') ambiguous for no gain.
            ...$extra,
        ];
    }

    /** @return list<string> */
    public function types(): array
    {
        return array_keys($this->types);
    }

    public function supports(string $type): bool
    {
        return isset($this->types[$type]);
    }

    /**
     * @param array<string, mixed> $options Props, plus the shared keys `key`, `ref`,
     *                                      `onPress`, `onLongPress`, `layout`, `style`
     * @param list<Element>        $children
     */
    public function create(string $type, array $options = [], array $children = []): Element
    {
        if (!isset($this->types[$type])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown native element type "%s". Available: %s.',
                $type,
                implode(', ', $this->types()),
            ));
        }

        $class = $this->types[$type];
        $element = $this->instantiate($class, $type, $options, $children);

        return $this->applyShared($element, $options);
    }

    /**
     * @param class-string<Element> $class
     * @param array<string, mixed>  $options
     * @param list<Element>         $children
     */
    private function instantiate(string $class, string $type, array $options, array $children): Element
    {
        // Elements whose primary value is a constructor argument rather than a prop
        // setter — matching upstream, where a button's label and a text's content are
        // props on the node but slot content in the template.
        $element = match ($type) {
            'text' => Elements\Text::make((string) ($options['text'] ?? '')),
            'button' => Elements\Button::make((string) ($options['label'] ?? '')),
            'image' => Elements\Image::make((string) ($options['source'] ?? '')),
            // Null, not a cast default: passing '' or false would emit a value prop the
            // caller never asked for.
            'text_input' => Elements\TextInput::make(isset($options['value']) ? (string) $options['value'] : null),
            'toggle' => Elements\Toggle::make(isset($options['value']) ? (bool) $options['value'] : null),
            'icon' => Elements\Icon::make((string) ($options['name'] ?? '')),
            default => $class::make(...$children),
        };

        // Container types already took their children through make(); anything else
        // needs them attached. Decided from make()'s signature rather than a
        // hand-kept list, so adding an element cannot silently miss this.
        if ([] !== $children && !$this->takesChildrenInMake($class)) {
            $element->child(...$children);
        }

        return $this->applyProps($element, $type, $options);
    }

    /** @param class-string<Element> $class */
    private function takesChildrenInMake(string $class): bool
    {
        static $cache = [];

        if (isset($cache[$class])) {
            return $cache[$class];
        }

        $parameters = (new \ReflectionMethod($class, 'make'))->getParameters();

        return $cache[$class] = [] !== $parameters && $parameters[0]->isVariadic();
    }

    /** @param array<string, mixed> $options */
    private function applyProps(Element $element, string $type, array $options): Element
    {
        foreach ($options as $name => $value) {
            // Shared keys are handled by applyShared; the constructor keys are
            // already consumed.
            if (\in_array($name, ['key', 'ref', 'onPress', 'onLongPress', 'layout', 'style', 'navigate', 'class'], true)) {
                continue;
            }

            // Consumed by the constructor above. `label` is the exception: it is a
            // constructor argument for a button and an ordinary prop on nav items,
            // so it is only skipped where make() already took it.
            if (\in_array($name, ['text', 'source', 'value'], true)
                || ('label' === $name && 'button' === $type)
                || ('name' === $name && 'icon' === $type)
            ) {
                continue;
            }

            if (!method_exists($element, $name)) {
                throw new \InvalidArgumentException(sprintf(
                    'Native element "%s" has no property "%s".',
                    $type,
                    $name,
                ));
            }

            $element->{$name}($value);
        }

        return $element;
    }

    /** @param array<string, mixed> $options */
    private function applyShared(Element $element, array $options): Element
    {
        if (isset($options['key'])) {
            $element->key((string) $options['key']);
        }

        if (isset($options['ref'])) {
            $element->ref((string) $options['ref']);
        }

        if (isset($options['onPress'])) {
            $element->onPress((string) $options['onPress']);
        }

        if (isset($options['onLongPress'])) {
            $element->onLongPress((string) $options['onLongPress']);
        }

        if (isset($options['navigate']) && \is_array($options['navigate'])) {
            $element->navigate($options['navigate']);
        }

        // Applied before the explicit layout/style options so a hand-written value wins
        // over a class, which is the precedence every CSS-adjacent system uses.
        if (isset($options['class']) && \is_string($options['class'])) {
            if (null === $this->styles) {
                throw new \LogicException(
                    'The `class` option needs a StyleApplier. The bundle wires one; a '.
                    'hand-built ElementFactory has to be given one.',
                );
            }

            $this->styles->applyClasses($element, $options['class']);
        }

        if (isset($options['layout']) && \is_array($options['layout'])) {
            $element->layout($options['layout']);
        }

        if (isset($options['style']) && \is_array($options['style'])) {
            $element->style($options['style']);
        }

        return $element;
    }
}
