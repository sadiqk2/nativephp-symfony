<?php

declare(strict_types=1);

namespace Native\Symfony\Desktop\Contract;

/**
 * The app-startup contract, in full.
 *
 * The runtime never opens a window. At the end of its boot it POSTs
 * /_native/api/booted and the app decides what to do — so implementing this
 * interface is the entire "make my app appear" step.
 *
 * Must be idempotent: the runtime calls /booted again on macOS `activate` when
 * no windows are visible. WindowManager::open() already is, so the natural
 * implementation needs no extra care.
 *
 * Replaces Laravel's config('nativephp.provider'), which resolved a service
 * provider class out of the container purely to call two ad-hoc methods on it.
 */
interface AppBootstrapper
{
    public function boot(): void;
}
