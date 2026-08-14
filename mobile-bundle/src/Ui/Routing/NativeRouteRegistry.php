<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Ui\Routing;

/**
 * The set of declared native screens.
 *
 * Two consumers with different needs, which is why this is a first-class object rather than
 * a lookup baked into the responder:
 *
 *  - the **device**, which only ever sees the URI patterns, exported through
 *    {@see NativeRouteManifest} and compared against the start path before PHP boots;
 *  - **PHP**, which resolves a concrete path to a screen and its parameters.
 *
 * Both must agree, so resolution goes through {@see NativeRouteMatcher} — the port of the
 * device's own matcher — rather than through Symfony's router or a regex of its own.
 *
 * Upstream keeps this state in `static` properties on `NativeRouter`, with a `clearRoutes()`
 * for tests. That is a Laravel-facade habit; an injectable instance is both testable and
 * safe under a persistent runtime, where static state outlives the request that filled it.
 */
final class NativeRouteRegistry
{
    /** @var array<string, NativeRoute> Keyed by canonical pattern, in declaration order */
    private array $routes = [];

    /**
     * @throws \LogicException when two different screens claim the same pattern
     */
    public function add(NativeRoute $route): void
    {
        $pattern = NativeRouteMatcher::normalizePattern($route->pattern);

        $existing = $this->routes[$pattern] ?? null;

        // Upstream's array-keyed registry silently lets the last registration win. Here that
        // would be a coin toss decided by attribute-discovery order, and the symptom is the
        // wrong screen booting — so refuse instead. Re-declaring the *same* screen is
        // tolerated: discovery may legitimately run twice (a warm cache plus a rebuild).
        if (null !== $existing && $existing->describe() !== $route->describe()) {
            throw new \LogicException(sprintf(
                'Native screen pattern "%s" is declared by both %s and %s. Patterns must be unique.',
                $pattern,
                $existing->describe(),
                $route->describe(),
            ));
        }

        $this->routes[$pattern] = $pattern === $route->pattern
            ? $route
            : new NativeRoute($pattern, $route->screen, $route->action, $route->layout, $route->name);
    }

    /**
     * @param class-string $screen
     */
    public function register(string $pattern, string $screen, ?string $action = null, ?string $layout = null, ?string $name = null): NativeRoute
    {
        $route = new NativeRoute(NativeRouteMatcher::normalizePattern($pattern), $screen, $action, $layout, $name);

        $this->add($route);

        return $route;
    }

    /** @return array<string, NativeRoute> */
    public function all(): array
    {
        return $this->routes;
    }

    /**
     * The URI patterns, in declaration order — exactly what crosses into the app bundle.
     *
     * @return list<string>
     */
    public function patterns(): array
    {
        return array_keys($this->routes);
    }

    public function count(): int
    {
        return \count($this->routes);
    }

    public function get(string $pattern): ?NativeRoute
    {
        return $this->routes[NativeRouteMatcher::normalizePattern($pattern)] ?? null;
    }

    /**
     * Resolve a concrete path (or start URL, query string and all) to a screen.
     *
     * Precedence follows upstream: an exact pattern hit wins over a placeholder match, so a
     * literal `/items/new` beats `/items/{id}` regardless of declaration order. Beyond that
     * it is first declared wins, which is why `add()` refuses duplicate patterns — the
     * tie-break for genuinely ambiguous patterns is order-dependent and not something to
     * lean on.
     */
    public function resolve(string $path): ?NativeRouteMatch
    {
        $path = NativeRouteMatcher::normalizeStartPath($path);

        $exact = $this->routes[NativeRouteMatcher::normalizePattern($path)] ?? null;

        if (null !== $exact) {
            return new NativeRouteMatch($exact, $path);
        }

        foreach ($this->routes as $pattern => $route) {
            if (NativeRouteMatcher::matches($pattern, $path)) {
                return new NativeRouteMatch($route, $path, NativeRouteMatcher::parameters($pattern, $path));
            }
        }

        return null;
    }

    /**
     * The boot decision, as the device computes it: does *any* pattern match?
     *
     * Equivalent to `resolve() !== null` today, and kept separate on purpose — this is the
     * question `BootPlanner.plan()` asks, and it must keep answering the same way even if
     * resolution later grows requirements the manifest cannot express.
     */
    public function isNativePath(string $path): bool
    {
        $path = NativeRouteMatcher::normalizeStartPath($path);

        foreach ($this->routes as $pattern => $_) {
            if (NativeRouteMatcher::matches($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    public function clear(): void
    {
        $this->routes = [];
    }
}
