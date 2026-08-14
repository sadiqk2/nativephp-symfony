<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Ui;

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

    /** @param array<string, class-string<Element>> $extra Additional or overriding types */
    public function __construct(array $extra = [])
    {
        $this->types = [
            'column' => Elements\Column::class,
            'row' => Elements\Row::class,
            'stack' => Elements\Stack::class,
            'scroll_view' => Elements\ScrollView::class,
            'spacer' => Elements\Spacer::class,
            'divider' => Elements\Divider::class,
            'text' => Elements\Text::class,
            'button' => Elements\Button::class,
            'image' => Elements\Image::class,
            'text_input' => Elements\TextInput::class,
            'toggle' => Elements\Toggle::class,
            'pressable' => Elements\Pressable::class,
            'activity_indicator' => Elements\ActivityIndicator::class,
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
            'text_input' => Elements\TextInput::make((string) ($options['value'] ?? '')),
            'toggle' => Elements\Toggle::make((bool) ($options['value'] ?? false)),
            default => $class::make(...$children),
        };

        // Container types took their children through make(); the rest may still have
        // them, and a text element with children is a template bug worth surfacing.
        if ([] !== $children && !$element instanceof Elements\Column
            && !$element instanceof Elements\Row
            && !$element instanceof Elements\Stack
            && !$element instanceof Elements\ScrollView
            && !$element instanceof Elements\Spacer
            && !$element instanceof Elements\Pressable
        ) {
            $element->child(...$children);
        }

        return $this->applyProps($element, $type, $options);
    }

    /** @param array<string, mixed> $options */
    private function applyProps(Element $element, string $type, array $options): Element
    {
        foreach ($options as $name => $value) {
            // Shared keys are handled by applyShared; the constructor keys are
            // already consumed.
            if (\in_array($name, ['key', 'ref', 'onPress', 'onLongPress', 'layout', 'style', 'navigate'], true)) {
                continue;
            }

            if (\in_array($name, ['text', 'label', 'source', 'value'], true)) {
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

        if (isset($options['layout']) && \is_array($options['layout'])) {
            $element->layout($options['layout']);
        }

        if (isset($options['style']) && \is_array($options['style'])) {
            $element->style($options['style']);
        }

        return $element;
    }
}
