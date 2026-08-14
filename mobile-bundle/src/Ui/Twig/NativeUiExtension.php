<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Ui\Twig;

use Native\Symfony\Mobile\Ui\CallbackRegistry;
use Native\Symfony\Mobile\Ui\Element;
use Native\Symfony\Mobile\Ui\ElementFactory;
use Native\Symfony\Mobile\Ui\ElementPublisher;
use Native\Symfony\Mobile\Ui\Style\StyleApplier;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Authors native element trees from Twig.
 *
 * Upstream's Blade equivalent is a tag precompiler that rewrites `<native:*>` tags
 * into collector calls, specifically to *bypass* Blade's component lifecycle for
 * speed. Twig needs none of that: its functions are already just calls, so a
 * template composes elements directly and there is no compilation step to fight.
 *
 *     {{ native_publish(
 *          native('column', {layout: {gap: 8}}, [
 *            native('text', {text: 'Hello', fontSize: 24}),
 *            native('button', {label: 'Tap me', onPress: 'save'}),
 *          ])
 *        ) }}
 *
 * A generic `native()` covers every type rather than exposing one function per
 * element — 37 of those would put the burden on the template language instead of
 * the data.
 */
final class NativeUiExtension extends AbstractExtension
{
    public function __construct(
        private readonly ElementFactory $factory,
        private readonly ?ElementPublisher $publisher = null,
        private readonly ?StyleApplier $styles = null,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('native', $this->native(...)),
            new TwigFunction('native_publish', $this->publish(...)),
            new TwigFunction('native_types', $this->factory->types(...)),
        ];
    }

    /**
     * Publish a frame from a template.
     *
     * A screen template renders no markup: it builds a tree and hands it to the runtime.
     * Returning void rather than a string is what makes `{% do native_publish(...) %}`
     * the natural spelling — a function returning JSON would only be exercising Twig's
     * autoescaping.
     *
     * The registry is per-publish unless one is passed. That is right for a template used
     * as a whole screen and wrong for one re-rendered on an interaction, where the incoming
     * callback id has to resolve against the registry that minted it — so a lifecycle
     * passes its own.
     *
     * @return array<string, mixed> The published tree, so a test can assert on it
     */
    public function publish(Element $root, ?CallbackRegistry $callbacks = null): array
    {
        if (null === $this->publisher) {
            throw new \LogicException(
                'native_publish() needs an ElementPublisher. It is registered by the bundle; '.
                'a hand-built extension has to be given one.',
            );
        }

        return $this->publisher->publish($root, $callbacks ?? new CallbackRegistry());
    }

    /**
     * @param array<string, mixed>       $options
     * @param list<Element>|Element|null $children A single element, a list, or nothing
     */
    public function native(string $type, array $options = [], array|Element|null $children = null): Element
    {
        $childList = match (true) {
            null === $children => [],
            $children instanceof Element => [$children],
            default => array_values($children),
        };

        foreach ($childList as $index => $child) {
            if (!$child instanceof Element) {
                // A template that interpolates a string where an element belongs would
                // otherwise fail deep inside the tree walk with no hint of where.
                throw new \InvalidArgumentException(sprintf(
                    'Child %d of native element "%s" is a %s, not a native element. '.
                    'Wrap text in native(\'text\', {text: …}).',
                    $index,
                    $type,
                    get_debug_type($child),
                ));
            }
        }

        return $this->factory->create($type, $options, $childList);
    }
}
