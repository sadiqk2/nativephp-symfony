<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Ui\Routing;

use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Exposes declared native screens as ordinary Symfony routes.
 *
 * This is the second half of what `Route::native()` does upstream: besides recording the
 * pattern in the manifest, it registers a plain GET route so the URI is reachable when no
 * device is attached — a shared app link opened in a browser, a crawler, an HTTP smoke test.
 * Without it a native screen's URI 404s outside the app, which is a worse default than
 * rendering something.
 *
 * Optional by design, and the reason routing does not depend on it: an application that
 * already declares `#[Route]` next to `#[NativeScreen]` needs nothing from this class, and
 * `symfony/routing` is not a hard requirement of this bundle. Register it as a route loader
 * and import with the `native_screens` type:
 *
 *     # config/routes.yaml
 *     native_screens:
 *         resource: .
 *         type: native_screens
 *
 * Pattern translation happens here and nowhere else. Laravel's `{id?}` has no Symfony
 * equivalent in the path itself — an optional placeholder is a placeholder plus a `null`
 * default — so the syntax is converted at this boundary while the manifest keeps the
 * original string, which is what the device matches against.
 */
final class NativeScreenRouteLoader extends Loader
{
    public const TYPE = 'native_screens';

    public function __construct(
        private readonly NativeRouteRegistry $registry,
        ?string $env = null,
    ) {
        parent::__construct($env);
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        $collection = new RouteCollection();

        foreach ($this->registry->all() as $pattern => $nativeRoute) {
            $controller = $nativeRoute->controller();

            // A class-level screen on a non-invokable class is a component, dispatched by the
            // runloop rather than by the HTTP kernel. Registering a route for it would produce
            // a route whose controller cannot be called at all.
            if (null === $controller) {
                continue;
            }

            $defaults = ['_controller' => $controller, '_native_screen' => $pattern];

            foreach (NativeRouteMatcher::parameterNames($pattern) as $name) {
                if (self::isOptional($pattern, $name)) {
                    $defaults[$name] = null;
                }
            }

            $collection->add(
                $nativeRoute->name ?? self::routeName($pattern),
                new Route(self::toSymfonyPath($pattern), $defaults, [], [], '', [], ['GET']),
            );
        }

        return $collection;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return self::TYPE === $type;
    }

    /**
     * `/items/{id?}` → `/items/{id}`; the optionality moves into the defaults.
     */
    public static function toSymfonyPath(string $pattern): string
    {
        return str_replace('?}', '}', NativeRouteMatcher::normalizePattern($pattern));
    }

    /**
     * A deterministic, collision-free route name for an un-named screen.
     *
     * Placeholders contribute their names, so `/items/{id}` and `/items/{slug}` do not
     * collapse onto one name — Symfony's collection is keyed by name and would silently keep
     * only the last.
     */
    public static function routeName(string $pattern): string
    {
        $slug = preg_replace('/[^a-z0-9]+/i', '_', trim($pattern, '/')) ?? '';
        $slug = trim(strtolower($slug), '_');

        return 'native_screen.'.('' === $slug ? 'root' : $slug);
    }

    private static function isOptional(string $pattern, string $name): bool
    {
        return str_contains($pattern, '{'.$name.'?}');
    }
}
