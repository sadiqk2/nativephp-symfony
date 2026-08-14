<?php

declare(strict_types=1);

namespace App\Mobile;

use Native\Symfony\Mobile\Ui\Routing\NativeRoute;
use Native\Symfony\Mobile\Ui\Routing\NativeRouteRegistry;
use Native\Symfony\Mobile\Ui\Routing\NativeScreenAttributeLoader;

/**
 * The app's list of native screens — explicitly, not by scanning.
 *
 * Discovery has to be *deterministic*, because the same walk produces two artefacts
 * that must agree: the patterns baked into the app bundle at build time, and the
 * patterns the running PHP registers. A scan of "everything autoloadable" produces a
 * shorter list during a build than at runtime, and the difference does not fail — it
 * boots those screens into a WebView instead, which looks like a slow app rather than
 * a misconfigured one.
 *
 * So the app names its screens. One line per screen is a small price for the build
 * and the device agreeing.
 */
final class ScreenCatalog
{
    /** @var list<class-string> */
    public const SCREENS = [
        CounterScreen::class,
    ];

    private bool $loaded = false;

    public function __construct(
        private readonly NativeRouteRegistry $registry,
        private readonly NativeScreenAttributeLoader $loader,
    ) {
    }

    /** @return list<NativeRoute> */
    public function all(): array
    {
        if (!$this->loaded) {
            $this->loader->loadClasses(self::SCREENS);
            $this->loaded = true;
        }

        return $this->registry->all();
    }
}
