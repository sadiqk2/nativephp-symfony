<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Ui\Component;

use Native\Symfony\Mobile\Ui\CallbackRegistry;
use Native\Symfony\Mobile\Ui\Element;
use Native\Symfony\Mobile\Ui\Routing\NativeRouteMatch;
use Native\Symfony\Mobile\Ui\Routing\ScreenRendererInterface;
use Psr\Container\ContainerInterface;

/**
 * Joins routing to the component lifecycle.
 *
 * Both halves of the native-UI path existed and nothing connected them: routing resolved a
 * path to a screen class, components could render and handle interactions, and
 * `NativeScreenResponder` asked for a `ScreenRendererInterface` that nothing implemented.
 * An application had to write this itself — and the demo did, which is how the gap showed up.
 *
 * Two things it has to get right, both of which are silent when wrong:
 *
 * **A component instance must survive across frames.** State lives in ordinary properties,
 * so re-instantiating on every render would reset the screen on each interaction — a
 * counter that never counts, with nothing in any log. Instances are therefore cached per
 * pattern, and only discarded when the screen actually changes.
 *
 * **The registry passed in is the one to bind to.** `NativeScreenResponder` scopes a
 * registry per screen and reuses it across re-renders, because an incoming callback id was
 * minted by the previous frame and has to resolve against the registry that holds it.
 * Binding a component to a registry of its own would make every interaction miss.
 */
final class ComponentScreenRenderer implements ScreenRendererInterface
{
    /** @var array<string, NativeComponent> Keyed by route pattern */
    private array $mounted = [];

    /** @var array<string, CallbackRegistry> The registry each mounted component was bound to */
    private array $boundTo = [];

    /**
     * @param ContainerInterface|null $screens A service locator for screen classes that are
     *        registered as services, so a screen can take constructor dependencies. Screens
     *        absent from it are instantiated directly, which is the common case — a screen
     *        with no dependencies should not need registering.
     */
    public function __construct(private readonly ?ContainerInterface $screens = null)
    {
    }

    public function renderScreen(NativeRouteMatch $match, CallbackRegistry $callbacks): Element
    {
        $component = $this->componentFor($match, $callbacks);

        return $component->renderTree();
    }

    /**
     * Deliver an interaction to the component currently showing `$pattern`.
     *
     * Returns false when there is nothing mounted for it — routine rather than
     * exceptional, since a device can tap a frame from a screen that has since been
     * replaced.
     */
    public function dispatch(string $pattern, InteractionEvent $event): bool
    {
        $component = $this->mounted[$pattern] ?? null;

        if (null === $component) {
            return false;
        }

        $component->dispatch($event);

        return true;
    }

    /** The live component for a pattern, if one is mounted. */
    public function mounted(string $pattern): ?NativeComponent
    {
        return $this->mounted[$pattern] ?? null;
    }

    /**
     * Discard a screen's component, unmounting its tree.
     *
     * Called when navigating away. Without it a component and everything it holds stays
     * alive for the life of the process — which on a device is the life of the app.
     */
    public function forget(string $pattern): void
    {
        $component = $this->mounted[$pattern] ?? null;

        if (null !== $component) {
            $component->unmountTree();
        }

        unset($this->mounted[$pattern], $this->boundTo[$pattern]);
    }

    public function forgetAll(): void
    {
        foreach (array_keys($this->mounted) as $pattern) {
            $this->forget($pattern);
        }
    }

    private function componentFor(NativeRouteMatch $match, CallbackRegistry $callbacks): NativeComponent
    {
        $pattern = $match->route->pattern;
        $existing = $this->mounted[$pattern] ?? null;

        // Reuse only while the registry is the same one. The responder mints a fresh
        // registry when the screen changes, so a different registry here means this is a
        // new visit rather than a re-render, and the previous state should not leak into it.
        if (null !== $existing && ($this->boundTo[$pattern] ?? null) === $callbacks) {
            return $existing;
        }

        if (null !== $existing) {
            $this->forget($pattern);
        }

        $component = $this->instantiate($match);
        $component->bind($callbacks);

        $this->mounted[$pattern] = $component;
        $this->boundTo[$pattern] = $callbacks;

        return $component;
    }

    private function instantiate(NativeRouteMatch $match): NativeComponent
    {
        $class = $match->route->screen;

        if (null !== $this->screens && $this->screens->has($class)) {
            $component = $this->screens->get($class);

            if (!$component instanceof NativeComponent) {
                throw new \LogicException(sprintf(
                    'Screen service "%s" must be a %s.',
                    $class,
                    NativeComponent::class,
                ));
            }

            return $this->withParameters($component, $match);
        }

        if (!class_exists($class)) {
            throw new \LogicException(sprintf('Screen class "%s" does not exist.', $class));
        }

        if (!is_subclass_of($class, NativeComponent::class)) {
            throw new \LogicException(sprintf(
                'Screen "%s" must extend %s to be rendered by this renderer. A screen that is a '.
                'controller action instead needs its own %s.',
                $class,
                NativeComponent::class,
                ScreenRendererInterface::class,
            ));
        }

        // Constructed without arguments: a screen needing collaborators should be registered
        // as a service and reached through the locator above, rather than having them guessed.
        return $this->withParameters(new $class(), $match);
    }

    /**
     * Hand route parameters to the component, if it wants them.
     *
     * Optional rather than part of the base class: most screens have no parameters, and a
     * required hook would put an empty method on every one of them.
     */
    private function withParameters(NativeComponent $component, NativeRouteMatch $match): NativeComponent
    {
        if ([] !== $match->parameters && method_exists($component, 'withRouteParameters')) {
            $component->withRouteParameters($match->parameters);
        }

        return $component;
    }
}
