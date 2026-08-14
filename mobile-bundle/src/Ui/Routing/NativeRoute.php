<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Ui\Routing;

/**
 * One declared native screen: the URI pattern, the class that answers it, and the optional
 * chrome to wrap it in.
 *
 * Upstream stores `['class' => ..., 'layout' => ...]` keyed by pattern. The extra fields
 * here are Symfony's, not the protocol's: an action name (a controller may host several
 * screens) and a route name (so the same declaration can also feed Symfony's router).
 */
final class NativeRoute
{
    /**
     * @param string      $pattern URI pattern in Laravel syntax, canonicalised with one leading slash
     * @param class-string $screen  The controller/component class that renders the screen
     * @param string|null $action  Method on $screen, or null when the class itself is the screen
     * @param string|null $layout  Opaque layout identifier, for the component lifecycle to interpret
     * @param string|null $name    Symfony route name, when this screen is also an HTTP route
     */
    public function __construct(
        public readonly string $pattern,
        public readonly string $screen,
        public readonly ?string $action = null,
        public readonly ?string $layout = null,
        public readonly ?string $name = null,
    ) {
    }

    /**
     * Placeholder names in the pattern, optional ones included.
     *
     * @return list<string>
     */
    public function parameterNames(): array
    {
        return NativeRouteMatcher::parameterNames($this->pattern);
    }

    /**
     * A Symfony `_controller` value, or null when this screen is not callable over HTTP.
     *
     * A class-level `#[NativeScreen]` on a non-invokable class is the upstream shape — a
     * component with its own lifecycle, dispatched by the runloop rather than by the HTTP
     * kernel. Those have no controller, and {@see NativeScreenRouteLoader} skips them rather
     * than registering a route that would fatal on the first request.
     */
    public function controller(): ?string
    {
        if (null !== $this->action) {
            return $this->screen.'::'.$this->action;
        }

        return method_exists($this->screen, '__invoke') ? $this->screen : null;
    }

    public function describe(): string
    {
        return null === $this->action ? $this->screen : $this->screen.'::'.$this->action;
    }
}
