<?php

declare(strict_types=1);

namespace Native\Symfony\Mobile\Ui\Routing;

/**
 * Declares a controller — or a single action on one — as a native screen.
 *
 * Upstream's equivalent is `Route::native('/items/{id}', ItemScreen::class)`, a router
 * macro that does two things at once: registers a plain GET route and records the URI
 * pattern in a manifest the device reads at boot (see {@see NativeRouteManifest}).
 *
 * A macro is the wrong shape for Symfony. Routes here are declared where the code lives,
 * not in a central file, and the same `#[Route]`-adjacent attribute style is what every
 * Symfony developer already reaches for. An attribute also keeps the declaration next to
 * the thing being declared, which matters for the manifest: the pattern PHP registers and
 * the pattern baked into the app have to agree, and the further the declaration drifts
 * from the code, the easier it is for them not to.
 *
 * The path uses **Laravel** placeholder syntax (`{id}`, `{id?}`) rather than Symfony's,
 * because it is not only consumed by PHP: the same string is copied verbatim into the
 * app bundle and matched on device by Kotlin's and Swift's `BootPlanner`. Inventing a
 * Symfony-flavoured syntax here would mean the two sides disagree about which paths boot
 * natively — a mistake that shows up as a WebView flash on a device and as nothing at all
 * in a test suite. {@see NativeScreenRouteLoader} translates to Symfony syntax at the one
 * point where Symfony's router needs it.
 *
 * Repeatable, like `#[Route]`: one screen may answer several paths.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class NativeScreen
{
    /**
     * @param string      $path   URI pattern in Laravel syntax, e.g. `/items/{id}` or `/items/{id?}`
     * @param string|null $layout Optional layout/chrome class, the equivalent of upstream's
     *                            `Route::native(...)->layout(...)`. Carried through the
     *                            registry untouched — this namespace has no opinion on what
     *                            a layout is, that belongs to the component lifecycle.
     * @param string|null $name   Symfony route name, when this screen is also exposed as an
     *                            HTTP route. Defaults to a name derived from the path.
     */
    public function __construct(
        public readonly string $path,
        public readonly ?string $layout = null,
        public readonly ?string $name = null,
    ) {
    }
}
