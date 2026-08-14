<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Ui\Routing;

/**
 * The result of resolving a concrete path against the native-route registry: which screen,
 * and with which parameter values.
 *
 * `$path` is kept alongside the route because a screen frequently needs the URI it was
 * reached by — upstream passes it into the navigation stack so back() and hot-reload
 * restoration can replay it — and recomputing it from pattern + params is lossy (`/items//42`
 * does not round-trip).
 */
final class NativeRouteMatch
{
    /** @param array<string, string> $parameters */
    public function __construct(
        public readonly NativeRoute $route,
        public readonly string $path,
        public readonly array $parameters = [],
    ) {
    }

    public function parameter(string $name, ?string $default = null): ?string
    {
        return $this->parameters[$name] ?? $default;
    }
}
